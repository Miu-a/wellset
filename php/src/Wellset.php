<?php

declare(strict_types=1);

namespace Miua\Wellset;

final class Wellset
{
    private const LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const SKIPPED_ELEMENTS = ['code' => true, 'pre' => true, 'script' => true, 'style' => true, 'textarea' => true];
    private const INLINE_ELEMENTS = [
        'a' => true, 'abbr' => true, 'b' => true, 'bdi' => true, 'bdo' => true, 'cite' => true,
        'data' => true, 'del' => true, 'dfn' => true, 'em' => true, 'i' => true, 'ins' => true,
        'kbd' => true, 'mark' => true, 'q' => true, 's' => true, 'samp' => true, 'small' => true,
        'span' => true, 'strong' => true, 'sub' => true, 'sup' => true, 'time' => true, 'u' => true,
        'var' => true,
    ];

    /** @var array<string, RuleSet> */
    private static array $ruleSets = [];

    public static function apply(string $text, string $lang): string
    {
        $rules = self::ruleSet($lang);
        if ($rules === null) {
            $quoted = json_encode($lang, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            error_log("wellset: unknown language $quoted, text left unchanged");
            return $text;
        }

        $length = strlen($text);
        $replaced = [];
        $word = '';
        $wordStart = -1;
        $prevWord = '';
        $pendingSpace = -1;

        $i = 0;
        while ($i <= $length) {
            $plain = strcspn($text, " \t\n\r\f<&\xC2", $i);
            if ($plain > 0) {
                $wordStart = $wordStart < 0 ? $i : $wordStart;
                $i += $plain;
                continue;
            }

            $char = $text[$i] ?? '';
            $isSpace = $char === ' ';
            $tag = null;
            if ($i === $length || $isSpace || $char === "\t" || $char === "\n" || $char === "\r" || $char === "\f") {
                $boundaryEnd = $i + 1;
            } elseif ($char === "\xC2" && ($text[$i + 1] ?? '') === "\xA0") {
                $boundaryEnd = $i + 2;
            } elseif ($char === '&' && substr_compare($text, '&nbsp;', $i, 6) === 0) {
                $boundaryEnd = $i + 6;
            } elseif ($char === '<' && ($tag = self::tag($text, $i)) !== null && $tag[1]) {
                $boundaryEnd = $tag[0];
            } else {
                if ($tag !== null) {
                    if ($wordStart >= 0) {
                        $word .= substr($text, $wordStart, $i - $wordStart);
                        $wordStart = -1;
                    }
                    $i = $tag[0];
                } else {
                    $wordStart = $wordStart < 0 ? $i : $wordStart;
                    $i++;
                }
                continue;
            }

            if ($wordStart >= 0) {
                $word .= substr($text, $wordStart, $i - $wordStart);
                $wordStart = -1;
            }
            if ($pendingSpace >= 0 && $rules->matches($prevWord, $word)) {
                $replaced[] = $pendingSpace;
            }
            $pendingSpace = $isSpace ? $i : -1;
            $prevWord = $word;
            $word = '';
            $i = $boundaryEnd;
        }

        if ($replaced === []) {
            return $text;
        }

        $parts = [];
        $from = 0;
        foreach ($replaced as $at) {
            $parts[] = substr($text, $from, $at - $from);
            $parts[] = "\u{00A0}";
            $from = $at + 1;
        }
        $parts[] = substr($text, $from);

        return implode('', $parts);
    }

    /**
     * Returns [offset after the tag, whether it ends a word], or null when "<" starts no tag.
     * A skipped element is consumed together with its content and closing tag.
     *
     * @return array{int, bool}|null
     */
    private static function tag(string $text, int $at): ?array
    {
        $length = strlen($text);
        $first = $text[$at + 1] ?? '';

        if (substr_compare($text, '<!--', $at, 4) === 0) {
            $close = strpos($text, '-->', $at + 4);
            return [$close === false ? $length : $close + 3, false];
        }
        if ($first === '!' || $first === '?') {
            return [self::tagEnd($text, $at + 2), false];
        }

        $nameStart = $at + ($first === '/' ? 2 : 1);
        if (strspn($text, self::LETTERS, $nameStart, 1) === 0) {
            return null;
        }
        $name = strtolower(substr($text, $nameStart, strspn($text, self::LETTERS . '0123456789-', $nameStart)));
        $end = self::tagEnd($text, $nameStart + strlen($name));

        if ($first !== '/' && isset(self::SKIPPED_ELEMENTS[$name])) {
            $search = $end;
            while (($close = stripos($text, "</$name", $search)) !== false) {
                $after = $text[$close + 2 + strlen($name)] ?? '>';
                if (str_contains("> \t\n\r\f/", $after)) {
                    return [self::tagEnd($text, $close + 2), true];
                }
                $search = $close + 2;
            }
            return [$length, true];
        }

        return [$end, !isset(self::INLINE_ELEMENTS[$name])];
    }

    private static function tagEnd(string $text, int $from): int
    {
        $length = strlen($text);
        $quote = '';
        for ($i = $from; $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== '') {
                $quote = $char === $quote ? '' : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                return $i + 1;
            }
        }

        return $length;
    }

    private static function ruleSet(string $lang): ?RuleSet
    {
        $code = strtolower(substr($lang, 0, strcspn($lang, '-_')));
        if (isset(self::$ruleSets[$code])) {
            return self::$ruleSets[$code];
        }

        $path = __DIR__ . "/../../rules/$code.json";
        if ($code === '' || strspn($code, self::LETTERS) !== strlen($code) || !is_file($path)) {
            return null;
        }

        return self::$ruleSets[$code] = RuleSet::fromFile($path);
    }
}
