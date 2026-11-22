<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle;

interface EmbeddingProviderInterface
{
    /** Inputs already include the profile's task prefix. @return list<list<float>> */
    public function embed(array $inputs, EmbeddingProfile $profile): array;
}
