<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Logs each outgoing API attempt before dispatch, without headers, credentials or input text. */
final class BatchHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    public function __construct(HttpClientInterface $client, private readonly DuckDbEmbeddingCache $cache, private readonly EmbeddingProfile $profile)
    {
        $this->client = $client;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->cache->execute("INSERT INTO provider_calls (id, profile_id, inputs, status, operation) VALUES (?, ?, 0, 'attempted', ?)",
            [bin2hex(random_bytes(16)), $this->profile->id(), $method.' '.parse_url($url, PHP_URL_PATH)]);

        return $this->client->request($method, $url, $options);
    }
}
