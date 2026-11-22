<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle;

use Survos\AiEmbedBundle\Chunking\TokenChunker;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class EmbeddingService
{
    public function __construct(public DuckDbEmbeddingCache $cache, public EmbeddingProfile $profile,
        private EmbeddingProviderInterface $provider, private TokenChunker $chunker = new TokenChunker())
    {
    }

    public function forProfile(EmbeddingProfile $profile): self
    {
        return new self($this->cache, $profile, $this->provider, $this->chunker);
    }

    public function ensureEmbedding(string $text, string $purpose = 'document'): array
    {
        return $this->cache->ensure($this->profile->input($text, $purpose), $this->profile);
    }

    public function ensureDocumentEmbedding(string $reference, string $title, string $text, int $maxTokens = 1800): array
    {
        if ('' === trim($reference) || '' === trim($text)) {
            throw new \InvalidArgumentException('Document reference and text must not be empty.');
        }
        $chunking = $this->chunker->profile($this->profile->model, $maxTokens);
        if ($chunking['tokenizer']['documentPrefix'] !== $this->profile->documentPrefix) {
            throw new \InvalidArgumentException('Document prefix must match the model-aware tokenizer profile.');
        }
        $id = hash('sha256', json_encode([$reference, $title, $text, $this->profile->id(), $chunking], JSON_THROW_ON_ERROR));
        $this->cache->register($this->profile);
        // Reimport takes the fast path: no tokenization and no provider access.
        if (!$this->cache->execute('SELECT id FROM documents WHERE id = ?', [$id])->fetchColumn()) {
            $chunks = $this->chunker->chunks($title, $text, $this->profile->model, $maxTokens);
            $db = $this->cache->connection();
            $db->beginTransaction();
            try {
                $this->cache->execute('INSERT INTO documents VALUES (?, ?, ?, ?, ?, CAST(? AS JSON))', [$id, $reference, $this->profile->id(), $title, $text, json_encode($chunking, JSON_THROW_ON_ERROR)]);
                foreach ($chunks as $i => $chunk) {
                    $request = $this->ensureEmbedding($chunk['text']);
                    $this->cache->execute('INSERT INTO chunks VALUES (?, ?, ?, ?, ?, ?)', [$id, $i, $chunk['charStart'], $chunk['charEnd'], $chunk['tokenCount'], $request['id']]);
                }
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        }
        $chunks = $this->documentChunks($id);
        $ready = count(array_filter($chunks, fn ($c) => 'ready' === $c['status']));

        return ['documentId' => $id, 'status' => $ready === count($chunks) ? 'ready' : 'pending', 'chunks' => count($chunks), 'cacheHits' => $ready, 'pending' => count($chunks) - $ready];
    }

    public function documentChunks(string $id): array
    {
        return $this->cache->execute('SELECT c.*, r.status FROM chunks c JOIN requests r ON c.request_id = r.id WHERE c.document_id = ? ORDER BY c.ordinal', [$id])->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** One bounded drain. Failed requests are retried on the next invocation, never in a tight loop. */
    public function consume(int $batchSize = 8, int $limit = 1000): array
    {
        if ($batchSize < 1 || $batchSize > 64 || $limit < 1) {
            throw new \InvalidArgumentException('Expected batch size 1–64 and positive limit.');
        }
        $this->cache->register($this->profile);
        $requests = $this->cache->execute("SELECT id, input FROM requests WHERE profile_id = ? AND status IN ('pending', 'failed') ORDER BY id LIMIT ".$limit, [$this->profile->id()])->fetchAll(\PDO::FETCH_ASSOC);
        if ($requests && 'openai' === $this->profile->provider) {
            throw new \LogicException('Use ai:embed:batch:submit and ai:embed:batch:collect for OpenAI.');
        }
        $calls = 0;
        $completed = 0;
        foreach (array_chunk($requests, $batchSize) as $batch) {
            $callId = bin2hex(random_bytes(16));
            // Persist attempted calls before network access, including failures/crashes.
            $this->cache->execute("INSERT INTO provider_calls (id, profile_id, inputs, status) VALUES (?, ?, ?, 'started')", [$callId, $this->profile->id(), count($batch)]);
            foreach ($batch as $request) {
                $this->cache->execute('UPDATE requests SET attempts = attempts + 1 WHERE id = ?', [$request['id']]);
            }
            ++$calls;
            try {
                $vectors = $this->provider->embed(array_column($batch, 'input'), $this->profile);
                $this->cache->complete($batch, $vectors, $this->profile);
                $this->cache->execute("UPDATE provider_calls SET status = 'ready' WHERE id = ?", [$callId]);
                $completed += count($batch);
            } catch (\Throwable $e) {
                foreach ($batch as $request) {
                    $this->cache->execute("UPDATE requests SET status = 'failed', error = ? WHERE id = ?", [get_class($e), $request['id']]);
                }
                $this->cache->execute("UPDATE provider_calls SET status = 'failed' WHERE id = ?", [$callId]);
                throw $e;
            }
        }

        return ['modelCalls' => $calls, 'completed' => $completed];
    }

    #[AsCommand('ai:embed:consume', 'Generate pending embeddings in the configured app-local cache.')]
    public function consumeCommand(SymfonyStyle $io, #[Option] int $batchSize = 8, #[Option] int $limit = 1000): int
    {
        $io->writeln(json_encode($this->consume($batchSize, $limit), JSON_THROW_ON_ERROR));

        return 0;
    }

    #[AsCommand('ai:embed:export', 'Export ready embeddings with model provenance to a portable .jsonl.gz archive.')]
    public function export(SymfonyStyle $io, #[Argument] string $path): int
    {
        if (!str_ends_with($path, '.jsonl.gz') || file_exists($path)) {
            throw new \InvalidArgumentException('Choose a new .jsonl.gz path; existing archives are preserved.');
        }
        $this->cache->register($this->profile);
        $writer = JsonlWriter::open($path, 'w');
        try {
            $rows = $this->cache->execute("SELECT id, input FROM requests WHERE profile_id = ? AND status = 'ready' ORDER BY id", [$this->profile->id()])->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $writer->write(['schemaVersion' => 1, 'profile' => (array) $this->profile, 'profileId' => $this->profile->id(), 'id' => $row['id'], 'input' => $row['input'], 'vector' => $this->cache->vector($row['id'], $this->profile)]);
            }
            $writer->finish();
        } finally {
            $writer->close();
        }
        $io->success(sprintf('Exported %d embeddings.', count($rows)));

        return 0;
    }
}
