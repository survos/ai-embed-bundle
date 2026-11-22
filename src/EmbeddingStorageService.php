<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/** One command service; transport can be S3 or any existing Flysystem storage. */
final class EmbeddingStorageService
{
    public function __construct(private readonly DuckDbEmbeddingCache $cache,
        private readonly EmbeddingSnapshot $snapshots = new EmbeddingSnapshot(),
        private ?FilesystemOperator $remote = null, private readonly array $s3 = [])
    {
    }

    #[AsCommand('embed:push', 'Publish a checksummed ready-only embedding snapshot; never publishes pending jobs.')]
    public function pushCommand(SymfonyStyle $io, #[Argument('Repository namespace, e.g. survos-sites/packages')] string $repository): int
    {
        $io->writeln(json_encode($this->push($repository), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return 0;
    }

    #[AsCommand('embed:pull', 'Install a verified snapshot into an absent local cache; refuses divergent local files.')]
    public function pullCommand(SymfonyStyle $io, #[Argument] string $repository,
        #[Option('Pin an immutable snapshot SHA-256 instead of latest')] ?string $snapshot = null): int
    {
        $io->writeln(json_encode($this->pull($repository, $snapshot), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return 0;
    }

    #[AsCommand('embed:merge', 'Merge completed remote vectors and document mappings into the local cache.')]
    public function mergeCommand(SymfonyStyle $io, #[Argument] string $repository,
        #[Option('Pin an immutable snapshot SHA-256 instead of latest')] ?string $snapshot = null): int
    {
        $io->writeln(json_encode($this->merge($repository, $snapshot), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return 0;
    }

    public function push(string $repository): array
    {
        $this->repository($repository);
        $remote = $this->remote();
        if (!is_file($this->cache->path)) {
            throw new \LogicException('There is no local embedding database to publish.');
        }
        $path = $this->temporary();
        try {
            $counts = $this->snapshots->create($this->cache, $path);
            if (0 === $counts['vectors']) {
                throw new \LogicException('No completed vectors to publish.');
            }
            $sha = hash_file('sha256', $path);
            $key = $repository.'/snapshots/'.$sha.'.duckdb';
            $manifest = ['schemaVersion' => EmbeddingSnapshot::VERSION, 'repository' => $repository,
                'sha256' => $sha, 'bytes' => filesize($path), 'key' => $key,
                'createdAt' => gmdate(DATE_ATOM), 'profiles' => $counts['profiles'],
                'vectors' => $counts['vectors'], 'documents' => $counts['documents'], 'readyOnly' => true];
            $stream = fopen($path, 'rb');
            try {
                $remote->writeStream($key, $stream, ['visibility' => 'private']);
            } finally {
                fclose($stream);
            }
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
            $remote->write($repository.'/snapshots/'.$sha.'.json', $json, ['visibility' => 'private']);
            // Publish the pointer last. Older immutable snapshots remain available after concurrent pushes.
            $remote->write($repository.'/latest.json', $json, ['visibility' => 'private']);

            return $manifest;
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            @unlink($path.'.wal');
        }
    }

    public function pull(string $repository, ?string $snapshot = null): array
    {
        $manifest = $this->manifest($repository, $snapshot);
        if ($this->cache->isOpen()) {
            throw new \LogicException('Close the local cache before pulling a snapshot.');
        }

        return $this->cache->withFileLock(function () use ($manifest): array {
            if (file_exists($this->cache->path.'.wal')) {
                throw new \LogicException('Local WAL exists; open/checkpoint the local cache before pulling.');
            }
            if (file_exists($this->cache->path)) {
                if (hash_equals($manifest['sha256'], hash_file('sha256', $this->cache->path))) {
                    return ['path' => $this->cache->path, 'sha256' => $manifest['sha256'], 'reused' => true];
                }
                throw new \LogicException('Local embeddings differ. Use embed:merge; pull never overwrites local work.');
            }
            $path = $this->download($manifest);
            try {
                $this->checkContents($path, $manifest);
                if (!rename($path, $this->cache->path)) {
                    throw new \RuntimeException('Cannot install embedding snapshot.');
                }

                return ['path' => $this->cache->path, 'sha256' => $manifest['sha256'], 'reused' => false];
            } finally {
                @unlink($path);
            }
        });
    }

    public function merge(string $repository, ?string $snapshot = null): array
    {
        $manifest = $this->manifest($repository, $snapshot);
        $path = $this->download($manifest);
        try {
            $this->checkContents($path, $manifest);

            return ['sha256' => $manifest['sha256'], ...$this->snapshots->merge($path, $this->cache)];
        } finally {
            @unlink($path);
        }
    }

    private function remote(): FilesystemOperator
    {
        return $this->remote ??= S3EmbeddingStorage::create($this->s3);
    }

    private function repository(string $name): void
    {
        if (!preg_match('~^[a-zA-Z0-9][a-zA-Z0-9_-]*/[a-zA-Z0-9][a-zA-Z0-9_.-]*$~D', $name) || str_contains($name, '..')) {
            throw new \InvalidArgumentException('Use an owner/repository namespace without traversal or URL syntax.');
        }
    }

    private function manifest(string $repository, ?string $snapshot): array
    {
        $this->repository($repository);
        if (null !== $snapshot && !preg_match('/^[a-f0-9]{64}$/D', $snapshot)) {
            throw new \InvalidArgumentException('Snapshot must be a SHA-256.');
        }
        $key = $repository.'/'.(null === $snapshot ? 'latest.json' : 'snapshots/'.$snapshot.'.json');
        $remote = $this->remote();
        if ($remote->fileSize($key) > 1048576) {
            throw new \UnexpectedValueException('Oversized snapshot manifest.');
        }
        $manifest = json_decode($remote->read($key), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['schemaVersion'] ?? null) !== EmbeddingSnapshot::VERSION || ($manifest['repository'] ?? null) !== $repository
            || !preg_match('/^[a-f0-9]{64}$/D', $manifest['sha256'] ?? '') || (null !== $snapshot && $manifest['sha256'] !== $snapshot)
            || ($manifest['key'] ?? null) !== $repository.'/snapshots/'.$manifest['sha256'].'.duckdb'
            || !is_int($manifest['bytes'] ?? null) || $manifest['bytes'] <= 0 || ($manifest['readyOnly'] ?? false) !== true
            || !is_array($manifest['profiles'] ?? null) || !is_int($manifest['vectors'] ?? null) || !is_int($manifest['documents'] ?? null)) {
            throw new \UnexpectedValueException('Invalid embedding snapshot manifest.');
        }

        return $manifest;
    }

    private function temporary(): string
    {
        $dir = dirname($this->cache->path);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create embedding directory.');
        }

        return $dir.'/.embedding-snapshot-'.bin2hex(random_bytes(12)).'.duckdb';
    }

    private function download(array $manifest): string
    {
        $path = $this->temporary();
        $input = $output = null;
        try {
            $input = $this->remote()->readStream($manifest['key']);
            $output = fopen($path, 'xb');
            if (!$output) {
                throw new \RuntimeException('Cannot stage snapshot.');
            }
            $size = stream_copy_to_stream($input, $output, $manifest['bytes'] + 1);
            fclose($output);
            $output = null;
            if ($size !== $manifest['bytes'] || !hash_equals($manifest['sha256'], hash_file('sha256', $path))) {
                throw new \UnexpectedValueException('Downloaded snapshot size/checksum mismatch.');
            }

            return $path;
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        } finally {
            foreach ([$input, $output] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    private function checkContents(string $path, array $manifest): void
    {
        $counts = $this->snapshots->validate($path);
        foreach (['profiles', 'vectors', 'documents'] as $field) {
            if ($counts[$field] !== $manifest[$field]) {
                throw new \UnexpectedValueException('Snapshot contents differ from manifest: '.$field);
            }
        }
    }
}
