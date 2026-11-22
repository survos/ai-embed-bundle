<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle;

/** Portable completed results, never another machine's queue or batch ownership. */
final class EmbeddingSnapshot
{
    public const VERSION = 1;

    public function create(DuckDbEmbeddingCache $source, string $path): array
    {
        if (file_exists($path)) {
            throw new \InvalidArgumentException('Snapshot destination already exists.');
        }
        $target = new DuckDbEmbeddingCache($path);
        try {
            $result = $this->transfer($source->connection(), $target, true);
            $target->execute('CREATE TABLE embedding_snapshot (version INTEGER NOT NULL)');
            $target->execute('INSERT INTO embedding_snapshot VALUES (?)', [self::VERSION]);
            $target->close();

            return $result;
        } catch (\Throwable $e) {
            $target->close();
            @unlink($path);
            throw $e;
        } finally {
            @unlink($path.'.lock');
        }
    }

    public function validate(string $path): array
    {
        return $this->transfer($this->open($path), null, false);
    }

    public function merge(string $path, DuckDbEmbeddingCache $target): array
    {
        return $this->transfer($this->open($path), $target, false);
    }

    private function open(string $path): \PDO
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Snapshot does not exist.');
        }
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            throw new \LogicException('Snapshots require ext-pdo_duckdb.');
        }
        $db = new \PDO('duckdb:'.$path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only', 'enable_external_access' => 'false']]);
        foreach (['embedding_snapshot', 'profiles', 'requests', 'documents', 'chunks', 'provider_calls', 'embedding_batches', 'embedding_batch_requests'] as $table) {
            $stmt = $db->prepare("SELECT count(*) FROM information_schema.tables WHERE table_schema='main' AND table_name=? AND table_type='BASE TABLE'");
            $stmt->execute([$table]);
            if (1 !== (int) $stmt->fetchColumn()) {
                throw new \UnexpectedValueException('Snapshot is missing base table '.$table);
            }
        }
        if ($db->query('SELECT version FROM embedding_snapshot')->fetchAll(\PDO::FETCH_COLUMN) !== [self::VERSION]) {
            throw new \UnexpectedValueException('Unsupported embedding snapshot version.');
        }
        foreach (["requests WHERE status <> 'ready'", 'provider_calls', 'embedding_batches', 'embedding_batch_requests'] as $table) {
            if (0 !== (int) $db->query('SELECT count(*) FROM '.$table)->fetchColumn()) {
                throw new \UnexpectedValueException('Published snapshots cannot contain pending work or provider job ownership.');
            }
        }

        return $db;
    }

    /** Transfer is transactional; a conflict never overwrites an existing result. */
    private function transfer(\PDO $source, ?DuckDbEmbeddingCache $target, bool $publishing): array
    {
        $counts = ['profiles' => [], 'vectors' => 0, 'added' => 0, 'reused' => 0, 'documents' => 0];
        $profiles = [];
        $db = $target?->connection();
        $db?->beginTransaction();
        try {
            foreach ($source->query('SELECT id, CAST(profile AS VARCHAR) AS profile FROM profiles ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $profile = new EmbeddingProfile(...json_decode($row['profile'], true, flags: JSON_THROW_ON_ERROR));
                if ($profile->id() !== $row['id']) {
                    throw new \UnexpectedValueException('Profile hash mismatch.');
                }
                $profiles[$row['id']] = $profile;
                $counts['profiles'][] = $row['id'];
                $target?->register($profile);
            }
            // Hash-derived table identifiers are safe. Iterate vectors, never hold the corpus in memory.
            foreach ($profiles as $id => $profile) {
                $stmt = $source->prepare("SELECT r.id, r.input, CAST(to_json(v.embedding) AS VARCHAR) AS vector FROM requests r LEFT JOIN vectors_$id v ON r.id=v.id WHERE r.profile_id=? AND r.status='ready' ORDER BY r.id");
                $stmt->execute([$id]);
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    if ($row['id'] !== hash('sha256', $id."\0".$row['input'])) {
                        throw new \UnexpectedValueException('Request input hash mismatch.');
                    }
                    $vector = json_decode($row['vector'] ?? 'null', true, flags: JSON_THROW_ON_ERROR);
                    if (!is_array($vector) || !array_is_list($vector) || count($vector) !== $profile->dimensions
                        || !array_all($vector, fn ($v) => (is_float($v) || is_int($v)) && is_finite((float) $v))
                        || array_sum(array_map(fn ($v) => $v * $v, $vector)) <= 0) {
                        throw new \UnexpectedValueException('Invalid snapshot vector.');
                    }
                    ++$counts['vectors'];
                    if (!$target) {
                        continue;
                    }
                    $local = $target->ensure($row['input'], $profile);
                    if ('ready' === $local['status']) {
                        if (array_map(floatval(...), $local['vector'] ?? []) !== array_map(floatval(...), $vector)) {
                            throw new \UnexpectedValueException('Conflicting vector for the same input/profile: '.$row['id']);
                        }
                        ++$counts['reused'];
                    } else {
                        if ($target->execute("SELECT count(*) FROM embedding_batch_requests r JOIN embedding_batches b ON b.id=r.batch_id WHERE r.request_id=? AND b.state <> 'reconciled'", [$row['id']])->fetchColumn()) {
                            throw new \LogicException('Finish the locally owned batch before merging request '.$row['id']);
                        }
                        $target->complete([$local], [$vector], $profile);
                        ++$counts['added'];
                    }
                }
            }
            $readyCount = (int) $source->query("SELECT count(*) FROM requests WHERE status='ready'")->fetchColumn();
            if ($readyCount !== $counts['vectors']) {
                throw new \UnexpectedValueException('Snapshot requests reference an unknown profile.');
            }
            $docs = $source->query('SELECT id, reference, profile_id, title, text, CAST(chunking AS VARCHAR) AS chunking FROM documents ORDER BY id');
            while ($doc = $docs->fetch(\PDO::FETCH_ASSOC)) {
                $chunks = $source->prepare('SELECT c.*, r.status, r.input, r.profile_id FROM chunks c LEFT JOIN requests r ON r.id=c.request_id WHERE c.document_id=? ORDER BY c.ordinal');
                $chunks->execute([$doc['id']]);
                $rows = $chunks->fetchAll(\PDO::FETCH_ASSOC);
                if ($publishing && array_any($rows, fn ($r) => 'ready' !== $r['status'])) {
                    continue;
                }
                $profile = $profiles[$doc['profile_id']] ?? throw new \UnexpectedValueException('Unknown document profile.');
                $chunking = json_decode($doc['chunking'], true, flags: JSON_THROW_ON_ERROR);
                $expected = hash('sha256', json_encode([$doc['reference'], $doc['title'], $doc['text'], $profile->id(), $chunking], JSON_THROW_ON_ERROR));
                if ($doc['id'] !== $expected || !$rows) {
                    throw new \UnexpectedValueException('Invalid document identity or missing chunks.');
                }
                $end = 0;
                foreach ($rows as $i => $chunk) {
                    if ('ready' !== $chunk['status'] || $chunk['profile_id'] !== $doc['profile_id'] || (int) $chunk['ordinal'] !== $i
                        || (int) $chunk['char_start'] !== $end || $chunk['char_end'] <= $end || $chunk['tokens'] < 1
                        || $chunk['input'] !== $profile->input($doc['title']."\n\n".mb_substr($doc['text'], $chunk['char_start'], $chunk['char_end'] - $chunk['char_start'], 'UTF-8'), 'document')) {
                        throw new \UnexpectedValueException('Invalid document chunk mapping.');
                    }
                    $end = (int) $chunk['char_end'];
                }
                if ($end !== mb_strlen($doc['text'], 'UTF-8')) {
                    throw new \UnexpectedValueException('Incomplete document coverage.');
                }
                ++$counts['documents'];
                if ($target) {
                    $existing = $target->execute('SELECT id, reference, profile_id, title, text, CAST(chunking AS VARCHAR) AS chunking FROM documents WHERE id=?', [$doc['id']])->fetch(\PDO::FETCH_ASSOC);
                    if ($existing && $existing !== $doc) {
                        throw new \UnexpectedValueException('Conflicting document metadata.');
                    }
                    if (!$existing) {
                        $target->execute('INSERT INTO documents VALUES (?, ?, ?, ?, ?, CAST(? AS JSON))', array_values($doc));
                    }
                    foreach ($rows as $chunk) {
                        $values = array_intersect_key($chunk, array_flip(['document_id', 'ordinal', 'char_start', 'char_end', 'tokens', 'request_id']));
                        $old = $target->execute('SELECT * FROM chunks WHERE document_id=? AND ordinal=?', [$doc['id'], $chunk['ordinal']])->fetch(\PDO::FETCH_ASSOC);
                        if ($old && $old !== $values) {
                            throw new \UnexpectedValueException('Conflicting chunk metadata.');
                        }
                        if (!$old) {
                            $target->execute('INSERT INTO chunks VALUES (?, ?, ?, ?, ?, ?)', array_values($values));
                        }
                    }
                    if (count($rows) !== (int) $target->execute('SELECT count(*) FROM chunks WHERE document_id=?', [$doc['id']])->fetchColumn()) {
                        throw new \UnexpectedValueException('Conflicting chunk count.');
                    }
                }
            }
            if (!$publishing && (int) $source->query('SELECT count(*) FROM chunks c LEFT JOIN documents d ON c.document_id=d.id WHERE d.id IS NULL')->fetchColumn()) {
                throw new \UnexpectedValueException('Orphan snapshot chunks.');
            }
            $db?->commit();

            return $counts;
        } catch (\Throwable $e) {
            if ($db?->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
