<?php

declare(strict_types=1);

namespace Miua\Wellset;

final class Wellset
{
    private const LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const DIGITS = '0123456789';
    private const SKIPPED_ELEMENTS = [
        'code' => true, 'iframe' => true, 'noembed' => true, 'noframes' => true, 'plaintext' => true,
        'pre' => true, 'script' => true, 'style' => true, 'textarea' => true, 'xmp' => true,
    ];
    private const INLINE_ELEMENTS = [
        'a' => true, 'abbr' => true, 'acronym' => true, 'b' => true, 'bdi' => true, 'bdo' => true,
        'big' => true, 'cite' => true, 'data' => true, 'del' => true, 'dfn' => true, 'em' => true,
        'font' => true, 'i' => true, 'ins' => true, 'kbd' => true, 'label' => true, 'mark' => true,
        'nobr' => true, 'output' => true, 'q' => true, 'rb' => true, 'ruby' => true, 's' => true,
        'samp' => true, 'small' => true, 'span' => true, 'strike' => true, 'strong' => true,
        'sub' => true, 'sup' => true, 'time' => true, 'tt' => true, 'u' => true, 'var' => true,
        'wbr' => true,
    ];
    private const BOUNDARIES = [' ' => true, "\t" => true, "\n" => true, "\r" => true, "\f" => true, "\u{00A0}" => true];
    private const ENTITIES = [
        'nbsp' => "\u{00A0}", 'amp' => '&', 'quot' => '"', 'apos' => "'", 'bdquo' => '„',
        'ldquo' => '“', 'rdquo' => '”', 'sbquo' => '‚', 'lsquo' => '‘', 'rsquo' => '’',
    ];
    private const LEGACY_ENTITIES = ['nbsp', 'amp', 'quot'];

    /** @var array<string, RuleSet> */
    private static array $ruleSets = [];

    public static function apply(string $text, string $lang, bool $html = false): string
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
        $pendingEnd = -1;
        $stops = $html ? " \t\n\r\f<&\xC2" : " \t\n\r\f\xC2";

        $i = 0;
        while ($i <= $length) {
            $plain = strcspn($text, $stops, $i);
            if ($plain > 0) {
                $wordStart = $wordStart < 0 ? $i : $wordStart;
                $i += $plain;
                continue;
            }

            $char = $text[$i] ?? '';
            $isSpace = $char === ' ';
            $isWhitespace = $isSpace || $char === "\t" || $char === "\n" || $char === "\r" || $char === "\f";
            $entity = null;
            $tag = null;
            if ($i === $length || $isWhitespace) {
                $boundaryEnd = $html && $isWhitespace ? $i + strspn($text, " \t\n\r\f", $i) : $i + 1;
            } elseif ($char === "\xC2" && ($text[$i + 1] ?? '') === "\xA0") {
                $boundaryEnd = $i + 2;
            } elseif ($html && $char === '&' && ($entity = self::entity($text, $i)) !== null && isset(self::BOUNDARIES[$entity[1]])) {
                $boundaryEnd = $entity[0];
            } elseif ($html && $char === '<' && ($tag = self::tag($text, $i)) !== null && $tag[1]) {
                $boundaryEnd = $tag[0];
            } else {
                $skipTo = $entity[0] ?? $tag[0] ?? null;
                if ($skipTo !== null) {
                    if ($wordStart >= 0) {
                        $word .= substr($text, $wordStart, $i - $wordStart);
                        $wordStart = -1;
                    }
                    $word .= $entity[1] ?? '';
                    $i = $skipTo;
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
                $replaced[] = [$pendingSpace, $pendingEnd];
            }
            $pendingSpace = ($html ? $isWhitespace : $isSpace) ? $i : -1;
            $pendingEnd = $boundaryEnd;
            $prevWord = $word;
            $word = '';
            $i = $boundaryEnd;
        }

        if ($replaced === []) {
            return $text;
        }

        $parts = [];
        $from = 0;
        foreach ($replaced as [$at, $end]) {
            $parts[] = substr($text, $from, $at - $from);
            $parts[] = "\u{00A0}";
            $from = $end;
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
            return [self::commentEnd($text, $at + 4), false];
        }
        if (substr_compare($text, '<![CDATA[', $at, 9) === 0) {
            return [self::indexAfter($text, ']]>', $at + 9), false];
        }
        if ($first === '!' || $first === '?' || ($first === '/' && $at + 2 < $length && strspn($text, self::LETTERS, $at + 2, 1) === 0)) {
            return [self::indexAfter($text, '>', $at + 2), false];
        }

        $nameStart = $at + ($first === '/' ? 2 : 1);
        if ($nameStart >= $length || strspn($text, self::LETTERS, $nameStart, 1) === 0) {
            return null;
        }
        $nameLength = strcspn($text, " \t\n\r\f/>", $nameStart);
        $name = strtolower(substr($text, $nameStart, $nameLength));
        $end = self::tagEnd($text, $nameStart + $nameLength);

        if ($first !== '/' && isset(self::SKIPPED_ELEMENTS[$name]) && substr_compare($text, '/>', $end - 2, 2) !== 0) {
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

    /**
     * Returns the offset after the ">" that ends the tag. Quotes count only around an attribute value.
     */
    private static function tagEnd(string $text, int $from): int
    {
        $length = strlen($text);
        $i = $from;
        while ($i < $length) {
            $i += strspn($text, " \t\n\r\f/", $i);
            if ($i >= $length || $text[$i] === '>') {
                break;
            }
            $i++;
            $i += strcspn($text, " \t\n\r\f/>=", $i);
            $i += strspn($text, " \t\n\r\f", $i);
            if (($text[$i] ?? '') !== '=') {
                continue;
            }
            $i++;
            $i += strspn($text, " \t\n\r\f", $i);
            $quote = $text[$i] ?? '';
            if ($quote === '"' || $quote === "'") {
                $i = self::indexAfter($text, $quote, $i + 1);
            } else {
                $i += strcspn($text, " \t\n\r\f>", $i);
            }
        }

        return min($i + 1, $length);
    }

    /**
     * Returns the offset after a comment that started at "<!--" and continues at $from.
     */
    private static function commentEnd(string $text, int $from): int
    {
        if (($text[$from] ?? '') === '>') {
            return $from + 1;
        }
        if (substr_compare($text, '->', $from, 2) === 0) {
            return $from + 2;
        }
        $dashes = $from;
        while (($dashes = strpos($text, '--', $dashes)) !== false) {
            if (($text[$dashes + 2] ?? '') === '>') {
                return $dashes + 3;
            }
            if (substr_compare($text, '!>', $dashes + 2, 2) === 0) {
                return $dashes + 4;
            }
            $dashes++;
        }

        return strlen($text);
    }

    private static function indexAfter(string $text, string $needle, int $from): int
    {
        $at = $from <= strlen($text) ? strpos($text, $needle, $from) : false;
        return $at === false ? strlen($text) : $at + strlen($needle);
    }

    /**
     * Returns [offset after the character reference, decoded text], or null when "&" starts none.
     *
     * @return array{int, string}|null
     */
    private static function entity(string $text, int $at): ?array
    {
        if (($text[$at + 1] ?? '') === '#') {
            $hex = ($text[$at + 2] ?? '') === 'x' || ($text[$at + 2] ?? '') === 'X';
            $digitsStart = $at + ($hex ? 3 : 2);
            $digits = strspn($text, $hex ? self::DIGITS . 'abcdefABCDEF' : self::DIGITS, $digitsStart);
            $end = $digitsStart + $digits;
            if ($digits === 0) {
                return null;
            }
            $significant = ltrim(substr($text, $digitsStart, $digits), '0');
            $value = strlen($significant) > 7 ? 0x110000 : (int) ($hex ? hexdec($significant) : $significant);
            if ($value > 0x10FFFF || ($value >= 0xD800 && $value <= 0xDFFF)) {
                return null;
            }
            return [($text[$end] ?? '') === ';' ? $end + 1 : $end, (string) mb_chr($value, 'UTF-8')];
        }

        $nameEnd = $at + 1 + strspn($text, self::LETTERS, $at + 1);
        $named = ($text[$nameEnd] ?? '') === ';' ? self::ENTITIES[substr($text, $at + 1, $nameEnd - $at - 1)] ?? null : null;
        if ($named !== null) {
            return [$nameEnd + 1, $named];
        }
        foreach (self::LEGACY_ENTITIES as $name) {
            if (substr($text, $at + 1, strlen($name)) === $name) {
                return [$at + 1 + strlen($name), self::ENTITIES[$name]];
            }
        }

        return null;
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
