<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle\Chunking;

use Codewithkyrian\Tokenizers\Tokenizer;
use Yethee\Tiktoken\EncoderProvider;

/** Counts the complete provider input, including task prefixes and model special tokens. */
final class ModelTokenCounter
{
    public const NOMIC = 'nomic-embed-text:v1.5';
    private ?Tokenizer $nomic = null;
    private ?EncoderProvider $openai = null;

    public function profile(string $model): array
    {
        if (self::NOMIC === $model) {
            $root = dirname(__DIR__, 2).'/resources/tokenizers/nomic-embed-text-v1.5';
            $provenance = json_decode(file_get_contents($root.'/provenance.json'), true, flags: JSON_THROW_ON_ERROR);

            return ['model' => $model, 'tokenizer' => 'bert-wordpiece', 'revision' => $provenance['revision'],
                'sha256' => $provenance['sha256'], 'contextTokens' => 2048, 'documentPrefix' => 'search_document: ', 'queryPrefix' => 'search_query: ', 'specialTokens' => true];
        }
        if (!in_array($model, ['text-embedding-3-small', 'text-embedding-3-large', 'text-embedding-ada-002'], true)) {
            throw new \InvalidArgumentException('Unsupported embedding tokenizer: '.$model);
        }

        return ['model' => $model, 'tokenizer' => 'cl100k_base', 'contextTokens' => 8191,
            'documentPrefix' => '', 'queryPrefix' => '', 'specialTokens' => false];
    }

    public function input(string $text, string $model, string $purpose = 'document'): string
    {
        if (!in_array($purpose, ['document', 'query'], true)) {
            throw new \InvalidArgumentException('Purpose must be document or query.');
        }

        return $this->profile($model)[$purpose.'Prefix'].$text;
    }

    public function count(string $text, string $model, string $purpose = 'document'): int
    {
        $input = $this->input($text, $model, $purpose);
        if (self::NOMIC === $model) {
            if (null === $this->nomic) {
                $root = dirname(__DIR__, 2).'/resources/tokenizers/nomic-embed-text-v1.5';
                foreach ($this->profile($model)['sha256'] as $file => $hash) {
                    if (hash_file('sha256', $root.'/'.$file) !== $hash) {
                        throw new \RuntimeException('Tokenizer checksum mismatch: '.$file);
                    }
                }
                $this->nomic = Tokenizer::fromFile($root.'/tokenizer.json', $root.'/tokenizer_config.json');
            }

            return count($this->nomic->encode($input, addSpecialTokens: true)->ids);
        }
        $this->openai ??= new EncoderProvider();

        return count($this->openai->getForModel($model)->encode($input));
    }
}
