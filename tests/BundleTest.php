<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\AiEmbedBundle\SurvosAiEmbedBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;

final class BundleTest extends TestCase
{
    public function testBundleBootsWithoutDatabaseOrProviderCredentials(): void
    {
        $directory = sys_get_temp_dir().'/ai-embed-kernel-'.bin2hex(random_bytes(8));
        $kernel = new class($directory) extends Kernel {
            public function __construct(private readonly string $directory)
            {
                parent::__construct('test', true);
            }

            public function registerBundles(): iterable
            {
                yield new FrameworkBundle();
                yield new SurvosKitBundle();
                yield new SurvosAiEmbedBundle();
            }

            public function registerContainerConfiguration(LoaderInterface $loader): void
            {
                $loader->load(static function ($container): void {
                    $container->loadFromExtension('framework', ['secret' => 'ai-embed-test', 'http_method_override' => false]);
                });
            }

            public function getCacheDir(): string
            {
                return $this->directory.'/cache';
            }

            public function getLogDir(): string
            {
                return $this->directory.'/log';
            }
        };
        try {
            $kernel->boot();
            self::assertInstanceOf(SurvosAiEmbedBundle::class, $kernel->getBundle('SurvosAiEmbedBundle'));
            self::assertFalse($kernel->getContainer()->has('doctrine'));
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($directory);
        }
    }
}
