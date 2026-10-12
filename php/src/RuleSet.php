<?php

declare(strict_types=1);

namespace Miua\Wellset;

use stdClass;
use UnexpectedValueException;

/**
 * Rules of one language compiled into hash tables.
 *
 * A rule is stored as $pairs[prevKey][nextKey], where a key is "w:<word>",
 * "i:<lowercased word>", "k:<kind>" or "*" for a side without a condition.
 * Matching one space costs a handful of lookups, whatever the number of rules.
 */
final class RuleSet
{
    private const KINDS = ['number', 'ordinal', 'decimal', 'digit-group', 'initial'];
    private const DIGITS = '0123456789';
    private const PREV_LEADING = ['(', '[', '„', '"', '‚', "'"];
    private const NEXT_TRAILING = ['.', ',', ';', ':', '!', '?', ')', ']', '“', '"'];

    /** @var array<string, array<string, true>> */
    private array $pairs = [];

    /** @var array{w: bool, i: bool, k: bool, any: bool} */
    private array $prevUses = ['w' => false, 'i' => false, 'k' => false, 'any' => false];

    /** @var array{w: bool, i: bool, k: bool, any: bool} */
    private array $nextUses = ['w' => false, 'i' => false, 'k' => false, 'any' => false];

    public static function fromFile(string $path): self
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new UnexpectedValueException("Cannot read $path");
        }

        $data = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        if (!$data instanceof stdClass || !isset($data->rules) || !is_array($data->rules)) {
            throw new UnexpectedValueException("$path: missing \"rules\" array");
        }

        $set = new self();
        foreach ($data->rules as $rule) {
            if (!$rule instanceof stdClass || !isset($rule->id) || !is_string($rule->id)) {
                throw new UnexpectedValueException("$path: every rule needs a string \"id\"");
            }
            $set->add($rule, "$path#{$rule->id}");
        }

        return $set;
    }

    public function matches(string $prev, string $next): bool
    {
        $prev = substr($prev, self::leadingEnd($prev));
        if ($prev === '') {
            return false;
        }

        $rows = [];
        foreach (self::keys($prev, [$prev], $this->prevUses) as $prevKey) {
            if (isset($this->pairs[$prevKey])) {
                $rows[] = $this->pairs[$prevKey];
            }
        }
        if ($rows === []) {
            return false;
        }

        $nextEnd = self::trailingStart($next);
        $nextKindSources = ($next[$nextEnd] ?? '') === '.'
            ? [substr($next, 0, $nextEnd), substr($next, 0, $nextEnd + 1)]
            : [substr($next, 0, $nextEnd)];
        if ($nextKindSources[0] === '') {
            return false;
        }

        foreach (self::keys($nextKindSources[0], $nextKindSources, $this->nextUses) as $nextKey) {
            foreach ($rows as $row) {
                if (isset($row[$nextKey])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function add(stdClass $rule, string $where): void
    {
        if (!property_exists($rule, 'prev') && !property_exists($rule, 'next')) {
            throw new UnexpectedValueException("$where: needs \"prev\" or \"next\"");
        }

        $prevKeys = self::conditionKeys($rule, 'prev', $this->prevUses, $where);
        $nextKeys = self::conditionKeys($rule, 'next', $this->nextUses, $where);
        foreach ($prevKeys as $prevKey) {
            foreach ($nextKeys as $nextKey) {
                $this->pairs[$prevKey][$nextKey] = true;
            }
        }
    }

    /**
     * @param array{w: bool, i: bool, k: bool, any: bool} $uses
     * @return list<string>
     */
    private static function conditionKeys(stdClass $rule, string $side, array &$uses, string $where): array
    {
        if (!property_exists($rule, $side)) {
            $uses['any'] = true;
            return ['*'];
        }
        $condition = $rule->$side;
        if (!$condition instanceof stdClass || property_exists($condition, 'words') === property_exists($condition, 'kind')) {
            throw new UnexpectedValueException("$where: a condition needs exactly one of \"words\" or \"kind\"");
        }

        if (property_exists($condition, 'kind')) {
            $keys = [];
            foreach (is_array($condition->kind) ? $condition->kind : [$condition->kind] as $kind) {
                if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
                    throw new UnexpectedValueException("$where: unknown kind");
                }
                $keys[] = 'k:' . $kind;
            }
            $uses['k'] = true;
            return $keys;
        }

        if (!is_array($condition->words) || $condition->words === []) {
            throw new UnexpectedValueException("$where: \"words\" must be a non-empty array");
        }
        $caseInsensitive = ($condition->caseInsensitive ?? false) === true;
        $uses[$caseInsensitive ? 'i' : 'w'] = true;
        $keys = [];
        foreach ($condition->words as $word) {
            if (!is_string($word) || $word === '') {
                throw new UnexpectedValueException("$where: words must be non-empty strings");
            }
            $keys[] = $caseInsensitive ? 'i:' . mb_strtolower($word, 'UTF-8') : 'w:' . $word;
        }

        return $keys;
    }

    /**
     * @param list<string> $kindSources
     * @param array{w: bool, i: bool, k: bool, any: bool} $uses
     * @return list<string>
     */
    private static function keys(string $word, array $kindSources, array $uses): array
    {
        $keys = [];
        if ($uses['w']) {
            $keys[] = 'w:' . $word;
        }
        if ($uses['i']) {
            $keys[] = 'i:' . mb_strtolower($word, 'UTF-8');
        }
        if ($uses['k']) {
            foreach ($kindSources as $source) {
                foreach (self::kinds($source) as $kind) {
                    $keys[] = 'k:' . $kind;
                }
            }
        }
        if ($uses['any']) {
            $keys[] = '*';
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function kinds(string $word): array
    {
        $length = strlen($word);
        $digits = strspn($word, self::DIGITS);
        if ($digits === $length) {
            return $length === 3 ? ['number', 'digit-group'] : ['number'];
        }
        if ($digits > 0) {
            $separator = $word[$digits];
            if ($separator === '.' && $digits === $length - 1) {
                return ['ordinal'];
            }
            $fraction = $length - $digits - 1;
            if (($separator === ',' || $separator === '.') && $fraction > 0 && strspn($word, self::DIGITS, $digits + 1) === $fraction) {
                return ['decimal'];
            }
            return [];
        }
        if ($length >= 2 && $length <= 5 && $word[$length - 1] === '.') {
            $letter = substr($word, 0, -1);
            if (mb_strlen($letter, 'UTF-8') === 1 && mb_strtolower($letter, 'UTF-8') !== $letter) {
                return ['initial'];
            }
        }

        return [];
    }

    private static function leadingEnd(string $word): int
    {
        if (strspn($word, "([\"'\xE2", 0, 1) === 0) {
            return 0;
        }

        $at = 0;
        do {
            $before = $at;
            foreach (self::PREV_LEADING as $mark) {
                if (substr_compare($word, $mark, $at, strlen($mark)) === 0) {
                    $at += strlen($mark);
                }
            }
        } while ($at !== $before);

        return $at;
    }

    private static function trailingStart(string $word): int
    {
        $end = strlen($word);
        if ($end === 0 || strspn($word, ".,;:!?)]\"\x9C", $end - 1) === 0) {
            return $end;
        }

        do {
            $before = $end;
            foreach (self::NEXT_TRAILING as $mark) {
                $length = strlen($mark);
                if ($end >= $length && substr_compare($word, $mark, $end - $length, $length) === 0) {
                    $end -= $length;
                    break;
                }
            }
        } while ($end !== $before);

        return $end;
    }
}
