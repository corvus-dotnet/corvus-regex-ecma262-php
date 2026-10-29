# Getting started with PHP

## Introduction

`corvus_regex_ecma262` is a PHP extension whose patterns answer as ECMAScript does. Where PHP's `preg_` functions use
PCRE's grammar and its meaning for `\d`, `\w`, `\s`, `.`, case folding and Unicode properties, this extension has
ECMA-262's, so a pattern written for JavaScript, or taken from a JSON Schema, matches the same texts here as it does
there. It is the corvus-regex-ecma262 Rust crate, held to V8's answers on the ECMA-262 test data.

This guide covers:

- **Setup**: installing the extension with PIE.
- **Patterns and flags**: the flags are ECMA-262's letters, and what each one does.
- **The edition and the version of Unicode**: the grammar of ECMAScript 2020, and the Unicode tables a pattern reads.
- **Matching**: `test`, `exec`, `matchAll`, `replace` and `split`, as `RegExp` and `String.prototype` define them.
- **Timeouts**: the limit of time and of memory a match may take.
- **UTF-8 and UTF-16**: strings as UTF-8, and `Utf16` text with JavaScript's positions.
- **Differences from PCRE**: what to expect when the two meet.

## Setup

```
pie install corvus-dotnet/corvus-regex-ecma262
```

[PIE](https://github.com/php/pie) downloads the build for the PHP it installs into, and enables the extension. There
are builds for PHP 8.2 to 8.5, NTS and ZTS, on Linux (glibc and musl, x86_64 and arm64), macOS (arm64) and Windows
(x86_64).

```php
use Corvus\RegexEcma262\{MatchResult, Regex, Utf16};
```

## Patterns and flags

A pattern is written as it would be in JavaScript, and the flags are the letters `RegExp` takes:

| Flag | Meaning |
|---|---|
| `d` | Accepted for completeness; indices are always kept. |
| `g` | `replace` replaces every match; without it, the first, as `String.prototype.replace` does. |
| `i` | Canonicalize of the mode in force: simple case folding with `u` or `v`, `toUpperCase` without. Never the locale. |
| `m` | `^` and `$` at line terminators. |
| `s` | The dot matches line terminators. |
| `u` | The `u` flag grammar; matched by code point. |
| `v` | Set notation and properties of strings; matched by code point. `u` and `v` together is an error, as in ECMA-262. |
| `y` | A match only at the start position. |

```php
use Corvus\RegexEcma262\Regex;

$a = new Regex('\p{Script=Greek}+', 'u');
$b = new Regex('[[a-z]--[aeiou]]+', 'v');
$c = new Regex('^(?<year>\d{4})-(?<month>\d{2})$', 'u');

assert($c->groupNames() === ['year', 'month']);
assert($b->test('rhythm'));
```

With no flag the pattern is read with the Annex B grammar and matched by UTF-16 code unit, which is what ECMA-262 does
with no flag. JSON Schema's reading, the `u` flag first and Annex B when that refuses, is two compiles, each with the
match timeout, so no default timeout is needed:

```php
use Corvus\RegexEcma262\{InvalidPatternException, Regex};

function schema_pattern(string $pattern): Regex
{
    try {
        return new Regex($pattern, 'u', 1.0);
    } catch (InvalidPatternException) {
        return new Regex($pattern, '', 1.0);
    }
}

assert(schema_pattern('^\p{L}+$')->flags() === 'u');
assert(schema_pattern('\-')->flags() === '');  // an identity escape, which only Annex B allows
```

A pattern that is not valid throws `InvalidPatternException`, with the reason, (or `InvalidFlagsException`), each a `RegexException`.
Every pattern has deterministic limits of size, and `PatternTooLargeException` says when one is passed.

## The edition and the version of Unicode

By default a pattern is read with today's grammar, ECMAScript 2025 (`edition: 'current'`), and with the tables of the
version of Unicode that V8 carries, 17 today (`unicode_version: null`). Either can be overridden when the pattern is
compiled, each independently of the other.

JSON Schema 2020-12 names the grammar of ECMAScript 2020, which `edition: 'es2020'` selects in place of the default. It
refuses what later editions added, and nothing else: the `d` and `v` flags, modifier groups such as `(?i:...)`, a group
name used by two groups, and a property name or value that is not in the 2020 tables (such as `\p{Script=Yezidi}`). A
pattern it accepts has the same answers as in today's grammar.

The version of Unicode is chosen apart from the edition: `unicode_version:` 13, 17, 18, `'latest'` (18 today) or
`null`, the version of the V8 the answers are held to (17 today). It decides the members of each `\p{...}`, the
characters of a group name and the case folding of the `i` flag. `unicodeVersion()` reports the version a pattern was
compiled with. Both settings are read when the pattern is compiled, so a match costs the same whichever is chosen.

```php
use Corvus\RegexEcma262\Regex;

// ECMAScript 2020 as its edition was published, with the Unicode of its year.
$greek = new Regex('^\p{Script=Greek}+$', 'u', 1.0, edition: 'es2020', unicode_version: 13);
assert($greek->test('αβγ') && $greek->unicodeVersion() === 13);

// Vithkuqi is in Unicode 14 and later, so not in Unicode 13.
assert(!Regex::isValid('\p{Script=Vithkuqi}', 'u', unicode_version: 13));
```

## Matching

The methods are those of a `RegExp`, with the position as an argument where JavaScript keeps `lastIndex`:

```php
use Corvus\RegexEcma262\{MatchResult, Regex};

$date = new Regex('(?<year>\d{4})-(?<month>\d{2})', 'gu');
$text = 'due 2026-10, then 2027-01';

assert($date->test($text));
$m = $date->exec($text);
assert($m->group() === '2026-10' && $m->group('year') === '2026' && [$m->start(), $m->end()] === [4, 11]);

// Every match of a global search, as matchAll makes it.
assert(array_map(fn(MatchResult $m) => $m->group(), $date->matchAll($text)) === ['2026-10', '2027-01']);
assert($date->countMatches($text) === 2);

// replace replaces every match with the g flag, and the first without it, with every $ form of ECMA-262.
assert($date->replace($text, '$<month>/$1') === 'due 10/2026, then 01/2027');
assert($date->replace($text, fn(MatchResult $m) => $m->group('year')) === 'due 2026, then 2027');

// split puts what the groups captured between the pieces; a group that did not take part is null.
assert((new Regex('\s*(;)\s*'))->split('a ; b;c') === ['a', ';', 'b', ';', 'c']);
```

`exec($subject, $offset)` is `exec` with `lastIndex` set to `$offset`, and `y` anchors it there. A `Regex` has no
mutable state, so one instance is safe to share.

## Timeouts

A match has no limit by default. A `Regex` made with a timeout in seconds abandons a match that reaches it, or that
reaches the cap on backtracking memory the timeout brings, with `MatchLimitException`, whatever the pattern:

```php
use Corvus\RegexEcma262\{MatchLimitException, Regex};

$slow = new Regex('^(?:(a+)\1*)+$', '', 0.1);
assert($slow->timeout() === 0.1);
try {
    $slow->test(str_repeat('a', 60) . 'b');
    assert(false);
} catch (MatchLimitException) {
}
```

A `Regex` made without a timeout takes `Regex::defaultTimeout()`, which is none unless `Regex::setDefaultTimeout()` sets
it. A program that matches patterns or texts from outside should set one, for example with
`Regex::setDefaultTimeout(1.0)` when it starts. `test` and `exec` take a `$timeout` of their own for one match.

A pattern without a backreference is matched in time linear in the text, and no pattern can overflow the stack, so the
timeout is what bounds the others.

## UTF-8 and UTF-16

A string is UTF-8, matched as its bytes with no transcoding, and positions are byte offsets, as the `preg_` functions
give them. It is checked before it is read: a string that is not UTF-8 throws `ValueError`, and is never matched or
read as U+FFFD. `Utf16` text is matched as UTF-16, lone surrogates and all, with positions in code units, as JavaScript
gives them; the text of a group of a `Utf16` subject is a `Utf16`.

```php
use Corvus\RegexEcma262\{Regex, Utf16};

$dotB = new Regex('.b', 'u');
$m = $dotB->exec('a😀b');
assert([$m->start(), $m->end()] === [1, 6]);  // bytes of UTF-8

$m16 = $dotB->exec(Utf16::fromUtf8('a😀b'));
assert([$m16->start(), $m16->end()] === [1, 4]);  // UTF-16 code units
assert($m16->group()->toUtf8() === '😀b');

try {
    $dotB->test("\xff");
    assert(false);
} catch (ValueError) {
}
```

Under the `u` and `v` flags a character is a code point in both encodings, so a class, a dot or a quantifier takes a
four-byte sequence as one character. With no flag ECMA-262 matches by UTF-16 code unit; over UTF-8 the extension keeps
that meaning, so a match can start or end between the halves of a character beyond the Basic Multilingual Plane. Such
a position is the character's third byte, and the half is written as U+FFFD.

## Differences from PCRE

The methods follow `RegExp`; the meaning follows ECMA-262. The differences a user coming from the `preg_` functions will
meet:

- **The grammar.** No delimiters and no trailing modifiers: the flags are a separate string. No `(?i)` inline option
  (ECMA-262 has `(?i:...)` groups), no atomic groups, no possessive quantifiers, no `\A`, `\z` or `\h`, no
  conditionals and no recursion.
- **The character classes.** `\d` is `[0-9]`, `\w` is the 63 ASCII word characters, `\s` is ECMAScript's white space
  and line terminators, and `.` excludes the four line terminators (LF, CR, U+2028, U+2029).
- **Replacement.** A template writes a group as `$1` or `$<name>`, and `$&`, `` $` `` and `$'` are the match and the
  text before and after it; `replace` replaces every match only with the `g` flag, and `replaceAll` always does.
- **Split.** `split` is JavaScript's, which puts what the groups captured between the pieces, as
  `PREG_SPLIT_DELIM_CAPTURE` does, and takes a limit on the number of pieces.
- **Timeouts.** PCRE's `pcre.backtrack_limit` makes a match return false; here a pattern without a backreference is
  matched in time linear in the text, and a timeout, which bounds a match's time and its memory, throws.
