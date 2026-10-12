# wellset

Knihovna nahradí běžné mezery (U+0020) pevnými (U+00A0) podle typografických pravidel jazyka. Ostatní text nechá beze změny. Funguje na čistý text i na HTML. Implementace v PHP a v TypeScriptu sdílejí stejná pravidla (`rules/`) i stejné testovací případy (`cases/`).

```php
use Miua\Wellset\Wellset;

Wellset::apply('Jdu k mostu, váží 5 kg.', 'cs-CZ'); // "Jdu k\u{00A0}mostu, váží 5\u{00A0}kg."
```

```ts
import { apply } from 'wellset';

apply('Jdu k mostu, váží 5 kg.', 'cs'); // "Jdu k\u00A0mostu, váží 5\u00A0kg."
```

Knihovna nemá žádné runtime závislosti. PHP potřebuje verzi 8.2+ a rozšíření `mbstring`. JS je ESM a pravidla importuje jako JSON (`with { type: 'json' }`), což zvládá Node 22+, Vite i webpack 5.

## API

`apply(text, lang)` vrátí upravený text.

- `lang` přijímá `cs`, `cs-CZ`, `cs_CZ` v libovolné velikosti písmen. Použije se část před `-` nebo `_`.
- Neznámý jazyk vrátí text beze změny a nevyhodí výjimku. PHP zapíše varování přes `error_log()`, JS přes `console.warn`. Varování přijde při každém volání.
- Když se nic nezmění, dostanete zpět původní řetězec.
- `apply(apply(x)) === apply(x)`. Existující U+00A0 a `&nbsp;` jsou hranice slova.

### Co se v HTML děje

- Tagy, hodnoty atributů, komentáře a entity zůstanou beze změny.
- Obsah `<code>`, `<pre>`, `<script>`, `<style>` a `<textarea>` se přeskočí celý.
- Inline tagy (`a`, `abbr`, `b`, `em`, `span`, `strong`, `sub`, `sup` a další z `INLINE_ELEMENTS`) slovo nepřeruší. Mezera v `5 <abbr>kg</abbr>` se proto změní na U+00A0.
- Každý jiný tag (`p`, `br`, `li`, `td` …) slovo ukončí.
- `<` následované mezerou nebo číslicí je běžný znak, ne tag. `3 < 5` tedy projde jako text.

## Jak pravidla fungují

Slovo je nejdelší úsek bez mezer. Mezeru odděluje tabulátor, konec řádku, U+00A0, `&nbsp;` a blokový tag. Mezera U+0020 se změní na pevnou, když ji splní aspoň jedno pravidlo. Pravidlo je splněné, když platí jeho podmínka `prev` (slovo před mezerou) i `next` (slovo za mezerou). Chybějící podmínka platí vždy.

```json
{ "id": "number-unit", "prev": { "kind": ["number", "decimal"] }, "next": { "words": ["kg", "km", "%"] } }
```

Podmínka má buď `words` (seznam slov, volitelně `"caseInsensitive": true`), nebo `kind` (druh tokenu, řetězec nebo pole). Obojí najednou mít nesmí.

Před porovnáním se z `prev` odstraní úvodní `( [ „ " ‚ '` a z `next` koncové `. , ; : ! ? ) ] “ "`. Seznam `next` proto obsahuje `kg`, ne `kg.`. U `next` se druh tokenu zkouší na očištěném slově a, následuje-li v textu tečka, i na slově s ní. Proto je `6.)` zároveň `number` i `ordinal`.

| `kind` | co rozpozná | příklad |
|---|---|---|
| `number` | jen číslice | `5`, `2024` |
| `digit-group` | právě tři číslice | `000` v `1 000` |
| `ordinal` | číslice a tečka | `1.` |
| `decimal` | číslice, `,` nebo `.`, číslice | `2,5`, `30.2` |
| `initial` | jedno velké písmeno a tečka | `T.`, `Г.` |

Kód rozpozná druhy průchodem po znacích, bez regexu, v PHP i JS stejně (`RuleSet::kinds()`, `kinds()` v `js/src/ruleset.ts`).

Pravidla jazyka se při prvním použití zkompilují do hashovací tabulky `pairs[prevKey][nextKey]` a zůstanou v paměti procesu. Vyhodnocení jedné mezery stojí pár vyhledání v tabulce bez ohledu na počet pravidel. Text se prochází jednou, výstup se skládá po částech a spojí se jednou na konci.

## Pravidla podle jazyků

V příkladech `~` značí pevnou mezeru.

### cs, čeština

Zdroj: Internetová jazyková příručka ÚJČ AV ČR, [Zalomení řádků a nevhodné výrazy na jejich konci](https://prirucka.ujc.cas.cz/?id=880), oddíl Nevhodné výrazy na konci řádků. Text ČSN 01 6910 jsem neměl k dispozici.

| pravidlo | příklad |
|---|---|
| `single-letter-word`: k s v z o u a i | `k~mostu`, `V~Plzni` |
| `digit-group` | `1~000~000` |
| `number-unit`: %, ‰, str., hod., jednotky, měny | `50~%`, `2,5~km`, `250~€` |
| `symbol-number`: § # * † | `§~23` |
| `abbreviation-number`: s. č. obr. tab. | `s.~53`, `č.~9` |
| `noun-number`: strana, tabulka | `strana~2` |
| `date-day-month`, `date-day-month-name` | `21.~6. 2024`, `16.~ledna 1972` |
| `compound-abbreviation-*` | `s.~r.~o.`, `a.~s.`, `př.~n.~l.`, `mn.~č.` |
| `abbreviation-following`: tj. tzv. tzn. | `tzv.~klikání` |
| `initial-surname` | `M.~Těšitelová`, `T.~G.~M.` |
| `title-name` | `Ing.~Ivana Poliaková` |

Příručka u jednotek a titulů jmenuje kategorii a dává příklady (str., hod., m², kg, h, °C, Kč, €; p., mjr., Ing.). Seznamy v `rules/cs.json` doplňují další běžné členy kategorie.

### sk, slovenčina

Zdroj: [STN 01 6910 Pravidlá písania a úpravy písomností, výklad normy (2010)](https://www.stuba.sk/new/docs/stu/pracoviska/rektorat/odd_pravne_organizacne/STN_01_6910_Pravidla_pisania_a_upravy_pisomnosti.pdf), oddiely 3.3, 3.5 a úprava úradných listov.

| pravidlo | příklad |
|---|---|
| `number-unit`: „Značky jednotiek sa píšu vždy v rovnakom riadku za číselnou hodnotou“ | `15~kg`, `10~m/s`, `6,5~km` |
| `date-*`: „Dátum sa nerozdeľuje do dvoch riadkov“ | `6.~januára~2010`, `25.~02.~2009` |

Jednopísmenové predložky zatím neobsahuje, viz Otevřené otázky.

### en, English

Zdroj: New Hart's Rules (OUP 2005) 2.4.5 a 5.10.8; The Chicago Manual of Style, 18. vyd., 6.129 podle [CMOS Q&A](https://www.chicagomanualofstyle.org/qanda/data/faq/topics/WordDivision/faq0006.html); BIPM SI Brochure, 9. vyd., 5.4.3 a 5.4.7; M. Butterick, [Practical Typography](https://practicaltypography.com/nonbreaking-spaces.html).

| pravidlo | příklad |
|---|---|
| `number-unit` (NHR 5.10.8, SI 5.4.3, 5.4.7) | `15~kg`, `30.2~°C`, `10~%` |
| `number-era` (NHR 5.10.8) | `300~BC` |
| `page-line-reference`: p. pp. l. ll. (NHR 2.4.5) | `p.~5` |
| `reference-mark`: § ¶ Fig. Ex. (Butterick) | `Fig.~23` |
| `honorific`: Mr. Mrs. Ms. Dr. Sgt. St. | `Dr.~Smith` |
| `initials` (NHR 2.4.5, CMOS 6.129) | `T.~S. Eliot` |
| `before-middle-initial` (CMOS Q&A) | `John~Q. Public` |
| `name-suffix`: Jr Sr II III IV (CMOS Q&A) | `King~Jr.`, `Henry~IV` |
| `article-pronoun`: a, A, I (NHR 2.4.5, „if possible“) | `a~cat`, `I~think` |

Zdroje jmenují Ms., Sgt., Dr. a St., Mr. a Mrs. doplňují kategorii „honorifics“. Bez mezery psané `5%` zůstane, jak je.

### de, Deutsch

Zdroj: DIN 5008:2020, geschütztes Leerzeichen, podle příkladů v [Stenografenzeitung 2022](https://stenografenzeitung.de/wp-content/uploads/2022/04/DIN-5008___2020.pdf) a na [federwerk.de](https://federwerk.de/geschuetzte-leerzeichen-bitte-nicht-trennen/); Austrian Standards, Fachinformation 23 (ÖNORM EN ISO 80000-1), 3.3, 5.3, 8.1. Původní text DIN 5008 je placený, četl jsem jen tyto sekundární zdroje.

| pravidlo | příklad |
|---|---|
| `number-unit` | `5~%`, `7,25~€`, `9~Uhr`, `120~km` |
| `digit-group` | `50~000 Zuschauer`, `5000 Zuschauer` |
| `ordinal-month` | `1.~April 2022` |
| `paragraph-number`: § §§ | `§~535`, `§§~548` |
| `abbreviation-number`: S. Abs. | `S.~75`, `Abs.~3` |
| `number-following`: f. ff. | `75~f.`, `548~ff.` |
| `dotted-abbreviation-*` | `z.~B.`, `u.~a.`, `u.~Ä.`, `e.~V.` |
| `title-name`: Dr. Prof. | `Prof.~Dr.~Baumann` |
| `company-form`: AG OHG | `Stadtwerke~AG` |

### pl, polski

Zdroj: [Poradnia Językowa PWN](https://sjp.pwn.pl/poradnia): A. Wolański (Sieroty na końcu wiersza, 2018; Odstępy w oznaczeniach jednostek miar, 2022; Reguły zapisu liczb, 2022), M. Bańko (20 km, 2001; Zapis liczb wielocyfrowych, 2011), J. Grzenia (2009); Wielki słownik ortograficzny PWN, zasady pisowni §54.8.

| pravidlo | příklad |
|---|---|
| `single-letter-word`: a i o u w z | `i~pies`, `(W~domu` |
| `number-unit` | `20~km`, `38,4~kg` |
| `digit-group` | `12~500 osób` |
| `multiword-abbreviation` | `dz.~cyt.` |

PWN jednopísmenná slova vyžaduje v nadpisech a v běžném textu je doporučuje. Úzké sloupce povoluje výjimkou. Knihovna je váže vždy. Zdroje jmenují jednotky km a kg, ostatní jednotky v seznamu doplňují kategorii.

### uk, українська

Zdroj: [Український правопис (2019)](https://testportal.gov.ua/wp-content/uploads/2019/09/05062019-onovl-pravo.pdf), § 62 a § 64; Близнюк А. С., [Основні технічні правила набору текстів](https://eprints.zu.edu.ua/7122/), ЖДУ, 2006, розд. 1.2, 1.3.5.

| pravidlo | příklad |
|---|---|
| `one-letter-word`: в у з і й а о (Близнюк 1.2) | `у~ліс` |
| `initial-surname` (§ 64) | `Т.~Г.~Шевченко` |
| `title-name`: гр. акад. доц. проф. п. тов. м. (§ 64, Близнюк) | `проф.~Гончаренко`, `м.~Київ` |
| `number-unit`, `cubic-unit` (§ 64) | `150~га`, `20~куб.~см` |
| `number-year` (§ 64) | `2024~р.` |
| `graphic-abbreviation-*` (§ 64) | `і~т.~д.`, `та~ін.`, `н.~е.` |
| `sign-number`: № § (Близнюк 1.2) | `№~25` |
| `digit-group` (Близнюк 1.2) | `3~245~758` |

## Použití

Funkci můžete volat při uložení obsahu, nebo až při výpisu. Při uložení text už pevné mezery obsahuje a stránka nic nepočítá. Při výpisu stojí `apply` v PHP zhruba 0,2 ms na 1 kB textu a první volání v požadavku navíc načte pravidla jazyka, asi 0,1 ms. Na produkci zapněte `opcache.jit=tracing`, s ním knihovna běží zhruba dvakrát rychleji, viz Benchmark. Opakované volání nevadí, protože `apply` je idempotentní.

### Laravel

```php
use Miua\Wellset\Wellset;

class Article extends Model
{
    protected static function booted(): void
    {
        static::saving(function (Article $article) {
            $article->title = Wellset::apply($article->title, $article->locale);
            $article->body = Wellset::apply($article->body, $article->locale);
        });
    }
}
```

Čistý text vypište přes `{{ }}`. Escapování U+00A0 nezmění. HTML vypište přes `{!! !!}` a jen tehdy, když je obsah sanitizovaný:

```blade
<h1>{{ $article->title }}</h1>
<div>{!! $article->body !!}</div>
```

Neznámý jazyk zapíše jen řádek do logu, `trigger_error()` knihovna nevolá. Laravel by z něj udělal `ErrorException`.

### React

```tsx
import { apply } from 'wellset';

export function Title({ text, lang }: { text: string; lang: string }) {
  return <h1>{apply(text, lang)}</h1>;
}
```

React U+00A0 vykreslí jako pevnou mezeru. Pro HTML z CMS volejte `apply` při ukládání a výsledek vložte přes `dangerouslySetInnerHTML`, opět jen sanitizovaný.

## Přidání jazyka

1. Vytvořte `rules/<lang>.json` s poli `lang`, `source` a `rules`. Do `source` uveďte normu nebo jazykovou příručku.
2. Vytvořte `cases/<lang>.json`: pole objektů `{ "input", "expected", "note", "rules" }`. V nepovinném `rules` uveďte `id` pravidel, která případ ověřuje. Pevnou mezeru zapisujte jako `\u00A0`.
3. Spusťte testy (níže). PHP soubor najde samo, JS ho zaregistruje skript `js/scripts/gen-rules.mjs`, který běží před testy i buildem a generuje `js/src/rules.ts`.

Kód měnit nemusíte, pokud jazyk nepotřebuje nový druh tokenu.

## Přidání pravidla

1. Přidejte objekt do `rules` v `rules/<lang>.json`. `id` musí být v souboru jedinečné.
2. Přidejte případy do `cases/<lang>.json`, kladné i záporné. Aspoň jeden kladný případ musí mít `id` pravidla v poli `rules`, jinak testy selžou.
3. Potřebuje-li pravidlo nový druh tokenu, přidejte ho do `KINDS` a do funkce `kinds` v `php/src/RuleSet.php` i `js/src/ruleset.ts`. Obě implementace musí vracet stejné druhy.

## Vývoj

```sh
composer install && npm install
composer test       # PHPUnit
composer analyse    # PHPStan, level max
npm test            # Vitest
npm run typecheck   # tsc --strict
npm run build       # js/dist
```

PHPUnit i Vitest spouští všechny soubory `cases/*.json` a u každého případu ověří i idempotenci. Ke každému `rules/<lang>.json` musí existovat `cases/<lang>.json` a každé pravidlo musí mít aspoň jeden případ. Soubor `cases/invalid/rules.json` obsahuje vadná pravidla a chybu, kterou na ně obě implementace musí vyhodit.

## Benchmark

`bench/sample.cs.html` je český text s HTML, opakovaný na 100 kB. Spusťte `composer bench` a `npm run bench`.

| prostředí | 100 kB | propustnost |
|---|---|---|
| PHP 8.4.18 | 25,4 ms | 4,1 MB/s |
| PHP 8.4.18, `opcache.jit=tracing` | 14,6 ms | 7,0 MB/s |
| Node 24.13.1 | 8,1 ms | 12,7 MB/s |

Měřeno 12. 10. 2026 na vývojovém notebooku, průměr z 200 běhů. Čas roste lineárně i u patologických vstupů: 800 kB samých závorek nebo inline tagů trvá v PHP osmkrát déle než 100 kB.

## Otevřené otázky

Tato pravidla knihovna nezavádí, protože jsem pro ně nenašel ověřitelný zdroj nebo je formát neumí vyjádřit.

**Formát a tokeny**

- Dvojtečka v poměrech (`5 : 3`, `1 : 50 000`, cs). Z `next` se `:` odstraní a zbyde prázdné slovo.
- Číslo a počítané slovo (`500 lidí`), řadová číslovka a slovo (`5. pluk`), římské číslice (`II. patro`, `Karel IV.`). Chybí druh „libovolné slovo“ a `roman`. Řadovou číslovku před libovolným slovem nelze odlišit od čísla na konci věty.
- Telefonní čísla (`+420 800 123 987`), kódy (`ČSN 01 6910`, `PS PČR`), členění desetinné části (`25,325 23`), cs.
- Zkratky rodných jmen delší než jedno písmeno (`Fr. Daneš`), cs.
- `initial` zachytí i větu končící velkým písmenem (`vitamín C. Další`). U němčiny jsem `initial-surname` vynechal, protože by vázal `z. B. und`.

**Jazyky**

- sk: jednopísmenové predložky k, s, v, z, o, u. Odpověď Jazykovej poradne JÚĽŠ (q/3113) je za captchou a nepřečetl jsem ji. Dále spojky a, i, `%`, měny, `§`, tituly, zkratky před číslem a skupiny číslic: výklad normy jim předepisuje jen mezeru, ne nezlomitelnou. Text STN 01 6910:2023 je placený.
- en: „page 5“, no., vol., ch., datum (`March 5`), skupiny číslic (CMOS chce U+202F), výčty `(1) word`.
- de: Initiale a příjmení (`E. Olsen`), měsíc a rok (`April 2022`, zdroj připouští zlom), `d. h.`, `i. A.`, `Nr.`, `Bd.`, `Herr` nebo `Frau` a jméno, `DIN A4`, `Bad Saarow`, úzká mezera U+202F.
- pl: `%` (PWN uvádí obě varianty), `zł`, `s.`, `nr`, `art.`, `ust.`, `§` a číslo, tituly, data, iniciály.
- uk: `грн`, `коп`, `тис.`, `млн`, zkratky před číslem (`с. 5`, `ст. 15`), `у т. ч.`, `т. зв.`, rozsahy (`1990–1991 рр.`), `%`.
