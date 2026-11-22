<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class OptionalDuckDbTest extends TestCase
{
    public function testMissingAdapterFailsBeforeCreatingFiles(): void
    {
        $this->assertUnavailable('', 'composer require survos/ai-duckdb-store');
    }

    public function testMissingDriverFailsBeforeCreatingFiles(): void
    {
        $this->assertUnavailable('namespace Survos\\Ai\\DuckDb { class Store {} }', 'native PDO DuckDB driver');
    }

    private function assertUnavailable(string $stub, string $expected): void
    {
        $path = sys_get_temp_dir().'/missing-duckdb-'.bin2hex(random_bytes(8)).'/cache.duckdb';
        $code = $stub.' namespace { require '.var_export(dirname(__DIR__).'/src/DuckDbEmbeddingCache.php', true).'; '
            .'try { (new \\Survos\\AiEmbedBundle\\DuckDbEmbeddingCache('.var_export($path, true).'))->connection(); exit(2); } '
            .'catch (\\LogicException $e) { echo $e->getMessage(); } }';
        // No Composer loader or PHP extensions: exercise an actual installation without the adapter/driver.
        $process = new Process([PHP_BINARY, '-n', '-r', $code]);
        $process->mustRun();
        self::assertStringContainsString($expected, $process->getOutput());
        self::assertDirectoryDoesNotExist(dirname($path));
    }
}
