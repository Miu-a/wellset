const KINDS = new Set(['number', 'ordinal', 'decimal', 'digit-group', 'initial', 'initials']);
const PREV_LEADING = ['(', '[', '„', '"', '‚', "'"];
const NEXT_TRAILING = ['.', ',', ';', ':', '!', '?', ')', ']', '“', '"'];

interface Uses {
  w: boolean;
  i: boolean;
  k: boolean;
  any: boolean;
}

/**
 * Rules of one language compiled into hash tables.
 *
 * A rule is stored as pairs[prevKey][nextKey], where a key is "w:<word>",
 * "i:<lowercased word>", "k:<kind>" or "*" for a side without a condition.
 * Matching one space costs a handful of lookups, whatever the number of rules.
 */
export class RuleSet {
  private readonly pairs = new Map<string, Set<string>>();
  private readonly prevUses: Uses = { w: false, i: false, k: false, any: false };
  private readonly nextUses: Uses = { w: false, i: false, k: false, any: false };

  static fromJson(data: unknown, where: string): RuleSet {
    if (!isObject(data) || !Array.isArray(data.rules)) {
      throw new Error(`${where}: missing "rules" array`);
    }

    const set = new RuleSet();
    for (const rule of data.rules) {
      if (!isObject(rule) || typeof rule.id !== 'string') {
        throw new Error(`${where}: every rule needs a string "id"`);
      }
      set.add(rule, `${where}#${rule.id}`);
    }

    return set;
  }

  matches(prev: string, next: string): boolean {
    prev = prev.slice(leadingEnd(prev));
    if (prev === '') {
      return false;
    }

    const rows: Set<string>[] = [];
    for (const prevKey of keys(prev, [prev], this.prevUses)) {
      const nextSet = this.pairs.get(prevKey);
      if (nextSet !== undefined) {
        rows.push(nextSet);
      }
    }
    if (rows.length === 0) {
      return false;
    }

    const nextEnd = trailingStart(next) || next.length;
    const nextKindSources =
      next[nextEnd] === '.' ? [next.slice(0, nextEnd), next.slice(0, nextEnd + 1)] : [next.slice(0, nextEnd)];
    next = nextKindSources[0]!;
    if (next === '') {
      return false;
    }

    for (const nextKey of keys(next, nextKindSources, this.nextUses)) {
      for (const row of rows) {
        if (row.has(nextKey)) {
          return true;
        }
      }
    }

    return false;
  }

  private add(rule: Record<string, unknown>, where: string): void {
    if (rule.prev === undefined && rule.next === undefined) {
      throw new Error(`${where}: needs "prev" or "next"`);
    }

    const prevKeys = conditionKeys(rule.prev, this.prevUses, where);
    const nextKeys = conditionKeys(rule.next, this.nextUses, where);
    for (const prevKey of prevKeys) {
      let nextSet = this.pairs.get(prevKey);
      if (nextSet === undefined) {
        nextSet = new Set();
        this.pairs.set(prevKey, nextSet);
      }
      for (const nextKey of nextKeys) {
        nextSet.add(nextKey);
      }
    }
  }
}

function conditionKeys(condition: unknown, uses: Uses, where: string): string[] {
  if (condition === undefined) {
    uses.any = true;
    return ['*'];
  }
  if (!isObject(condition) || (condition.words === undefined) === (condition.kind === undefined)) {
    throw new Error(`${where}: a condition needs exactly one of "words" or "kind"`);
  }

  if (condition.kind !== undefined) {
    const kinds: unknown[] = Array.isArray(condition.kind) ? condition.kind : [condition.kind];
    const result = kinds.map((tokenKind) => {
      if (typeof tokenKind !== 'string' || !KINDS.has(tokenKind)) {
        throw new Error(`${where}: unknown kind`);
      }
      return `k:${tokenKind}`;
    });
    uses.k = true;
    return result;
  }

  if (!Array.isArray(condition.words) || condition.words.length === 0) {
    throw new Error(`${where}: "words" must be a non-empty array`);
  }
  const caseInsensitive = condition.caseInsensitive === true;
  uses[caseInsensitive ? 'i' : 'w'] = true;

  return condition.words.map((word: unknown) => {
    if (typeof word !== 'string' || word === '') {
      throw new Error(`${where}: words must be non-empty strings`);
    }
    return caseInsensitive ? `i:${word.toLowerCase()}` : `w:${word}`;
  });
}

function keys(word: string, kindSources: string[], uses: Uses): string[] {
  const result: string[] = [];
  if (uses.w) {
    result.push(`w:${word}`);
  }
  if (uses.i) {
    result.push(`i:${word.toLowerCase()}`);
  }
  if (uses.k) {
    for (const source of kindSources) {
      for (const tokenKind of kinds(source)) {
        result.push(`k:${tokenKind}`);
      }
    }
  }
  if (uses.any) {
    result.push('*');
  }

  return result;
}

function kinds(word: string): string[] {
  const length = word.length;
  const digits = digitRun(word, 0);
  if (digits === length) {
    return length === 3 ? ['number', 'digit-group'] : ['number'];
  }
  if (digits > 0) {
    const separator = word[digits];
    if (separator === '.' && digits === length - 1) {
      return ['ordinal'];
    }
    const fraction = length - digits - 1;
    if ((separator === ',' || separator === '.') && fraction > 0 && digitRun(word, digits + 1) === fraction) {
      return ['decimal'];
    }
    return [];
  }
  const result: string[] = [];
  if (length >= 2 && word.endsWith('.')) {
    const letter = word.slice(0, -1);
    if (letter.length <= 2 && [...letter].length === 1 && letter.toLowerCase() !== letter) {
      result.push('initial');
    }
    if (isInitials(word)) {
      result.push('initials');
    }
  }

  return result;
}

function isInitials(word: string): boolean {
  const chars = [...word];
  if (chars.length < 4 || chars.length % 2 !== 0) {
    return false;
  }
  for (let at = 0; at < chars.length; at += 2) {
    const letter = chars[at]!;
    if (chars[at + 1] !== '.' || letter.toLowerCase() === letter) {
      return false;
    }
  }
  return true;
}

function digitRun(word: string, from: number): number {
  let end = from;
  while (end < word.length && word.charCodeAt(end) >= 48 && word.charCodeAt(end) <= 57) {
    end++;
  }
  return end - from;
}

function leadingEnd(word: string): number {
  let at = 0;
  let before: number;
  do {
    before = at;
    for (const mark of PREV_LEADING) {
      if (word.startsWith(mark, at)) {
        at += mark.length;
      }
    }
  } while (at !== before);

  return at;
}

function trailingStart(word: string): number {
  let end = word.length;
  let before: number;
  do {
    before = end;
    for (const mark of NEXT_TRAILING) {
      if (word.endsWith(mark, end)) {
        end -= mark.length;
        break;
      }
    }
  } while (end !== before);

  return end;
}

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
