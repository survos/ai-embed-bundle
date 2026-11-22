<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle\Tests;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Survos\AiEmbedBundle\DuckDbEmbeddingCache;
use Survos\AiEmbedBundle\EmbeddingProfile;
use Survos\AiEmbedBundle\EmbeddingProviderInterface;
use Survos\AiEmbedBundle\EmbeddingService;
use Survos\AiEmbedBundle\EmbeddingSnapshot;
use Survos\AiEmbedBundle\EmbeddingStorageService;
use Symfony\Component\Filesystem\Filesystem as LocalFiles;

final class EmbeddingStorageTest extends TestCase
{
    private string $dir;
    private Filesystem $remote;
    private array $caches = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/embed-storage-'.bin2hex(random_bytes(8));
        $this->remote = new Filesystem(new LocalFilesystemAdapter($this->dir.'/remote'));
    }

    protected function tearDown(): void
    {
        foreach ($this->caches as $cache) {
            $cache->close();
        }
        (new LocalFiles())->remove($this->dir);
    }

    private function cache(string $name): DuckDbEmbeddingCache
    {
        return $this->caches[] = new DuckDbEmbeddingCache($this->dir.'/'.$name.'.duckdb');
    }

    private function service(DuckDbEmbeddingCache $cache): EmbeddingStorageService
    {
        return new EmbeddingStorageService($cache, new EmbeddingSnapshot(), $this->remote);
    }

    private function ready(DuckDbEmbeddingCache $cache, string $input = 'input', ?array $vector = null, string $revision = 'r1'): array
    {
        $profile = new EmbeddingProfile('nomic-embed-text:v1.5', $revision, 3);
        $request = $cache->ensure($input, $profile);
        $cache->complete([$request], [$vector ?? [1., 2., 3.]], $profile);

        return [$request, $profile];
    }

    public function testPushPullMergePreserveModelsDocumentsAndNeverTransferWorkOwnership(): void
    {
        $source = $this->cache('source');
        [$request, $profile] = $this->ready($source);
        $this->ready($source, 'input', [3., 2., 1.], 'r2');
        $provider = new class implements EmbeddingProviderInterface {
            public int $calls = 0;

            public function embed(array $inputs, EmbeddingProfile $profile): array
            {
                ++$this->calls;

                return array_fill(0, count($inputs), [1., 2., 3.]);
            }
        };
        $embed = new EmbeddingService($source, $profile, $provider);
        $doc = $embed->ensureDocumentEmbedding('repo/README', 'Title', "Hello.\n\nWorld.");
        $embed->consume();
        $embed->ensureDocumentEmbedding('repo/pending', 'Pending', 'Not yet embedded.');
        $source->execute("INSERT INTO embedding_batches (id,profile_id,state,input_path) VALUES ('owned',?,'submitting','local.jsonl')", [$profile->id()]);
        $manifest = $this->service($source)->push('survos/test');
        self::assertSame(3, $manifest['vectors']);
        self::assertSame(1, $manifest['documents']);
        self::assertCount(2, $manifest['profiles']);
        $dest = $this->cache('dest');
        $storage = $this->service($dest);
        self::assertFalse($storage->pull('survos/test')['reused']);
        self::assertTrue($storage->pull('survos/test')['reused']);
        self::assertSame(0, $dest->callCount());
        self::assertSame(0, (int) $dest->execute('SELECT count(*) FROM embedding_batches')->fetchColumn());
        self::assertSame(0, (int) $dest->execute("SELECT count(*) FROM requests WHERE status <> 'ready'")->fetchColumn());
        $reimport = new EmbeddingService($dest, $profile, $provider);
        self::assertSame('ready', $reimport->ensureDocumentEmbedding('repo/README', 'Title', "Hello.\n\nWorld.")['status']);
        self::assertSame(['modelCalls' => 0, 'completed' => 0], $reimport->consume());
        self::assertSame(1, $provider->calls);
        $local = $this->cache('local');
        $this->ready($local, 'only-local');
        $local->ensure('local-pending', $profile);
        $merge = $this->service($local);
        self::assertSame(3, $merge->merge('survos/test', $manifest['sha256'])['added']);
        self::assertSame(3, $merge->merge('survos/test')['reused']);
        self::assertSame(1, (int) $local->execute("SELECT count(*) FROM requests WHERE status='pending'")->fetchColumn());
        self::assertSame(4, (int) $local->execute("SELECT count(*) FROM requests WHERE status='ready'")->fetchColumn());
    }

    public function testPullRefusesDivergentLocalFile(): void
    {
        $source = $this->cache('source');
        $this->ready($source);
        $this->service($source)->push('survos/test');
        $dest = $this->cache('dest');
        $this->ready($dest, 'local');
        $dest->close();
        $sha = hash_file('sha256', $dest->path);
        try {
            $this->service($dest)->pull('survos/test');
            self::fail('Overwrote local data');
        } catch (\LogicException $e) {
            self::assertStringContainsString('embed:merge', $e->getMessage());
        }
        self::assertSame($sha, hash_file('sha256', $dest->path));
    }

    public function testConflictingMergeRollsBackAllNewRequests(): void
    {
        $source = $this->cache('source');
        $this->ready($source, 'shared', [3., 2., 1.]);
        $this->ready($source, 'new');
        $this->service($source)->push('survos/test');
        $dest = $this->cache('dest');
        [$request, $profile] = $this->ready($dest, 'shared');
        try {
            $this->service($dest)->merge('survos/test');
            self::fail('Accepted conflict');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('Conflicting vector', $e->getMessage());
        }
        self::assertSame(1, (int) $dest->execute('SELECT count(*) FROM requests')->fetchColumn());
        self::assertSame([1., 2., 3.], array_map(floatval(...), $dest->vector($request['id'], $profile)));
    }

    public function testCorruptDownloadCannotCreateLocalDatabase(): void
    {
        $source = $this->cache('source');
        $this->ready($source);
        $manifest = $this->service($source)->push('survos/test');
        $this->remote->write($manifest['key'], 'corrupt');
        $dest = $this->cache('dest');
        try {
            $this->service($dest)->pull('survos/test');
            self::fail('Accepted corrupt download');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('checksum', $e->getMessage());
        }
        self::assertFileDoesNotExist($dest->path);
        self::assertSame([], glob($this->dir.'/.embedding-snapshot-*'));
    }

    public function testMalformedManifestCannotSelectAnUnrelatedObject(): void
    {
        $source = $this->cache('source');
        $this->ready($source);
        $manifest = $this->service($source)->push('survos/test');
        $manifest['key'] = 'different/private.duckdb';
        $this->remote->write('survos/test/latest.json', json_encode($manifest));
        $this->expectException(\UnexpectedValueException::class);
        $this->service($this->cache('dest'))->pull('survos/test');
    }

    public function testActiveLocalBatchCannotBeSupersededByMerge(): void
    {
        $source = $this->cache('source');
        [$request, $profile] = $this->ready($source);
        $this->service($source)->push('survos/test');
        $dest = $this->cache('dest');
        $dest->ensure('input', $profile);
        $dest->execute("INSERT INTO embedding_batches (id,profile_id,state,input_path) VALUES ('local',?,'submitting','local.jsonl')", [$profile->id()]);
        $dest->execute("INSERT INTO embedding_batch_requests VALUES ('local',?)", [$request['id']]);
        try {
            $this->service($dest)->merge('survos/test');
            self::fail('Overrode active work');
        } catch (\LogicException $e) {
            self::assertStringContainsString('locally owned batch', $e->getMessage());
        }
        self::assertSame('pending', $dest->ensure('input', $profile)['status']);
    }

    public function testFileLockProtectsPullAndConnectionCanBeReopened(): void
    {
        $source = $this->cache('source');
        $this->ready($source);
        $this->service($source)->push('survos/test');
        $dest = $this->cache('dest');
        $other = $this->cache('dest');
        $dest->withFileLock(function () use ($other): void {
            try {
                $this->service($other)->pull('survos/test');
                self::fail('Ignored lock');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('in use', $e->getMessage());
            }
        });
        self::assertFalse($this->service($dest)->pull('survos/test')['reused']);
        self::assertSame(0, $dest->callCount());
        $dest->close();
        self::assertSame(0, $dest->callCount());
    }

    public function testInvalidRequestIdentityIsRejectedEvenWithAValidFileChecksum(): void
    {
        $source = $this->cache('source');
        $this->ready($source);
        $manifest = $this->service($source)->push('survos/test');
        $path = $this->dir.'/remote/'.$manifest['key'];
        $db = new \PDO('duckdb:'.$path);
        $db->exec("UPDATE requests SET input='tampered input'");
        $db->exec('CHECKPOINT');
        $db = null;
        $sha = hash_file('sha256', $path);
        $manifest['sha256'] = $sha;
        $manifest['bytes'] = filesize($path);
        $manifest['key'] = 'survos/test/snapshots/'.$sha.'.duckdb';
        $this->remote->write($manifest['key'], file_get_contents($path));
        $this->remote->write('survos/test/latest.json', json_encode($manifest));
        $dest = $this->cache('dest');
        try {
            $this->service($dest)->pull('survos/test');
            self::fail('Accepted invalid input identity');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('input hash', $e->getMessage());
        }
        self::assertFileDoesNotExist($dest->path);
    }

    public function testMissingStorageConfigurationFailsWithoutOpeningTheCache(): void
    {
        $dest = $this->cache('dest');
        try {
            (new EmbeddingStorageService($dest))->pull('survos/test');
            self::fail('Accepted missing config');
        } catch (\LogicException $e) {
            self::assertStringContainsString('Configure', $e->getMessage());
        }
        self::assertFileDoesNotExist($dest->path);
    }
}
