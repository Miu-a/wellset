import { RuleSet } from './ruleset.js';
import { ruleFiles } from './rules.js';

const SKIPPED_ELEMENTS = new Set([
  'code', 'iframe', 'noembed', 'noframes', 'plaintext', 'pre', 'script', 'style', 'textarea', 'xmp',
]);
const INLINE_ELEMENTS = new Set([
  'a', 'abbr', 'acronym', 'b', 'bdi', 'bdo', 'big', 'cite', 'data', 'del', 'dfn', 'em', 'font',
  'i', 'ins', 'kbd', 'label', 'mark', 'nobr', 'output', 'q', 'rb', 'ruby', 's', 'samp', 'small',
  'span', 'strike', 'strong', 'sub', 'sup', 'time', 'tt', 'u', 'var', 'wbr',
]);
const BOUNDARIES = new Set([' ', '\t', '\n', '\r', '\f', '\u00A0']);
const ENTITIES = new Map([
  ['nbsp', '\u00A0'], ['amp', '&'], ['quot', '"'], ['apos', "'"], ['bdquo', '„'],
  ['ldquo', '“'], ['rdquo', '”'], ['sbquo', '‚'], ['lsquo', '‘'], ['rsquo', '’'],
]);
const LEGACY_ENTITIES = ['nbsp', 'amp', 'quot'];

const ruleSets = new Map<string, RuleSet>();

export function apply(text: string, lang: string, { html = false }: { html?: boolean } = {}): string {
  const rules = ruleSet(lang);
  if (rules === null) {
    console.warn(`wellset: unknown language ${JSON.stringify(lang)}, text left unchanged`);
    return text;
  }

  const length = text.length;
  const replaced: [number, number][] = [];
  let word = '';
  let wordStart = -1;
  let prevWord = '';
  let pendingSpace = -1;
  let pendingEnd = -1;

  let i = 0;
  while (i <= length) {
    const code = text.charCodeAt(i);
    const isSpace = code === 0x20;
    const isWhitespace = isSpace || code === 0x09 || code === 0x0a || code === 0x0d || code === 0x0c;
    let entity: [number, string] | null = null;
    let tag: [number, boolean] | null = null;
    let boundaryEnd: number;
    if (i === length || isWhitespace) {
      boundaryEnd = i + 1;
      while (html && isWhitespace && boundaryEnd < length && ' \t\n\r\f'.includes(text[boundaryEnd]!)) {
        boundaryEnd++;
      }
    } else if (code === 0xa0) {
      boundaryEnd = i + 1;
    } else if (html && code === 0x26 && (entity = parseEntity(text, i)) !== null && BOUNDARIES.has(entity[1])) {
      boundaryEnd = entity[0];
    } else if (html && code === 0x3c && (tag = parseTag(text, i)) !== null && tag[1]) {
      boundaryEnd = tag[0];
    } else {
      const skipTo = entity?.[0] ?? tag?.[0];
      if (skipTo !== undefined) {
        if (wordStart >= 0) {
          word += text.slice(wordStart, i);
          wordStart = -1;
        }
        word += entity?.[1] ?? '';
        i = skipTo;
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
      replaced.push([pendingSpace, pendingEnd]);
    }
    pendingSpace = (html ? isWhitespace : isSpace) ? i : -1;
    pendingEnd = boundaryEnd;
    prevWord = word;
    word = '';
    i = boundaryEnd;
  }

  if (replaced.length === 0) {
    return text;
  }

  const parts: string[] = [];
  let from = 0;
  for (const [at, end] of replaced) {
    parts.push(text.slice(from, at), '\u00A0');
    from = end;
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
    return [commentEnd(text, at + 4), false];
  }
  if (text.startsWith('<![CDATA[', at)) {
    return [indexAfter(text, ']]>', at + 9), false];
  }
  if (first === '!' || first === '?' || (first === '/' && at + 2 < length && !isLetter(text.charCodeAt(at + 2)))) {
    return [indexAfter(text, '>', at + 2), false];
  }

  const nameStart = at + (first === '/' ? 2 : 1);
  if (!isLetter(text.charCodeAt(nameStart))) {
    return null;
  }
  let nameEnd = nameStart;
  while (nameEnd < length && !' \t\n\r\f/>'.includes(text[nameEnd]!)) {
    nameEnd++;
  }
  const name = text.slice(nameStart, nameEnd).toLowerCase();
  const end = tagEnd(text, nameEnd);

  if (first !== '/' && SKIPPED_ELEMENTS.has(name) && !text.startsWith('/>', end - 2)) {
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

/**
 * Returns the offset after the ">" that ends the tag. Quotes count only around an attribute value.
 */
function tagEnd(text: string, from: number): number {
  const length = text.length;
  let i = from;
  while (i < length) {
    while (i < length && ' \t\n\r\f/'.includes(text[i]!)) {
      i++;
    }
    if (i >= length || text[i] === '>') {
      break;
    }
    i++;
    while (i < length && !' \t\n\r\f/>='.includes(text[i]!)) {
      i++;
    }
    while (i < length && ' \t\n\r\f'.includes(text[i]!)) {
      i++;
    }
    if (text[i] !== '=') {
      continue;
    }
    i++;
    while (i < length && ' \t\n\r\f'.includes(text[i]!)) {
      i++;
    }
    const quote = text[i];
    if (quote === '"' || quote === "'") {
      i = indexAfter(text, quote, i + 1);
    } else {
      while (i < length && !' \t\n\r\f>'.includes(text[i]!)) {
        i++;
      }
    }
  }

  return Math.min(i + 1, length);
}

/**
 * Returns the offset after a comment that started at "<!--" and continues at from.
 */
function commentEnd(text: string, from: number): number {
  if (text[from] === '>') {
    return from + 1;
  }
  if (text.startsWith('->', from)) {
    return from + 2;
  }
  let dashes = from;
  while ((dashes = text.indexOf('--', dashes)) >= 0) {
    if (text[dashes + 2] === '>') {
      return dashes + 3;
    }
    if (text.startsWith('!>', dashes + 2)) {
      return dashes + 4;
    }
    dashes++;
  }

  return text.length;
}

function indexAfter(text: string, needle: string, from: number): number {
  const at = text.indexOf(needle, from);
  return at < 0 ? text.length : at + needle.length;
}

/**
 * Returns [offset after the character reference, decoded text], or null when "&" starts none.
 */
function parseEntity(text: string, at: number): [number, string] | null {
  const length = text.length;
  if (text[at + 1] === '#') {
    const hex = text[at + 2] === 'x' || text[at + 2] === 'X';
    const digitsStart = at + (hex ? 3 : 2);
    let end = digitsStart;
    let value = 0;
    while (end < length) {
      const digit = digitValue(text.charCodeAt(end), hex);
      if (digit < 0) {
        break;
      }
      value = Math.min(value * (hex ? 16 : 10) + digit, 0x110000);
      end++;
    }
    if (end === digitsStart || value > 0x10ffff || (value >= 0xd800 && value <= 0xdfff)) {
      return null;
    }
    return [text[end] === ';' ? end + 1 : end, String.fromCodePoint(value)];
  }

  let nameEnd = at + 1;
  while (nameEnd < length && isLetter(text.charCodeAt(nameEnd))) {
    nameEnd++;
  }
  const named = text[nameEnd] === ';' ? ENTITIES.get(text.slice(at + 1, nameEnd)) : undefined;
  if (named !== undefined) {
    return [nameEnd + 1, named];
  }
  const legacy = LEGACY_ENTITIES.find((name) => text.startsWith(name, at + 1));

  return legacy === undefined ? null : [at + 1 + legacy.length, ENTITIES.get(legacy)!];
}

function digitValue(code: number, hex: boolean): number {
  if (code >= 48 && code <= 57) {
    return code - 48;
  }
  const lower = code | 0x20;
  return hex && lower >= 97 && lower <= 102 ? lower - 87 : -1;
}

function isLetter(code: number): boolean {
  return (code >= 65 && code <= 90) || (code >= 97 && code <= 122);
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
