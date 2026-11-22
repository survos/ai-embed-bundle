<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle;

use Survos\Ai\DuckDb\Store;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;

/** One cooperating process owns the cache connection at a time, including its consumer. */
final class DuckDbEmbeddingCache
{
    private ?\PDO $db = null;
    private mixed $lock = null;

    public function __construct(public readonly string $path)
    {
    }

    public function connection(): \PDO
    {
        if ($this->db) {
            return $this->db;
        }
        if (!class_exists(Store::class)) {
            throw new \LogicException('DuckDB embedding cache requires survos/ai-duckdb-store. Install it with composer require survos/ai-duckdb-store and enable ext-pdo_duckdb.');
        }
        if (!class_exists(\PDO::class) || !in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            throw new \LogicException('DuckDB embedding cache requires the native PDO DuckDB driver (ext-pdo_duckdb). Enable it in this PHP runtime before using the cache.');
        }
        $this->acquireLock();
        $this->db = new \PDO('duckdb:'.$this->path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE IF NOT EXISTS profiles (id VARCHAR PRIMARY KEY, profile JSON NOT NULL);
            CREATE TABLE IF NOT EXISTS requests (id VARCHAR PRIMARY KEY, profile_id VARCHAR NOT NULL, input VARCHAR NOT NULL, status VARCHAR NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, error VARCHAR);
            CREATE TABLE IF NOT EXISTS documents (id VARCHAR PRIMARY KEY, reference VARCHAR NOT NULL, profile_id VARCHAR NOT NULL, title VARCHAR NOT NULL, text VARCHAR NOT NULL, chunking JSON NOT NULL);
            CREATE TABLE IF NOT EXISTS chunks (document_id VARCHAR NOT NULL, ordinal INTEGER NOT NULL, char_start INTEGER NOT NULL, char_end INTEGER NOT NULL, tokens INTEGER NOT NULL, request_id VARCHAR NOT NULL, PRIMARY KEY (document_id, ordinal));
            CREATE TABLE IF NOT EXISTS provider_calls (id VARCHAR PRIMARY KEY, profile_id VARCHAR NOT NULL, inputs INTEGER NOT NULL, started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, status VARCHAR NOT NULL)');

        $this->db->exec("ALTER TABLE provider_calls ADD COLUMN IF NOT EXISTS operation VARCHAR DEFAULT 'embedding';
            CREATE TABLE IF NOT EXISTS embedding_batches (id VARCHAR PRIMARY KEY, profile_id VARCHAR NOT NULL, state VARCHAR NOT NULL, input_path VARCHAR NOT NULL, file_id VARCHAR, remote_id VARCHAR, result_path VARCHAR, remote JSON);
            CREATE TABLE IF NOT EXISTS embedding_batch_requests (batch_id VARCHAR NOT NULL, request_id VARCHAR NOT NULL, PRIMARY KEY (batch_id, request_id))");

        return $this->db;
    }

    private function acquireLock(): void
    {
        if (is_resource($this->lock)) {
            return;
        }
        if (!is_dir(dirname($this->path)) && !mkdir(dirname($this->path), 0770, true) && !is_dir(dirname($this->path))) {
            throw new \RuntimeException('Cannot create embedding directory.');
        }
        $lock = fopen($this->path.'.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new \RuntimeException('Embedding cache is in use; retry after the current importer/consumer finishes.');
        }
        $this->lock = $lock;
    }

    public function isOpen(): bool
    {
        return null !== $this->db;
    }

    /** Protect file installation without opening/creating the database itself. */
    public function withFileLock(callable $operation): mixed
    {
        $owned = !is_resource($this->lock);
        $this->acquireLock();
        try {
            return $operation();
        } finally {
            if ($owned && !$this->db) {
                fclose($this->lock);
                $this->lock = null;
            }
        }
    }

    /** Caller must release any retained PDO/store references before closing. */
    public function close(): void
    {
        if ($this->db?->inTransaction()) {
            throw new \LogicException('Cannot close a cache inside a transaction.');
        }
        $this->db?->exec('CHECKPOINT');
        $this->db = null;
        if (is_resource($this->lock)) {
            fclose($this->lock);
        }
        $this->lock = null;
    }

    public function execute(string $sql, array $args = []): \PDOStatement
    {
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($args);

        return $stmt;
    }

    public function register(EmbeddingProfile $profile): void
    {
        $this->execute('INSERT INTO profiles VALUES (?, CAST(? AS JSON)) ON CONFLICT DO NOTHING', [$profile->id(), json_encode($profile, JSON_THROW_ON_ERROR)]);
        $this->store($profile)->setup();
    }

    public function store(EmbeddingProfile $profile): Store
    {
        return new Store($this->connection(), 'vectors_'.$profile->id(), $profile->dimensions);
    }

    public function ensure(string $input, EmbeddingProfile $profile): array
    {
        $this->register($profile);
        $id = hash('sha256', $profile->id()."\0".$input);
        $existing = $this->execute('SELECT status FROM requests WHERE id = ?', [$id])->fetchColumn();
        $this->execute("INSERT INTO requests (id, profile_id, input, status) VALUES (?, ?, ?, 'pending') ON CONFLICT DO NOTHING", [$id, $profile->id(), $input]);

        return ['id' => $id, 'status' => $existing ?: 'pending', 'cacheHit' => 'ready' === $existing, 'vector' => 'ready' === $existing ? $this->vector($id, $profile) : null];
    }

    public function vector(string $id, EmbeddingProfile $profile): ?array
    {
        $json = $this->execute('SELECT CAST(to_json(embedding) AS VARCHAR) FROM "vectors_'.$profile->id().'" WHERE id = ?', [$id])->fetchColumn();

        return false === $json ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function complete(array $requests, array $vectors, EmbeddingProfile $profile): void
    {
        if (count($requests) !== count($vectors)) {
            throw new \UnexpectedValueException('Embedding count mismatch.');
        }
        foreach ($vectors as $vector) {
            if (!is_array($vector) || !array_is_list($vector) || count($vector) !== $profile->dimensions || !array_all($vector, fn ($v) => (is_float($v) || is_int($v)) && is_finite((float) $v)) || array_sum(array_map(fn ($v) => $v * $v, $vector)) <= 0) {
                throw new \UnexpectedValueException('Expected finite, nonzero vectors of the configured dimensions.');
            }
        }
        $db = $this->connection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        try {
            foreach ($requests as $i => $request) {
                $this->store($profile)->add(new VectorDocument($request['id'], new Vector($vectors[$i]), new Metadata(['profileId' => $profile->id()])));
                $this->execute("UPDATE requests SET status = 'ready', error = NULL WHERE id = ?", [$request['id']]);
            }
            if ($ownsTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function callCount(): int
    {
        return (int) $this->execute('SELECT COUNT(*) FROM provider_calls')->fetchColumn();
    }
}
