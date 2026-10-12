import { readdirSync, readFileSync } from 'node:fs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { apply } from '../src/index.js';
import { RuleSet } from '../src/ruleset.js';

interface Case {
  input: string;
  expected: string;
  note: string;
  rules?: string[];
}

const casesDir = new URL('../../cases/', import.meta.url);

for (const file of readdirSync(casesDir).filter((name) => name.endsWith('.json'))) {
  const lang = file.slice(0, -'.json'.length);
  const cases = JSON.parse(readFileSync(new URL(file, casesDir), 'utf8')) as Case[];

  describe(lang, () => {
    it.each(cases.map((c, index) => ({ ...c, index })))('#$index $note', ({ input, expected }) => {
      const output = apply(input, lang);
      expect(output).toBe(expected);
      expect(apply(output, lang)).toBe(output);
    });

    it('has a case for every rule', () => {
      const ruleFile = JSON.parse(readFileSync(new URL(`../../rules/${file}`, import.meta.url), 'utf8')) as {
        rules: { id: string }[];
      };
      const ids = ruleFile.rules.map((rule) => rule.id).sort();
      const covered = [...new Set(cases.flatMap((c) => c.rules ?? []))].sort();
      expect(covered).toEqual(ids);
    });
  });
}

it('has cases for every rule file', () => {
  const langs = (dir: string) =>
    readdirSync(new URL(`../../${dir}/`, import.meta.url))
      .filter((name) => name.endsWith('.json'))
      .sort();

  expect(langs('rules')).not.toEqual([]);
  expect(langs('cases')).toEqual(langs('rules'));
});

describe('invalid rule file', () => {
  const invalid = JSON.parse(readFileSync(new URL('invalid/rules.json', casesDir), 'utf8')) as {
    data: unknown;
    error: string;
    note: string;
  }[];

  it.each(invalid.map((c, index) => ({ ...c, index })))('#$index $note', ({ data, error }) => {
    expect(() => RuleSet.fromJson(data, 'test')).toThrow(error);
  });
});

it('stays linear on long punctuation runs', () => {
  const closing = ')'.repeat(200_000);
  const opening = '('.repeat(200_000);

  expect(apply(`k mostu${closing}`, 'cs')).toBe(`k mostu${closing}`);
  expect(apply(`${opening}k mostu`, 'cs')).toBe(`${opening}k mostu`);
});

describe('language tag', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it.each(['cs', 'CS', 'cs-CZ', 'cs_CZ', 'Cs_cz'])('normalises %s', (lang) => {
    expect(apply('k mostu', lang)).toBe('k mostu');
  });

  it('returns the input unchanged and warns for an unknown language', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

    expect(apply('k mostu', 'xx-YY')).toBe('k mostu');
    expect(apply('k mostu', '__proto__')).toBe('k mostu');
    expect(apply('k mostu', 'xx\nforged')).toBe('k mostu');
    expect(warn).toHaveBeenCalledTimes(3);
    expect(warn.mock.calls[0]?.[0]).toContain('unknown language "xx-YY"');
    expect(warn.mock.calls[2]?.[0]).toContain('unknown language "xx\\nforged"');
  });
});
