<?php

declare(strict_types=1);

namespace Miua\Wellset\Tests;

use Miua\Wellset\Wellset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WellsetTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cases(): iterable
    {
        foreach (glob(__DIR__ . '/../../cases/*.json') ?: [] as $file) {
            $lang = basename($file, '.json');
            $cases = json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($cases);
            foreach ($cases as $index => $case) {
                self::assertIsArray($case);
                self::assertIsString($case['input']);
                self::assertIsString($case['expected']);
                self::assertIsString($case['note']);
                yield "$lang #$index {$case['note']}" => [$lang, $case['input'], $case['expected']];
            }
        }
    }

    #[DataProvider('cases')]
    public function testCase(string $lang, string $input, string $expected): void
    {
        $output = Wellset::apply($input, $lang);

        self::assertSame($expected, $output);
        self::assertSame($output, Wellset::apply($output, $lang), 'apply is not idempotent');
    }

    public function testLanguageTagIsNormalised(): void
    {
        foreach (['cs', 'CS', 'cs-CZ', 'cs_CZ', 'Cs_cz'] as $lang) {
            self::assertSame("k\u{00A0}mostu", Wellset::apply('k mostu', $lang), $lang);
        }
    }

    public function testLongPunctuationRunsStayLinear(): void
    {
        $closing = str_repeat(')', 200_000);
        $opening = str_repeat('(', 200_000);

        self::assertSame("k\u{00A0}mostu$closing", Wellset::apply("k mostu$closing", 'cs'));
        self::assertSame("{$opening}k\u{00A0}mostu", Wellset::apply("{$opening}k mostu", 'cs'));
    }

    public function testUnknownLanguageReturnsInputAndLogs(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'wellset');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);

        try {
            self::assertSame('k mostu', Wellset::apply('k mostu', 'xx-YY'));
            self::assertSame('k mostu', Wellset::apply('k mostu', '../cs'));
            self::assertSame('k mostu', Wellset::apply('k mostu', "xx\nforged"));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $logged = (string) file_get_contents($log);
        unlink($log);
        self::assertStringContainsString('unknown language "xx-YY"', $logged);
        self::assertStringContainsString('unknown language "../cs"', $logged);
        self::assertStringContainsString('unknown language "xx\nforged"', $logged);
    }
}
