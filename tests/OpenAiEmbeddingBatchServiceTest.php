<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\AiEmbedBundle\BatchHttpClient;
use Survos\AiEmbedBundle\DuckDbEmbeddingCache;
use Survos\AiEmbedBundle\EmbeddingProfile;
use Survos\AiEmbedBundle\OpenAiEmbeddingBatchService;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tacman\AiBatch\Service\OpenAiBatchClient;

final class OpenAiEmbeddingBatchServiceTest extends TestCase
{
    private string $dir;
    private DuckDbEmbeddingCache $cache;
    private EmbeddingProfile $profile;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/embed-batch-test-'.bin2hex(random_bytes(8));
        $this->cache = new DuckDbEmbeddingCache($this->dir.'/cache.duckdb');
        $this->profile = new EmbeddingProfile('text-embedding-3-small', 'test-generation', 3, '', '', 'openai');
        $this->cache->register($this->profile);
    }

    protected function tearDown(): void
    {
        unset($this->cache);
        gc_collect_cycles();
        (new Filesystem())->remove($this->dir);
    }

    private function service(MockHttpClient $http): OpenAiEmbeddingBatchService
    {
        return new OpenAiEmbeddingBatchService($this->cache, $this->profile, new OpenAiBatchClient(new BatchHttpClient($http, $this->cache, $this->profile), 'test-key'));
    }

    private function line(string $id, array $vector = [1., 2., 3.]): array
    {
        return ['custom_id' => $id, 'response' => ['status_code' => 200, 'body' => ['model' => $this->profile->model, 'data' => [['index' => 0, 'embedding' => $vector]], 'usage' => ['prompt_tokens' => 4]]], 'error' => null];
    }

    public function testSubmitAndCollectOutOfOrderOnceThenCacheOnly(): void
    {
        $a = $this->cache->ensure('a', $this->profile);
        $b = $this->cache->ensure('b', $this->profile);
        $http = new MockHttpClient(function ($method, $url, $options) use ($a, $b) {
            if ('POST' === $method && str_ends_with($url, '/files')) {
                return new JsonMockResponse(['id' => 'file-input']);
            }
            if ('POST' === $method && str_ends_with($url, '/batches')) {
                $body = json_decode($options['body'], true);
                self::assertSame('/v1/embeddings', $body['endpoint']);
                self::assertSame('24h', $body['completion_window']);

                return new JsonMockResponse(['id' => 'batch-1', 'status' => 'validating', 'input_file_id' => 'file-input']);
            }
            if (str_ends_with($url, '/batches/batch-1')) {
                return new JsonMockResponse(['id' => 'batch-1', 'status' => 'completed', 'output_file_id' => 'file-output']);
            }
            if (str_ends_with($url, '/files/file-output/content')) {
                return new MockResponse(json_encode($this->line($b['id'], [3., 2., 1.]))."\n".json_encode($this->line($a['id']))."\n");
            }
            throw new \LogicException('Unexpected HTTP request');
        });
        $service = $this->service($http);
        $submitted = $service->submit();
        self::assertSame(2, $submitted['submitted']);
        self::assertSame(2, $submitted['apiCalls']);
        self::assertSame(['submitted' => 0, 'apiCalls' => 0], $service->submit());
        // A new coordinator instance resumes solely from persisted job state.
        $service = $this->service($http);
        self::assertSame(['completed' => 2, 'failed' => 0, 'waiting' => 0, 'apiCalls' => 2], $service->collect());
        self::assertEquals([1., 2., 3.], $this->cache->vector($a['id'], $this->profile));
        self::assertEquals([3., 2., 1.], $this->cache->vector($b['id'], $this->profile));
        $calls = $this->cache->callCount();
        self::assertSame(['completed' => 0, 'failed' => 0], $service->replay($submitted['localId']));
        self::assertSame(['completed' => 0, 'failed' => 0, 'waiting' => 0, 'apiCalls' => 0], $service->collect());
        self::assertSame(['submitted' => 0, 'apiCalls' => 0], $service->submit());
        self::assertSame($calls, $this->cache->callCount());
    }

    public function testUncertainSubmissionCannotBeRepeatedAndCanAttachMatchingJob(): void
    {
        $this->cache->ensure('a', $this->profile);
        $http = new MockHttpClient([new JsonMockResponse(['id' => 'file-input']), new JsonMockResponse(['error' => ['message' => 'uncertain']], ['http_code' => 500])]);
        $service = $this->service($http);
        try {
            $service->submit();
            self::fail('Expected submission failure');
        } catch (\Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface) {
        }
        $job = $this->cache->execute('SELECT * FROM embedding_batches')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('submitting', $job['state']);
        $calls = $this->cache->callCount();
        try {
            $service->resume($job['id']);
            self::fail('Uncertain job resubmitted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('uncertain', $e->getMessage());
        }
        self::assertSame($calls, $this->cache->callCount());
        $recovery = $this->service(new MockHttpClient([new JsonMockResponse(['id' => 'batch-recovered', 'status' => 'validating', 'input_file_id' => 'file-input', 'endpoint' => '/v1/embeddings', 'metadata' => ['embeddingBatchId' => $job['id'], 'embeddingProfileId' => $this->profile->id()]])]));
        $recovery->attach($job['id'], 'batch-recovered');
        self::assertSame('batch-recovered', $this->cache->execute('SELECT remote_id FROM embedding_batches')->fetchColumn());
    }

    private function seedArchive(array $requests, array $lines): OpenAiEmbeddingBatchService
    {
        $path = $this->dir.'/results.jsonl.gz';
        $writer = JsonlWriter::open($path, 'w');
        try {
            foreach ($lines as $line) {
                $writer->write($line);
            } $writer->finish();
        } finally {
            $writer->close();
        }
        $this->cache->execute("INSERT INTO embedding_batches (id, profile_id, state, input_path, result_path) VALUES ('job', ?, 'completed', 'unused', ?)", [$this->profile->id(), $path]);
        foreach ($requests as $r) {
            $this->cache->execute("INSERT INTO embedding_batch_requests VALUES ('job', ?)", [$r['id']]);
        }

        return $this->service(new MockHttpClient(fn () => throw new \LogicException('Replay must be offline')));
    }

    public function testPartialAndMissingResultsRemainRetryableWithoutLosingSuccess(): void
    {
        $a = $this->cache->ensure('a', $this->profile);
        $b = $this->cache->ensure('b', $this->profile);
        $c = $this->cache->ensure('c', $this->profile);
        $service = $this->seedArchive([$a, $b, $c], [$this->line($a['id']), ['custom_id' => $b['id'], 'error' => ['code' => 'batch_expired', 'message' => 'expired']]]);
        self::assertSame(['completed' => 1, 'failed' => 2], $service->replay('job'));
        self::assertSame('ready', $this->cache->ensure('a', $this->profile)['status']);
        self::assertSame('failed', $this->cache->ensure('b', $this->profile)['status']);
        self::assertSame('failed', $this->cache->ensure('c', $this->profile)['status']);
        self::assertSame(['submitted' => 0, 'apiCalls' => 0], $service->submit());
    }

    public function testMalformedVectorLeavesEntireBatchUnapplied(): void
    {
        $a = $this->cache->ensure('a', $this->profile);
        $b = $this->cache->ensure('b', $this->profile);
        $service = $this->seedArchive([$a, $b], [$this->line($a['id']), $this->line($b['id'], [1.])]);
        try {
            $service->replay('job');
            self::fail('Malformed vector accepted');
        } catch (\UnexpectedValueException) {
        }
        self::assertSame(0, $this->cache->store($this->profile)->count());
        self::assertSame('completed', $this->cache->execute('SELECT state FROM embedding_batches')->fetchColumn());
    }

    public function testDuplicateCustomIdRejectedBeforeWrites(): void
    {
        $a = $this->cache->ensure('a', $this->profile);
        $service = $this->seedArchive([$a], [$this->line($a['id']), $this->line($a['id'])]);
        $this->expectException(\UnexpectedValueException::class);
        $service->replay('job');
    }

    public function testForeignCustomIdRejectedBeforeWrites(): void
    {
        $a = $this->cache->ensure('a', $this->profile);
        $service = $this->seedArchive([$a], [$this->line('foreign')]);
        $this->expectException(\UnexpectedValueException::class);
        $service->replay('job');
    }

    public function testResponseFromDifferentModelRejected(): void
    {
        $a = $this->cache->ensure('a', $this->profile);
        $line = $this->line($a['id']);
        $line['response']['body']['model'] = 'different-model';
        $service = $this->seedArchive([$a],[$line]);
        $this->expectException(\UnexpectedValueException::class);
        $service->replay('job');
    }
}
