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
        if (!is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0770, true);
        }
        $this->lock = fopen($this->path.'.lock', 'c');
        if (!flock($this->lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Embedding cache is in use; retry after the current importer/consumer finishes.');
        }
        $this->db = new \PDO('duckdb:'.$this->path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE IF NOT EXISTS profiles (id VARCHAR PRIMARY KEY, profile JSON NOT NULL);
            CREATE TABLE IF NOT EXISTS requests (id VARCHAR PRIMARY KEY, profile_id VARCHAR NOT NULL, input VARCHAR NOT NULL, status VARCHAR NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, error VARCHAR);
            CREATE TABLE IF NOT EXISTS documents (id VARCHAR PRIMARY KEY, reference VARCHAR NOT NULL, profile_id VARCHAR NOT NULL, title VARCHAR NOT NULL, text VARCHAR NOT NULL, chunking JSON NOT NULL);
            CREATE TABLE IF NOT EXISTS chunks (document_id VARCHAR NOT NULL, ordinal INTEGER NOT NULL, char_start INTEGER NOT NULL, char_end INTEGER NOT NULL, tokens INTEGER NOT NULL, request_id VARCHAR NOT NULL, PRIMARY KEY (document_id, ordinal));
            CREATE TABLE IF NOT EXISTS provider_calls (id VARCHAR PRIMARY KEY, profile_id VARCHAR NOT NULL, inputs INTEGER NOT NULL, started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, status VARCHAR NOT NULL)');

        return $this->db;
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
        $db->beginTransaction();
        try {
            foreach ($requests as $i => $request) {
                $this->store($profile)->add(new VectorDocument($request['id'], new Vector($vectors[$i]), new Metadata(['profileId' => $profile->id()])));
                $this->execute("UPDATE requests SET status = 'ready', error = NULL WHERE id = ?", [$request['id']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function callCount(): int
    {
        return (int) $this->execute('SELECT COUNT(*) FROM provider_calls')->fetchColumn();
    }
}
