<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\AiEmbedBundle\Chunking\ModelTokenCounter;
use Survos\AiEmbedBundle\Chunking\TokenChunker;

final class TokenChunkerTest extends TestCase
{
    public function testPinnedNomicCountsMatchRecordedOllamaResponses(): void
    {
        $counter = new ModelTokenCounter();
        self::assertSame(9, $counter->count('Hello world!', $counter::NOMIC));
        self::assertSame(14, $counter->count('Café 中文 — wages rise. [CLS]', $counter::NOMIC));
        $fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/nomic-token-counts.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures as $f) {
            self::assertSame($f['tokens'], $counter->count($f['text'], $counter::NOMIC));
        }
        self::assertSame(3, $counter->count('Hello world!', 'text-embedding-3-small'));
        self::assertSame(3, $counter->count('Hello world!', 'text-embedding-3-large'));
    }

    public function testPacksWholeParagraphsWithoutOverlapAndPreservesCrLfAndUnicode(): void
    {
        $c = new ModelTokenCounter();
        $chunker = new TokenChunker($c);
        $title = 'Report';
        $p = ["Café wages rise.\r\n\r\n", "The council met.\r\n\r\n", 'A new park opens.'];
        $limit = $c->count($title."\n\n".$p[0].$p[1], $c::NOMIC);
        $chunks = $chunker->chunks($title, implode('', $p), $c::NOMIC, $limit);
        self::assertCount(2, $chunks);
        self::assertSame($title."\n\n".$p[0].$p[1], $chunks[0]['text']);
        self::assertSame($title."\n\n".$p[2], $chunks[1]['text']);
        $this->assertCoverage($title, implode('', $p), $chunks, $limit);
    }

    public function testParagraphIsNotSplitJustToFillRemainingSpace(): void
    {
        $c = new ModelTokenCounter();
        $chunker = new TokenChunker($c);
        $title = 'Title';
        $first = "Short.\n\n";
        $second = str_repeat('Longer paragraph words. ', 8);
        $budget = $c->count($title."\n\n".$second, $c::NOMIC);
        $chunks = $chunker->chunks($title, $first.$second, $c::NOMIC, $budget);
        self::assertCount(2, $chunks);
        self::assertSame($title."\n\n".$first, $chunks[0]['text']);
        self::assertSame($title."\n\n".$second, $chunks[1]['text']);
    }

    public function testOversizedParagraphAndUnbrokenUnicodePreserveExactSource(): void
    {
        $title = 'Story';
        $text = str_repeat('Weathered café text and numbers 12345. ', 30)."\n\n".str_repeat('世界', 100)."\n\nEnd.  ";
        $chunks = (new TokenChunker())->chunks($title, $text, ModelTokenCounter::NOMIC, 40);
        self::assertGreaterThan(3, count($chunks));
        $this->assertCoverage($title, $text, $chunks, 40);
    }

    public function testPreviouslyRejectedBillFitsAfterTokenChunking(): void
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/nomic-token-counts.json'), true, flags: JSON_THROW_ON_ERROR);
        $text = $fixtures[2]['text'];
        self::assertSame(2063, $fixtures[2]['tokens']);
        $chunks = (new TokenChunker())->chunks('HB558', $text, ModelTokenCounter::NOMIC, 1800);
        self::assertGreaterThan(1, count($chunks));
        $this->assertCoverage('HB558', $text, $chunks, 1800);
    }

    public function testOpenAiChunksUseTheirOwnBudgetAndPreserveSource(): void
    {
        $text = str_repeat("Café council proceedings, 2026.\n\n", 30);
        $counter = new ModelTokenCounter();
        $chunks = (new TokenChunker($counter))->chunks('Minutes', $text, 'text-embedding-3-small', 32);
        $body = '';
        $end = 0;
        foreach ($chunks as $chunk) {
            self::assertSame($end, $chunk['charStart']);
            self::assertLessThanOrEqual(32, $counter->count($chunk['text'], 'text-embedding-3-small'));
            $body .= mb_substr($text, $chunk['charStart'], $chunk['charEnd'] - $chunk['charStart']);
            $end = $chunk['charEnd'];
        }
        self::assertSame($text, $body);
    }

    public function testUnknownModelCannotSilentlyUseOpenAiTokenizer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ModelTokenCounter())->count('text', 'unknown-model');
    }

    public function testOversizedTitleFailsClearly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TokenChunker())->chunks(str_repeat('title ', 100), 'body', ModelTokenCounter::NOMIC, 20);
    }

    private function assertCoverage(string $title, string $text, array $chunks, int $limit): void
    {
        $counter = new ModelTokenCounter();
        $end = 0;
        $body = '';
        foreach ($chunks as $chunk) {
            self::assertSame($end, $chunk['charStart']);
            $part = mb_substr($text, $chunk['charStart'], $chunk['charEnd'] - $chunk['charStart'], 'UTF-8');
            self::assertSame($title."\n\n".$part, $chunk['text']);
            self::assertSame($counter->count($chunk['text'], $counter::NOMIC), $chunk['tokenCount']);
            self::assertLessThanOrEqual($limit, $chunk['tokenCount']);
            $body .= $part;
            $end = $chunk['charEnd'];
        }
        self::assertSame($text, $body);
    }
}
