<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\AiEmbedBundle\EmbeddingProfile;
use Survos\AiEmbedBundle\OllamaEmbeddingProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class OllamaEmbeddingProviderTest extends TestCase
{
    public function testPinnedModelAndNoTruncation(): void
    {
        $calls = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$calls) {
            $calls[] = $url;
            if (str_ends_with($url, '/api/tags')) {
                return new JsonMockResponse(['models' => [['name' => 'nomic-embed-text:v1.5', 'digest' => 'pinned']]]);
            }
            $data = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($data['truncate']);
            self::assertSame(['search_document: text'], $data['input']);

            return new JsonMockResponse(['embeddings' => [[1., 2., 3.]]]);
        });
        $provider = new OllamaEmbeddingProvider($http);
        self::assertCount(1, $provider->embed(['search_document: text'], new EmbeddingProfile('nomic-embed-text:v1.5', 'pinned', 3)));
        self::assertCount(2, $calls);
    }

    public function testChangedModelNeverReachesEmbeddingEndpoint(): void
    {
        $http = new MockHttpClient([new JsonMockResponse(['models' => [['name' => 'nomic-embed-text:v1.5', 'digest' => 'changed']]])]);
        $this->expectException(\RuntimeException::class);
        (new OllamaEmbeddingProvider($http))->embed(['text'], new EmbeddingProfile('nomic-embed-text:v1.5', 'pinned', 3));
    }
}
