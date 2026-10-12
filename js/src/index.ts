import { RuleSet } from './ruleset.js';
import { ruleFiles } from './rules.js';

const SKIPPED_ELEMENTS = new Set(['code', 'pre', 'script', 'style', 'textarea']);
const INLINE_ELEMENTS = new Set([
  'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'data', 'del', 'dfn', 'em', 'i', 'ins', 'kbd',
  'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var',
]);

const ruleSets = new Map<string, RuleSet>();

export function apply(text: string, lang: string): string {
  const rules = ruleSet(lang);
  if (rules === null) {
    console.warn(`wellset: unknown language "${lang}", text left unchanged`);
    return text;
  }

  const length = text.length;
  const replaced: number[] = [];
  let word = '';
  let wordStart = -1;
  let prevWord = '';
  let pendingSpace = -1;

  let i = 0;
  while (i <= length) {
    const code = text.charCodeAt(i);
    const isSpace = code === 0x20;
    let tag: [number, boolean] | null = null;
    let boundaryEnd: number;
    if (i === length || isSpace || code === 0x09 || code === 0x0a || code === 0x0d || code === 0x0c) {
      boundaryEnd = i + 1;
    } else if (code === 0xa0) {
      boundaryEnd = i + 1;
    } else if (code === 0x26 && text.startsWith('&nbsp;', i)) {
      boundaryEnd = i + 6;
    } else if (code === 0x3c && (tag = parseTag(text, i)) !== null && tag[1]) {
      boundaryEnd = tag[0];
    } else {
      if (tag !== null) {
        if (wordStart >= 0) {
          word += text.slice(wordStart, i);
          wordStart = -1;
        }
        i = tag[0];
      } else {
        wordStart = wordStart < 0 ? i : wordStart;
        i++;
      }
      continue;
    }

    if (wordStart >= 0) {
      word += text.slice(wordStart, i);
      wordStart = -1;
    }
    if (pendingSpace >= 0 && rules.matches(prevWord, word)) {
      replaced.push(pendingSpace);
    }
    pendingSpace = isSpace ? i : -1;
    prevWord = word;
    word = '';
    i = boundaryEnd;
  }

  if (replaced.length === 0) {
    return text;
  }

  const parts: string[] = [];
  let from = 0;
  for (const at of replaced) {
    parts.push(text.slice(from, at), ' ');
    from = at + 1;
  }
  parts.push(text.slice(from));

  return parts.join('');
}

/**
 * Returns [offset after the tag, whether it ends a word], or null when "<" starts no tag.
 * A skipped element is consumed together with its content and closing tag.
 */
function parseTag(text: string, at: number): [number, boolean] | null {
  const length = text.length;
  const first = text[at + 1] ?? '';

  if (text.startsWith('<!--', at)) {
    const close = text.indexOf('-->', at + 4);
    return [close < 0 ? length : close + 3, false];
  }
  if (first === '!' || first === '?') {
    return [tagEnd(text, at + 2), false];
  }

  const nameStart = at + (first === '/' ? 2 : 1);
  if (!isLetter(text.charCodeAt(nameStart))) {
    return null;
  }
  let nameEnd = nameStart;
  while (nameEnd < length && isNameChar(text.charCodeAt(nameEnd))) {
    nameEnd++;
  }
  const name = text.slice(nameStart, nameEnd).toLowerCase();
  const end = tagEnd(text, nameEnd);

  if (first !== '/' && SKIPPED_ELEMENTS.has(name)) {
    let search = end;
    let close: number;
    while ((close = text.indexOf('</', search)) >= 0) {
      const after = text[close + 2 + name.length] ?? '>';
      if (text.slice(close + 2, close + 2 + name.length).toLowerCase() === name && '> \t\n\r\f/'.includes(after)) {
        return [tagEnd(text, close + 2), true];
      }
      search = close + 2;
    }
    return [length, true];
  }

  return [end, !INLINE_ELEMENTS.has(name)];
}

function tagEnd(text: string, from: number): number {
  let quote = '';
  for (let i = from; i < text.length; i++) {
    const char = text[i];
    if (quote !== '') {
      quote = char === quote ? '' : quote;
    } else if (char === '"' || char === "'") {
      quote = char;
    } else if (char === '>') {
      return i + 1;
    }
  }

  return text.length;
}

function isLetter(code: number): boolean {
  return (code >= 65 && code <= 90) || (code >= 97 && code <= 122);
}

function isNameChar(code: number): boolean {
  return isLetter(code) || (code >= 48 && code <= 57) || code === 45;
}

function ruleSet(lang: string): RuleSet | null {
  let end = 0;
  while (end < lang.length && lang[end] !== '-' && lang[end] !== '_') {
    end++;
  }
  const code = lang.slice(0, end).toLowerCase();
  const cached = ruleSets.get(code);
  if (cached !== undefined) {
    return cached;
  }

  const data = Object.hasOwn(ruleFiles, code) ? ruleFiles[code] : undefined;
  if (data === undefined) {
    return null;
  }
  const rules = RuleSet.fromJson(data, `rules/${code}.json`);
  ruleSets.set(code, rules);

  return rules;
}
