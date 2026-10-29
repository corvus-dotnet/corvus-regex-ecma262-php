# API reference

The PHP API of the extension, namespace `Corvus\RegexEcma262`, generated from the classes and functions
`src/lib.rs` gives PHP and their doc comments by `tools/docs/gen-api.ps1`. [Getting started](getting-started.md)
shows it at work.

## Regex

```php
final class Corvus\RegexEcma262\Regex
```

A compiled ECMA-262 regular expression, as `new RegExp($pattern, $flags)` compiles it.

### Regex::DEFAULT_UNICODE_VERSION

```php
public const int DEFAULT_UNICODE_VERSION = 17;
```

The version of Unicode a pattern is read with by default: the version of the V8 the answers are held to.

### Regex::LATEST_UNICODE_VERSION

```php
public const int LATEST_UNICODE_VERSION = 18;
```

The newest version of Unicode the extension carries, which 'latest' stands for.

### Regex::__construct

```php
public function __construct(string $pattern, ?string $flags = null, ?float $timeout = null, ?string $edition = null, int|string|null $unicode_version = null);
```

Compiles a pattern with the flags `new RegExp($pattern, $flags)` takes, any of dgimsuvy. Throws
InvalidPatternException, InvalidFlagsException or PatternTooLargeException (each a RegexException). $timeout is
the time in seconds each match may take before it is abandoned with MatchLimitException (0 for none); null takes
Regex::defaultTimeout(), which is none unless set. A timeout also caps the backtracking memory of a match;
without one a match has no limit of time or of memory, so give a pattern or a text from outside one.

$edition is the grammar the pattern is read with: 'current' (today's, the default) or 'es2020', the grammar of
ECMAScript 2020, which JSON Schema 2020-12 names. It refuses what later editions added, and nothing else: the d
and v flags (InvalidFlagsException), modifier groups such as (?i:...), a group name used by two groups, and a
property name or value that is not in that edition's tables (InvalidPatternException). $unicode_version is the
version of Unicode whose tables the pattern is read with: null (the default, DEFAULT_UNICODE_VERSION), 13, 17, 18
or 'latest' (LATEST_UNICODE_VERSION). Each is independent of the other; both are settled here, and cost a match
nothing. A value of either outside those throws a ValueError.

### Regex::setDefaultTimeout

```php
public static function setDefaultTimeout(?float $seconds = null): void;
```

Sets the timeout of every Regex made from now on without one of its own: seconds, or null (or 0) for no limit,
the default. It is the process-wide default of .NET's REGEX_DEFAULT_MATCH_TIMEOUT; a program that matches
patterns or subjects from outside should set one when it starts. A Regex already made keeps its own.

### Regex::defaultTimeout

```php
public static function defaultTimeout(): ?float;
```

The timeout in seconds of a Regex made now without one of its own, or null for no limit.

### Regex::timeout

```php
public function timeout(): ?float;
```

The time in seconds each match may take before it is abandoned with MatchLimitException, or null for none.

### Regex::isValid

```php
public static function isValid(string $pattern, ?string $flags = null, ?string $edition = null, int|string|null $unicode_version = null): bool;
```

Whether a pattern is valid with the flags, in the edition and the version of Unicode (as the constructor takes
them), without compiling it.

### Regex::edition

```php
public function edition(): string;
```

The edition whose grammar the pattern was read with: 'current' or 'es2020'.

### Regex::unicodeVersion

```php
public function unicodeVersion(): int;
```

The version of Unicode whose tables the pattern was read with, resolved: 13, 17 or 18.

### Regex::unicodeVersions

```php
public static function unicodeVersions(): array;
```

The versions of Unicode the extension carries, each a set of tables a pattern can be read with.

### Regex::source

```php
public function source(): string;
```

The pattern as it was given.

### Regex::flags

```php
public function flags(): string;
```

The flags as RegExp.prototype.flags writes them, in the order dgimsuvy.

### Regex::groupCount

```php
public function groupCount(): int;
```

The number of capturing groups.

### Regex::groupNames

```php
public function groupNames(): array;
```

The distinct names of the groups, in the order of the first group of each name.

### Regex::test

```php
public function test(string|Utf16 $subject, ?int $offset = null, ?float $timeout = null): bool;
```

Whether the pattern matches at or after $offset (at $offset alone with the y flag), as RegExp.prototype.test
with lastIndex set to it. A match that reaches the pattern's timeout throws MatchLimitException; a timeout in
seconds here takes its place for this match (0 for none).

### Regex::exec

```php
public function exec(string|Utf16 $subject, ?int $offset = null, ?float $timeout = null): ?MatchResult;
```

The first match at or after $offset, as exec finds it with lastIndex set to it: a MatchResult, or null. A match
that reaches the pattern's timeout throws MatchLimitException; a timeout in seconds here takes its place.

### Regex::matchAll

```php
public function matchAll(string|Utf16 $subject): array;
```

Every match of a global search, as matchAll makes one: an array of MatchResult. After an empty match the
search goes on one character later, and with the y flag each match must start where the one before ended. A
match that reaches the pattern's timeout throws MatchLimitException.

### Regex::countMatches

```php
public function countMatches(string|Utf16 $subject): int;
```

The number of matches of a global search, without making them.

### Regex::replace

```php
public function replace(string|Utf16 $subject, string|Utf16|callable $replacement): string|Utf16;
```

The subject with the first match replaced, or every match with the g flag, as String.prototype.replace replaces
them. The replacement is a template ($& the match, `` $` `` the text before it, $' the text after it, $1 to $99 a
group, $\<name> a named group, $$ a dollar sign) or a callable given each MatchResult, returning the text.

### Regex::replaceAll

```php
public function replaceAll(string|Utf16 $subject, string|Utf16|callable $replacement): string|Utf16;
```

The subject with every match replaced, whether or not the g flag is set (String.prototype.replaceAll).

### Regex::split

```php
public function split(string|Utf16 $subject, ?int $limit = null): array;
```

The subject split around the matches, as String.prototype.split($separator, $limit) splits it: what each group
captured is spliced in after the piece before it (null for a group that did not take part), an empty match at
the start or the end splits nothing, and there are at most $limit pieces.

## MatchResult

```php
final class Corvus\RegexEcma262\MatchResult
```

A match: where it is and what each group captured, positions in bytes for a string subject and in code units for
a Utf16.

### MatchResult::start

```php
public function start(int|string|null $group = null): ?int;
```

The start of the group (0, the whole match, by default), or null when it did not take part.

### MatchResult::end

```php
public function end(int|string|null $group = null): ?int;
```

The end of the group (0, the whole match, by default), or null when it did not take part.

### MatchResult::group

```php
public function group(int|string|null $group = null): string|Utf16|null;
```

The text of the group (0, the whole match, by default), or null when it did not take part.

### MatchResult::groups

```php
public function groups(): array;
```

The text of the whole match then of each group, null for one that did not take part: the array preg_match
fills.

### MatchResult::namedGroups

```php
public function namedGroups(): array;
```

The text of the group of each name, null for a name whose group did not take part, in the order of the names.

### MatchResult::count

```php
public function count(): int;
```

The number of groups, the whole match among them.

## Utf16

```php
final class Corvus\RegexEcma262\Utf16
```

UTF-16LE text, matched as UTF-16 with positions in code units, as JavaScript gives them.

### Utf16::__construct

```php
public function __construct(string $utf16le);
```

The text from its UTF-16LE bytes (an even number of them), lone surrogates and all.

### Utf16::fromUtf8

```php
public static function fromUtf8(string $text): Utf16;
```

The text of a UTF-8 string.

### Utf16::toUtf8

```php
public function toUtf8(): string;
```

The text as UTF-8, a lone surrogate as U+FFFD.

### Utf16::bytes

```php
public function bytes(): string;
```

The UTF-16LE bytes.

### Utf16::length

```php
public function length(): int;
```

The number of code units.

## RegexException

```php
class Corvus\RegexEcma262\RegexException extends \Exception
```

What every failure of the extension throws: a pattern that is not valid, or a match that reached its limit.

## InvalidPatternException

```php
class Corvus\RegexEcma262\InvalidPatternException extends RegexException
```

The pattern is not valid in the grammar of its flags (new RegExp would throw a SyntaxError).

## InvalidFlagsException

```php
class Corvus\RegexEcma262\InvalidFlagsException extends RegexException
```

A letter that is not one of dgimsuvy, a flag given twice, u with v, or d or v under the 2020 edition.

## PatternTooLargeException

```php
class Corvus\RegexEcma262\PatternTooLargeException extends RegexException
```

The pattern is over a limit of size: its length, the depth its groups nest to, or its size as it is read.

## MatchLimitException

```php
class Corvus\RegexEcma262\MatchLimitException extends RegexException
```

A match reached its timeout or its cap on backtracking memory, so there is no answer.

## Functions

### Corvus\RegexEcma262\crate_version

```php
function crate_version(): string;
```

The version of the corvus-regex-ecma262 crate the extension was built from.
