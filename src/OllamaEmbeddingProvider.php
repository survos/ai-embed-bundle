<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OllamaEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(private HttpClientInterface $http, private string $url = 'http://localhost:11434')
    {
    }

    public function embed(array $inputs, EmbeddingProfile $profile): array
    {
        if ('ollama' !== $profile->provider) {
            throw new \InvalidArgumentException('This consumer requires an Ollama profile.');
        }
        $models = $this->http->request('GET', rtrim($this->url, '/').'/api/tags')->toArray()['models'] ?? [];
        $matches = array_filter($models, fn ($m) => ($m['name'] ?? '') === $profile->model && ($m['digest'] ?? '') === $profile->revision);
        if (!$matches) {
            throw new \RuntimeException('Installed model does not match the pinned embedding profile.');
        }

        return $this->http->request('POST', rtrim($this->url, '/').'/api/embed', ['timeout' => 120, 'json' => [
            'model' => $profile->model, 'input' => $inputs, 'truncate' => false, 'keep_alive' => '10m',
        ]])->toArray()['embeddings'] ?? [];
    }
}
