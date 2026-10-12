<?php

declare(strict_types=1);

namespace Miua\Wellset\Tests;

use Miua\Wellset\RuleSet;
use Miua\Wellset\Wellset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

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

    public function testEveryRuleFileHasCases(): void
    {
        $langs = static fn (string $dir): array => array_map(
            static fn (string $file): string => basename($file, '.json'),
            glob(__DIR__ . "/../../$dir/*.json") ?: [],
        );

        self::assertNotSame([], $langs('rules'));
        self::assertSame($langs('rules'), $langs('cases'));
    }

    public function testEveryRuleHasACase(): void
    {
        foreach (glob(__DIR__ . '/../../rules/*.json') ?: [] as $file) {
            $lang = basename($file, '.json');
            $ruleFile = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
            $cases = json_decode((string) file_get_contents(__DIR__ . "/../../cases/$lang.json"), true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($ruleFile);
            self::assertIsArray($ruleFile['rules']);
            self::assertIsArray($cases);

            $covered = [];
            foreach ($cases as $case) {
                self::assertIsArray($case);
                $rules = $case['rules'] ?? [];
                self::assertIsArray($rules);
                foreach ($rules as $id) {
                    self::assertIsString($id);
                    $covered[] = $id;
                }
            }
            $covered = array_unique($covered);
            $ids = array_column($ruleFile['rules'], 'id');
            sort($covered);
            sort($ids);

            self::assertSame($ids, $covered, $lang);
        }
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidRules(): iterable
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/../../cases/invalid/rules.json'), false, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($cases);
        foreach ($cases as $index => $case) {
            self::assertInstanceOf(stdClass::class, $case);
            self::assertIsString($case->error);
            self::assertIsString($case->note);
            yield "#$index {$case->note}" => [$case->data, $case->error];
        }
    }

    #[DataProvider('invalidRules')]
    public function testInvalidRuleFileIsRejected(mixed $data, string $error): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wellset');
        self::assertIsString($file);
        file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));

        try {
            RuleSet::fromFile($file);
            self::fail('no exception thrown');
        } catch (UnexpectedValueException $e) {
            self::assertStringContainsString($error, $e->getMessage());
        } finally {
            unlink($file);
        }
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
