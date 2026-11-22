<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle;

use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tacman\AiBatch\Model\BatchJob;
use Tacman\AiBatch\Model\BatchRequest;
use Tacman\AiBatch\Model\BatchResult;
use Tacman\AiBatch\Service\OpenAiBatchClient;

/** Durable embedding-specific orchestration over ai-batch-bundle's existing HTTP client. */
final readonly class OpenAiEmbeddingBatchService
{
    public function __construct(private DuckDbEmbeddingCache $cache, private EmbeddingProfile $profile, private OpenAiBatchClient $client)
    {
    }

    private function guard(): void
    {
        if ('openai' !== $this->profile->provider || !in_array($this->profile->model, ['text-embedding-3-small', 'text-embedding-3-large'], true)
            || '' !== $this->profile->documentPrefix || '' !== $this->profile->queryPrefix) {
            throw new \LogicException('Select an OpenAI embedding profile with empty task prefixes.');
        }
        $this->cache->register($this->profile);
    }

    private function job(string $id): array
    {
        $row = $this->cache->execute('SELECT * FROM embedding_batches WHERE id = ? AND profile_id = ?', [$id, $this->profile->id()])->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \InvalidArgumentException('Unknown batch for this profile.');
        }

        return $row;
    }

    public function submit(int $limit = 1000, bool $retryFailed = false): array
    {
        $this->guard();
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Batch limit must be 1–10000.');
        }
        $states = $retryFailed ? "('pending', 'failed')" : "('pending')";
        $rows = $this->cache->execute("SELECT id, input FROM requests WHERE profile_id = ? AND status IN $states ORDER BY id LIMIT ".$limit, [$this->profile->id()])->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) {
            return ['submitted' => 0, 'apiCalls' => 0];
        }
        $id = bin2hex(random_bytes(16));
        $dir = $this->cache->path.'.batches/'.$id;
        mkdir($dir, 0770, true);
        $path = $dir.'/input.jsonl';
        $writer = JsonlWriter::open($path, 'w');
        try {
            foreach ($rows as $row) {
                $writer->write(BatchRequest::raw($row['id'], '/v1/embeddings', ['model' => $this->profile->model, 'input' => $row['input'], 'dimensions' => $this->profile->dimensions, 'encoding_format' => 'float'])->toOpenAiLine());
            }
            $writer->finish();
        } finally {
            $writer->close();
        }
        if (filesize($path) > 190_000_000) {
            throw new \RuntimeException('Input exceeds the batch file budget.');
        }
        $db = $this->cache->connection();
        $db->beginTransaction();
        try {
            $this->cache->execute("INSERT INTO embedding_batches (id, profile_id, state, input_path) VALUES (?, ?, 'prepared', ?)", [$id, $this->profile->id(), $path]);
            foreach ($rows as $row) {
                $this->cache->execute('INSERT INTO embedding_batch_requests VALUES (?, ?)', [$id, $row['id']]);
                $this->cache->execute("UPDATE requests SET status = 'submitted', attempts = attempts + 1 WHERE id = ?", [$row['id']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $this->resume($id);
    }

    public function resume(string $id): array
    {
        $this->guard();
        $row = $this->job($id);
        if ($row['remote_id']) {
            return ['localId' => $id, 'remoteId' => $row['remote_id'], 'status' => $row['state'], 'submitted' => 0, 'apiCalls' => 0];
        }
        if ('submitting' === $row['state']) {
            throw new \RuntimeException('Submission outcome uncertain. Recover the remote batch ID and use ai:embed:batch:attach; do not resubmit.');
        }
        $before = $this->cache->callCount();
        if (!$row['file_id']) {
            $fileId = $this->client->uploadInputFile(file_get_contents($row['input_path']));
            $this->cache->execute("UPDATE embedding_batches SET file_id = ?, state = 'uploaded' WHERE id = ?", [$fileId, $id]);
            $row['file_id'] = $fileId;
        }
        // Persist uncertainty before the remote side effect: a timeout must never silently create another paid job.
        $this->cache->execute("UPDATE embedding_batches SET state = 'submitting' WHERE id = ?", [$id]);
        $job = $this->client->submitFromFileId($row['file_id'], ['endpoint' => '/v1/embeddings', 'metadata' => ['embeddingBatchId' => $id, 'embeddingProfileId' => $this->profile->id()]]);
        $this->saveRemote($id, $job);

        return ['localId' => $id, 'remoteId' => $job->id, 'status' => $job->status, 'submitted' => (int) $this->cache->execute('SELECT COUNT(*) FROM embedding_batch_requests WHERE batch_id = ?', [$id])->fetchColumn(), 'apiCalls' => $this->cache->callCount() - $before];
    }

    private function saveRemote(string $id, BatchJob $job): void
    {
        $this->cache->execute('UPDATE embedding_batches SET remote_id = ?, state = ?, remote = CAST(? AS JSON) WHERE id = ?', [$job->id, $job->status, json_encode($job->raw, JSON_THROW_ON_ERROR), $id]);
    }

    public function attach(string $id, string $remoteId): void
    {
        $this->guard();
        $row = $this->job($id);
        if ($row['remote_id'] || 'submitting' !== $row['state']) {
            throw new \LogicException('Only an uncertain submission can be attached.');
        }
        $job = $this->client->checkBatch($remoteId);
        if ($job->inputFileId !== $row['file_id'] || ($job->raw['endpoint'] ?? null) !== '/v1/embeddings'
            || ($job->raw['metadata']['embeddingBatchId'] ?? null) !== $id || ($job->raw['metadata']['embeddingProfileId'] ?? null) !== $this->profile->id()) {
            throw new \RuntimeException('Remote batch does not match the persisted submission.');
        }
        $this->saveRemote($id, $job);
    }

    public function collect(): array
    {
        $this->guard();
        $before = $this->cache->callCount();
        $completed = 0;
        $failed = 0;
        $waiting = 0;
        $jobs = $this->cache->execute("SELECT * FROM embedding_batches WHERE profile_id = ? AND state <> 'reconciled' ORDER BY id", [$this->profile->id()])->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($jobs as $row) {
            if (!$row['remote_id']) {
                ++$waiting;
                continue;
            }
            if (!$row['result_path']) {
                $job = $this->client->checkBatch($row['remote_id']);
                $this->saveRemote($row['id'], $job);
                if (!$job->isTerminal()) {
                    ++$waiting;
                    continue;
                }
                $path = dirname($row['input_path']).'/results.jsonl.gz';
                $temporary = dirname($path).'/results-'.bin2hex(random_bytes(8)).'.jsonl.gz';
                $writer = JsonlWriter::open($temporary, 'w');
                try {
                    foreach ($this->client->fetchResults($job) as $result) {
                        $writer->write($result->raw);
                    } $writer->finish();
                } finally {
                    $writer->close();
                }
                rename($temporary, $path);
                $this->cache->execute('UPDATE embedding_batches SET result_path = ? WHERE id = ?', [$path, $row['id']]);
                $row['result_path'] = $path;
            }
            $counts = $this->replay($row['id']);
            $completed += $counts['completed'];
            $failed += $counts['failed'];
        }

        return ['completed' => $completed, 'failed' => $failed, 'waiting' => $waiting, 'apiCalls' => $this->cache->callCount() - $before];
    }

    public function replay(string $id): array
    {
        $this->guard();
        $job = $this->job($id);
        if ('reconciled' === $job['state']) {
            return ['completed' => 0, 'failed' => 0];
        }
        if (!$job['result_path']) {
            throw new \LogicException('Collect terminal results first.');
        }
        $expected = array_fill_keys($this->cache->execute('SELECT request_id FROM embedding_batch_requests WHERE batch_id = ?', [$id])->fetchAll(\PDO::FETCH_COLUMN), true);
        $seen = [];
        $ready = [];
        $vectors = [];
        $errors = [];
        foreach (JsonlReader::open($job['result_path']) as $line) {
            $result = BatchResult::fromOpenAiLine($line);
            $requestId = $result->customId;
            if (!isset($expected[$requestId]) || isset($seen[$requestId])) {
                throw new \UnexpectedValueException('Unknown or duplicate batch custom_id.');
            }
            $seen[$requestId] = true;
            if (!$result->success) {
                $errors[$requestId] = $result->errorCode ?? 'provider_error';
                continue;
            }
            $body = $result->body;
            if (($line['response']['status_code'] ?? null) !== 200 || ($body['model'] ?? null) !== $this->profile->model || 1 !== count($body['data'] ?? []) || ($body['data'][0]['index'] ?? null) !== 0) {
                throw new \UnexpectedValueException('Invalid embedding result model, status or input index.');
            }
            $ready[] = ['id' => $requestId];
            $vectors[] = $body['data'][0]['embedding'] ?? [];
        }
        foreach (array_diff_key($expected, $seen) as $requestId => $_) {
            $errors[$requestId] = 'missing_result';
        }
        // Shared validation/write path: a malformed vector rejects the entire successful set.
        $db = $this->cache->connection();
        $db->beginTransaction();
        try {
            $this->cache->complete($ready, $vectors, $this->profile);
            foreach ($errors as $requestId => $error) {
                $this->cache->execute("UPDATE requests SET status = 'failed', error = ? WHERE id = ? AND status <> 'ready'", [$error, $requestId]);
            }
            $this->cache->execute("UPDATE embedding_batches SET state = 'reconciled' WHERE id = ?", [$id]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return ['completed' => count($ready), 'failed' => count($errors)];
    }

    #[AsCommand('ai:embed:batch:submit', 'Submit pending OpenAI embeddings through the Batch API.')]
    public function submitCommand(SymfonyStyle $io, #[Option] int $limit = 1000, #[Option] bool $retryFailed = false): int
    {
        $io->writeln(json_encode($this->submit($limit, $retryFailed), JSON_THROW_ON_ERROR));

        return 0;
    }

    #[AsCommand('ai:embed:batch:collect', 'Poll submitted batches and reconcile terminal results by cache request ID.')]
    public function collectCommand(SymfonyStyle $io): int
    {
        $io->writeln(json_encode($this->collect(), JSON_THROW_ON_ERROR));

        return 0;
    }

    #[AsCommand('ai:embed:batch:resume', 'Resume a prepared/uploaded batch; never repeat an uncertain submission.')]
    public function resumeCommand(SymfonyStyle $io, #[Argument] string $id): int
    {
        $io->writeln(json_encode($this->resume($id), JSON_THROW_ON_ERROR));

        return 0;
    }

    #[AsCommand('ai:embed:batch:attach', 'Recover a known remote ID after an uncertain batch submission.')]
    public function attachCommand(SymfonyStyle $io, #[Argument] string $id, #[Argument] string $remoteId): int
    {
        $this->attach($id, $remoteId);
        $io->success('Remote batch recovered.');

        return 0;
    }
}
