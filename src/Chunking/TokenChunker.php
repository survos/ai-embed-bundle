<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle\Chunking;

/** Greedy paragraph packing, with original Unicode offsets and exact-input budget checks. */
final readonly class TokenChunker
{
    public const VERSION = 'token-paragraphs-v1';

    public function __construct(private ModelTokenCounter $counter = new ModelTokenCounter())
    {
    }

    public function profile(string $model, int $maxTokens): array
    {
        $tokenizer = $this->counter->profile($model);
        if ($maxTokens < 1 || $maxTokens > $tokenizer['contextTokens']) {
            throw new \InvalidArgumentException('Token budget outside model context.');
        }

        return ['version' => self::VERSION, 'maxTokens' => $maxTokens, 'tokenizer' => $tokenizer,
            'titlePolicy' => 'title-newline-newline-v1', 'offsetUnit' => 'unicode-codepoints', 'endExclusive' => true];
    }

    public function chunks(string $title, string $text, string $model, int $maxTokens): array
    {
        $this->profile($model, $maxTokens);
        $prefix = $title."\n\n";
        if ($this->counter->count($prefix, $model) >= $maxTokens) {
            throw new \InvalidArgumentException('Title and model prefix exhaust the token budget.');
        }
        if ('' === $text) {
            return [];
        }
        // Blank lines delimit paragraphs. Keep every separator in the preceding paragraph.
        $parts = preg_split('/((?:\r?\n)[\t ]*(?:\r?\n)(?:[\t ]*\r?\n)*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $paragraphs = [];
        $offset = 0;
        for ($i = 0; $i < count($parts); $i += 2) {
            $part = $parts[$i].($parts[$i + 1] ?? '');
            if ('' !== $part) {
                $end = $offset + mb_strlen($part, 'UTF-8');
                $paragraphs[] = [$offset, $end];
                $offset = $end;
            }
        }
        $chunks = [];
        $start = 0;
        $end = 0;
        $count = fn (int $a, int $b) => $this->counter->count($prefix.mb_substr($text, $a, $b - $a, 'UTF-8'), $model);
        $emit = function (int $a, int $b) use (&$chunks, $prefix, $text, $count, $maxTokens): void {
            if ($b <= $a) {
                return;
            }
            $tokens = $count($a, $b);
            if ($tokens > $maxTokens) {
                throw new \LogicException('Chunk exceeds token budget.');
            }
            $chunks[] = ['text' => $prefix.mb_substr($text, $a, $b - $a, 'UTF-8'), 'charStart' => $a, 'charEnd' => $b, 'tokenCount' => $tokens];
        };
        foreach ($paragraphs as [$a, $b]) {
            if ($count($start, $b) <= $maxTokens) {
                $end = $b;
                continue;
            }
            $emit($start, $end);
            $start = $a;
            $end = $a;
            if ($count($a, $b) <= $maxTokens) {
                $end = $b;
                continue;
            }
            // An oversized paragraph is packed by whitespace-delimited words. Never decode tokens back to source text.
            preg_match_all('/\S+\s*|\s+/u', mb_substr($text, $a, $b - $a, 'UTF-8'), $words);
            $ends = [];
            $position = $a;
            foreach ($words[0] as $word) {
                $position += mb_strlen($word, 'UTF-8');
                $ends[] = $position;
            }
            $i = 0;
            $n = count($ends);
            while ($i < $n) {
                if ($count($start, $ends[$i]) > $maxTokens) {
                    // A single unbroken word is too large; halve until a verified prefix fits.
                    $wordEnd = $ends[$i];
                    while ($start < $wordEnd) {
                        $take = $wordEnd - $start;
                        while ($count($start, $start + $take) > $maxTokens) {
                            if (1 === $take) {
                                throw new \InvalidArgumentException('A source character cannot fit with the title/prefix.');
                            }
                            $take = max(1, intdiv($take, 2));
                        }
                        $end = $start + $take;
                        if ($end < $wordEnd) {
                            $emit($start, $end);
                            $start = $end;
                        } else {
                            break;
                        }
                    }
                    ++$i;
                    if ($i < $n) {
                        $emit($start, $end);
                        $start = $end;
                    }
                    continue;
                }
                // Find a fitting group by exponential probes, then refine the first failing range.
                // Token counts need not be monotonic: only observed fitting endpoints are emitted.
                $fit = $i;
                $step = 1;
                $fail = $n;
                while ($fit < $n - 1) {
                    $probe = min($n - 1, $i + $step);
                    if ($count($start, $ends[$probe]) > $maxTokens) {
                        $fail = $probe;
                        break;
                    }
                    $fit = $probe;
                    $step *= 2;
                }
                $lo = $fit + 1;
                $hi = $fail - 1;
                while ($lo <= $hi) {
                    $mid = intdiv($lo + $hi, 2);
                    if ($count($start, $ends[$mid]) <= $maxTokens) {
                        $fit = $mid;
                        $lo = $mid + 1;
                    } else {
                        $hi = $mid - 1;
                    }
                }
                $end = $ends[$fit];
                $i = $fit + 1;
                if ($i < $n) {
                    $emit($start, $end);
                    $start = $end;
                }
            }
        }
        $emit($start, $end);

        return $chunks;
    }
}
