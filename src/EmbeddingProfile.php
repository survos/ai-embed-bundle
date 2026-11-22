<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle;

final readonly class EmbeddingProfile
{
    public function __construct(
        public string $model,
        public string $revision,
        public int $dimensions = 768,
        public string $documentPrefix = 'search_document: ',
        public string $queryPrefix = 'search_query: ',
        public string $provider = 'ollama',
        public string $normalization = 'l2',
    ) {
        if ('' === $model || '' === $revision || $dimensions < 1) {
            throw new \InvalidArgumentException('A model, immutable revision and positive dimensions are required.');
        }
    }

    public function id(): string
    {
        return hash('sha256', json_encode($this, JSON_THROW_ON_ERROR));
    }

    public function input(string $text, string $purpose): string
    {
        return match ($purpose) {
            'document' => $this->documentPrefix.$text,
            'query' => $this->queryPrefix.$text,
            default => throw new \InvalidArgumentException('Unknown embedding purpose.'),
        };
    }
}
