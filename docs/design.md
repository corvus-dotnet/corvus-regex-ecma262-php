# corvus_regex_ecma262 for PHP: design

An outline of the extension as it is: what it offers, how it is built and why, and how it is tested and measured. The
[getting-started page](getting-started.md) shows how to use it.

## 1. What it is

A PHP extension of regular expressions with ECMA-262's grammar and answers (ECMAScript 2025), over UTF-8 strings and
UTF-16 text. It is a binding, through ext-php-rs, to the corvus-regex-ecma262 Rust crate, whose engine it uses as it
is, installed with PIE.

**Why ECMA-262.** JSON Schema, OpenAPI and many wire formats specify their patterns as ECMAScript regular
expressions. PHP's `preg_` functions are PCRE's, with its own grammar and its own meaning for `\d`, `\w`, `\s`, `.` and
case folding, so a translation to them can never be exact. An engine that reads ECMA-262 directly, held to V8's answers
over a large data set (section 6), needs no translation.

## 2. Public surface

**Names.** Namespace `Corvus\RegexEcma262`: a final class `Regex` with `test`, `exec`, `matchAll`, `countMatches`,
`replace`, `replaceAll` and `split`, the methods of a `RegExp`, each taking the position as `$offset` where JavaScript
keeps `lastIndex`; a `MatchResult` with `group`, `start`, `end`, `groups` (the array `preg_match` fills) and
`namedGroups`; and `Utf16`, for UTF-16 text.

**Flags.** ECMA-262's, as a string, and nothing else: no delimiters and no trailing modifiers.

**Edition and Unicode version.** By default a pattern is read with today's grammar (`edition: 'current'`) and V8's
version of Unicode (`unicode_version: null`, 17 today). Either is overridden when the pattern is compiled,
independently: `'es2020'` is the grammar JSON Schema 2020-12 names, and the versions are 13, 17, 18 and `'latest'`.
Both are resolved when the pattern is compiled.

**Errors.** `InvalidPatternException`, `InvalidFlagsException`, `PatternTooLargeException` and `MatchLimitException`
are each a `RegexException`, an `Exception`. A string that is not UTF-8, and an argument out of range, throw
`ValueError`.

**ECMA-262 algorithms.** `replace` substitutes as `GetSubstitution` does, replacing every match only with `g`, or calls
a callable with each `MatchResult`; `replaceAll` always replaces every match. `split` follows
`RegExp.prototype[Symbol.split]`, with `null` for a group that did not take part.

**Input.** A PHP string is UTF-8, checked before it is read and refused when it is not, never read as U+FFFD, with
positions in bytes as the `preg_` functions give them. A `Utf16` holds UTF-16LE code units, lone surrogates and all,
with positions in code units; the text of a group of a `Utf16` subject is a `Utf16`. Nothing is transcoded.

**Limits.** The crate's: a match timeout in seconds per pattern or for every pattern (`Regex::setDefaultTimeout`), with
a `$timeout` of its own on `test` and `exec`; the size limits; and no stack overflow.

## 3. Engine

The engine is the Rust crate's, which
[its design](https://github.com/corvus-dotnet/corvus-regex-ecma262-rs/blob/main/docs/design.md) describes. The
extension's work is between PHP and the crate:

- **Handles.** A `Regex` holds the crate's compiled pattern, immutable. A `MatchResult` holds a match's positions and
  its groups' texts, kept in the subject's kind, so it outlives the subject.
- **Text.** A PHP string's bytes are matched where they lie. A string checked once is marked as valid UTF-8 (the flag
  PCRE's functions set and read, from PHP 8.3) and read without a check from then on. A `Utf16` keeps its code units in
  memory of its own.
- **Without u or v.** A match over UTF-8 can end between the halves of a character beyond the Basic Multilingual
  Plane, at its third byte, and the half is written as U+FFFD in a group's text and a replacement.
- **Errors.** The crate's errors are thrown as the extension's exceptions, a timeout as `MatchLimitException`.

## 4. The builds

Each release attaches a build for every PHP from 8.2 to 8.5, NTS and ZTS, on Linux (glibc, linked by zig against glibc
2.17's symbols so it loads on any later one, and musl, x86_64 and arm64), macOS (arm64) and Windows (x86_64), named as
PIE looks for them; `package.ps1` makes each. ext-php-rs needs a nightly Rust on Windows, for PHP's `vectorcall`. The
API reference (`docs/api.md`) is generated from the classes `src/lib.rs` gives PHP by `tools/docs/gen-api.ps1`, which
CI checks is current.

## 5. Targets and layout

PHP 8.2 to 8.5, NTS and ZTS, on the platforms above; Packagist reads the release's tag, and PIE installs the build for
the PHP it runs in.

- `src/lib.rs`: the extension; `package.ps1`: a build, packaged as PIE expects.
- `tests/api.php`: the tests; `tests/getting-started.php`: the getting-started page's examples; `tools/dataset.php`:
  the test-data runner; `tools/docs`: the API reference's generator; `tools/bench`: the benchmark harness.

## 6. Testing

**The ECMA-262 test data.** [`ecma262-regex-test-data`](https://github.com/corvus-dotnet/ecma262-regex-test-data)
holds V8's answers: validity under each grammar, `test` answers, the positions of `exec`, the global search, `y`,
`replace` and `split`, and every `exec` call of test262. `tools/dataset.php` runs all of it through every build on its
platform, over UTF-8 strings and `Utf16` text, with none wrong, refused or at a limit, and as the data's own scripts
write it for Unicode 13 and 18 and for ECMAScript 2020.

**Other tests.** `tests/api.php` over every method and both encodings, and the getting-started page's examples with
assertions on, through every build: each PHP version, NTS and ZTS, on each platform.

## 7. Benchmarks

The harness image, `ghcr.io/corvus-dotnet/corvus-regex-ecma262-php`, runs the Industry workloads of
`dotnet/performance` over this extension (UTF-8 and UTF-16), PHP's PCRE functions and V8. The published figures are on
[the benchmark page](https://corvus-oss.org/corvus-regex-ecma262-benchmarks/), which runs every engine's image each
night; this repository publishes none of its own.

Three rows are not like for like with an engine that is not ECMA-262's, as the price of its rules under u: `.*` (`.`
excludes CR, so the CRLF text has more matches), `Sher[a-z]+|Hol[a-z]+` under i (the search must also find the long s
and the Kelvin sign), and `[a-q][^u-z]{13}x` (a supplementary character is two units, so the distance to the `x` is
not fixed).

## 8. Differences a PHP user will meet

- No delimiters or trailing modifiers: the flags are a separate string, ECMA-262's letters.
- The grammar is ECMA-262's, not PCRE's: `(?i:...)` groups but no `(?i)` prefix, no possessive quantifiers, atomic
  groups, `\A`, `\z`, `\h` or recursion.
- A match that reaches its timeout throws, where `pcre.backtrack_limit` makes a `preg_` function return false.

The [getting-started page](getting-started.md) has the full list.
