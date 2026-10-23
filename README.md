# corvus-regex-ecma262 for PHP

Regular expressions with the grammar and the answers of ECMA-262, the `RegExp` of JavaScript, for PHP: the
[corvus-regex-ecma262](https://github.com/corvus-dotnet/corvus-regex-ecma262-rs) Rust crate, as a PHP extension with
builds for PHP 8.2 to 8.5 (NTS and ZTS) on Linux (glibc and musl, x86_64 and arm64), macOS (arm64) and Windows
(x86_64), installed with [PIE](https://github.com/php/pie).

- Every flag: `d`, `g`, `i`, `m`, `s`, `u`, `v` and `y`, with the grammar of each. Without `u` or `v` a pattern is
  read by the Annex B grammar and matched by UTF-16 code unit, as ECMA-262 matches it; with them, by code point. The
  `v` flag has nested classes, the difference and the intersection of classes, strings in a class and the properties
  of strings.
- ECMAScript 2025: lookbehind, named groups (and a name shared between alternatives), modifier groups such as
  `(?i:...)`, and every Unicode property ECMA-262 lists, from Unicode 17 tables the crate carries.
- UTF-8 strings, with positions in bytes as PCRE's functions give them, and UTF-16 text (`Utf16`), with positions in
  code units as JavaScript gives them. A pattern answers as it does in a browser or Node, which PCRE does not.
- A pattern without a backreference is matched in time proportional to its size times the text, and a pattern can be
  given a match timeout, as a `Regex` of .NET can, which every method honours and which caps the backtracking memory
  too, so with a timeout neither a pattern nor a text from outside can make a program hang.

**Compliance.** Over the [ECMA-262 test data](https://github.com/corvus-dotnet/ecma262-regex-test-data) (what V8 says
about 117,889 patterns and 5,884,566 texts, test262's and `exec`, the global search, `replace` and `split` among them,
and texts of bytes that are not UTF-8, which it refuses), through this extension over `Utf16` text and UTF-8 strings, it
gives 26,953,709 right answers and none wrong, refuses no valid pattern, accepts no invalid one and reaches no limit. CI
runs the whole data through every build on its platform.

## Install

```
pie install corvus-dotnet/corvus-regex-ecma262
```

PIE downloads the build for the PHP it installs into, and enables the extension.

## Getting started

```php
use Corvus\RegexEcma262\{MatchResult, Regex, Utf16};

// The flags are those of new RegExp($pattern, $flags).
$date = new Regex('(?<year>\d{4})-(?<month>\d{2})', 'gu');

$date->test('due 2026-10');                              // true
$m = $date->exec('due 2026-10');
$m->group('year');                                       // '2026'
[$m->start(), $m->end()];                                // [4, 11]

// Every match of a global search, as matchAll makes it.
array_map(fn(MatchResult $m) => $m->group(), $date->matchAll('2026-10, 2027-01'));  // ['2026-10', '2027-01']

// replace replaces every match with the g flag, and the first without it; a callable may give each replacement.
$date->replace('2026-10, 2027-01', '$<month>/$1');       // '10/2026, 01/2027'
$date->replace('2026-10', fn(MatchResult $m) => $m->group('month'));  // '10'

// split splices in what the groups captured; a group that did not take part is null.
(new Regex('\s*(;)\s*'))->split('a ; b;c');              // ['a', ';', 'b', ';', 'c']

// UTF-16 text, positions in code units as in JavaScript.
$date->exec(Utf16::fromUtf8('café 2026-12'))->start();   // 5
```

## The API

| | |
|---|---|
| `new Regex($pattern, $flags = '', $timeout = null)` | Compiles a pattern as `new RegExp($pattern, $flags)` does. `$timeout` is the match timeout in seconds (0 for none; `null` for the default). |
| `Regex::setDefaultTimeout($seconds)`, `Regex::defaultTimeout()` | The timeout of every pattern compiled without one: `null` (no limit) unless set. |
| `Regex::isValid($pattern, $flags = '')` | Whether a pattern is valid, without compiling it. |
| `test($subject, $offset = 0, $timeout = null)` | `RegExp.prototype.test` with `lastIndex` at `$offset` (at `$offset` alone with `y`). |
| `exec($subject, $offset = 0, $timeout = null)` | The first match at or after `$offset`, as `exec` finds it: a `MatchResult`, or `null`. |
| `matchAll($subject)` | A global search as `matchAll` makes it: an array of `MatchResult`. |
| `countMatches($subject)` | The number of matches of a global search, without making them. |
| `replace($subject, $replacement)` | `String.prototype.replace`: the first match, or every match with `g`; a template or a callable. |
| `replaceAll($subject, $replacement)` | `String.prototype.replaceAll`: every match. |
| `split($subject, $limit = null)` | `String.prototype.split`: an array of strings, `null` for a group that did not take part. |
| `source()`, `flags()`, `timeout()`, `groupCount()`, `groupNames()` | What the pattern is. |

A `MatchResult` has `group($g = 0)`, `start($g = 0)` and `end($g = 0)` (a group by number or name; `null` when it did
not take part), `groups()` (the array `preg_match` fills: the match, then each group) and `namedGroups()`. A subject is
a string (UTF-8; one that is not throws `ValueError`) or a `Utf16`, built from UTF-16LE bytes or with
`Utf16::fromUtf8()`; the text of a group of a `Utf16` subject is a `Utf16`. Without `u` or `v` a pattern is matched by
UTF-16 code unit, so over UTF-8 a match can start or end between the halves of a character beyond the Basic
Multilingual Plane; such a position is the character's third byte, and the half is written as U+FFFD.

**Errors.** `InvalidPatternException`, `InvalidFlagsException`, `PatternTooLargeException` and `MatchLimitException`
(a match that reached its timeout or its cap on backtracking memory) are each a `RegexException`, an `Exception`.

**Timeouts.** A pattern has a match timeout, as a `Regex` of .NET has: `new Regex($pattern, $flags, 1.0)`, or for every
pattern compiled after it `Regex::setDefaultTimeout(1.0)`. Every method that matches gives each of its matches the
timeout and throws `MatchLimitException` at it; `test` and `exec` take a `$timeout` of their own in its place. Without
a timeout (the default) a match has no limit of time or of memory, so a program that matches a pattern or a subject it
did not write should set one.

## Benchmarks

The Industry workloads of dotnet/performance, this extension against PHP's PCRE functions and V8, are on the central
page, [corvus-oss.org/corvus-regex-ecma262-benchmarks](https://corvus-oss.org/corvus-regex-ecma262-benchmarks/),
measured nightly on one runner with every other Corvus ECMA-262 engine.

## Repository

- `src/lib.rs`: the extension (ext-php-rs, over the crate); `package.ps1`: builds and packages it as PIE expects.
- `tests/api.php`: the tests; `tools/dataset.php`: the test-data runner.
- `tools/bench`: the benchmark harness and the script the image runs; `Dockerfile`: the image the central page runs.

A release is tagged with its version alone (`0.1.1`), as Composer and PIE read it, and carries every build.

## Licence

Apache-2.0. See [LICENSE](LICENSE).
