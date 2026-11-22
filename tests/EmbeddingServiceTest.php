<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\AiEmbedBundle\DuckDbEmbeddingCache;
use Survos\AiEmbedBundle\EmbeddingProfile;
use Survos\AiEmbedBundle\EmbeddingProviderInterface;
use Survos\AiEmbedBundle\EmbeddingService;

final class EmbeddingServiceTest extends TestCase
{
    private array $paths = [];

    private function cache(): DuckDbEmbeddingCache
    {
        $path = sys_get_temp_dir().'/embedding-test-'.bin2hex(random_bytes(8)).'.duckdb';
        $this->paths[] = $path;

        return new DuckDbEmbeddingCache($path);
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        foreach ($this->paths as $path) {
            foreach (glob($path.'*') as $file) {
                unlink($file);
            }
        }
    }

    public function testDocumentReuseDeduplicationAndProfileIsolation(): void
    {
        $cache = $this->cache();
        $provider = new class implements EmbeddingProviderInterface {
            public int $calls = 0;

            public function embed(array $inputs, EmbeddingProfile $profile): array
            {
                ++$this->calls;

                return array_fill(0, count($inputs), [1., 2., 3.]);
            }
        };
        $profile = new EmbeddingProfile('nomic-embed-text:v1.5', 'pinned-test-revision', 3);
        $service = new EmbeddingService($cache, $profile, $provider);
        $first = $service->ensureDocumentEmbedding('bill:one', 'Title', "First paragraph.\n\nSecond paragraph.");
        self::assertSame('pending', $first['status']);
        $other = $service->ensureDocumentEmbedding('bill:two', 'Title', "First paragraph.\n\nSecond paragraph.");
        self::assertNotSame($first['documentId'], $other['documentId']);
        self::assertSame(['modelCalls' => 1, 'completed' => 1], $service->consume());
        $reloaded = $service->ensureDocumentEmbedding('bill:one', 'Title', "First paragraph.\n\nSecond paragraph.");
        self::assertSame('ready', $reloaded['status']);
        self::assertSame(['modelCalls' => 0, 'completed' => 0], $service->consume());
        self::assertSame(1, $provider->calls);
        $changed = $service->ensureDocumentEmbedding('bill:one', 'Title', 'Changed text');
        self::assertSame(1, $changed['pending']);
        self::assertSame('ready', $service->ensureDocumentEmbedding('bill:two', 'Title', "First paragraph.\n\nSecond paragraph.")['status']);
        $newProfile = new EmbeddingService($cache, new EmbeddingProfile('nomic-embed-text:v1.5', 'different-revision', 3), $provider);
        self::assertSame('pending', $newProfile->ensureEmbedding("Title\n\nFirst paragraph.\n\nSecond paragraph.")['status']);
        self::assertNotSame($service->ensureEmbedding('same')['id'], $service->ensureEmbedding('same', 'query')['id']);
    }

    public function testInvalidBatchIsNotPartiallyStoredAndCanRetry(): void
    {
        $cache = $this->cache();
        $provider = new class implements EmbeddingProviderInterface {
            public bool $invalid = true;

            public function embed(array $inputs, EmbeddingProfile $profile): array
            {
                return $this->invalid ? [[1., 2., 3.], [0., 0., 0.]] : [[1., 2., 3.], [3., 2., 1.]];
            }
        };
        $service = new EmbeddingService($cache, new EmbeddingProfile('nomic-embed-text:v1.5', 'test', 3), $provider);
        $a = $service->ensureEmbedding('one');
        $service->ensureEmbedding('two');
        try {
            $service->consume();
            self::fail('Invalid vector accepted');
        } catch (\UnexpectedValueException) {
        }
        self::assertSame(0, $cache->store($service->profile)->count());
        self::assertSame('failed', $service->ensureEmbedding('one')['status']);
        $provider->invalid = false;
        self::assertSame(['modelCalls' => 1, 'completed' => 2], $service->consume());
        self::assertCount(3, $cache->vector($a['id'], $service->profile));
        self::assertSame(2, $cache->callCount());
    }

    public function testRestartPreservesCompletedVectorsAndPendingWork(): void
    {
        $cache = $this->cache();
        $path = $cache->path;
        $profile = new EmbeddingProfile('nomic-embed-text:v1.5', 'test', 3);
        $request = $cache->ensure('cached input', $profile);
        $cache->complete([$request], [[1., 2., 3.]], $profile);
        $cache->ensure('pending input', $profile);
        unset($cache);
        $reopened = new DuckDbEmbeddingCache($path);
        self::assertTrue($reopened->ensure('cached input', $profile)['cacheHit']);
        self::assertSame([1., 2., 3.], array_map(floatval(...), $reopened->vector($request['id'], $profile)));
        self::assertSame('pending', $reopened->ensure('pending input', $profile)['status']);
    }
}
