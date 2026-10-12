import { readdirSync, readFileSync } from 'node:fs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { apply } from '../src/index.js';

interface Case {
  input: string;
  expected: string;
  note: string;
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
  });
}

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
    expect(warn).toHaveBeenCalledTimes(2);
    expect(warn.mock.calls[0]?.[0]).toContain('unknown language "xx-YY"');
  });
});
