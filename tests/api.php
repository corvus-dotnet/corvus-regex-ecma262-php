<?php
// The extension's API: each member over UTF-8 strings and Utf16 text, against the answers V8 gives.
declare(strict_types=1);

use Corvus\RegexEcma262\{InvalidFlagsException, InvalidPatternException, MatchLimitException, MatchResult,
    PatternTooLargeException, Regex, RegexException, Utf16};

$checks = 0;
$failures = [];
function check(string $what, mixed $expected, mixed $actual): void
{
    global $checks, $failures;
    $checks++;
    if ($expected !== $actual) {
        $failures[] = "$what: expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
}
function throws(string $what, string $class, callable $f): void
{
    global $checks, $failures;
    $checks++;
    try {
        $f();
        $failures[] = "$what: expected $class, nothing was thrown";
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            $failures[] = "$what: expected $class, got " . get_class($e) . ': ' . $e->getMessage();
        }
    }
}
function u16(string $utf8): Utf16
{
    return Utf16::fromUtf8($utf8);
}

// Compiling
$date = new Regex('(?<year>\d{4})-(?<month>\d{2})', 'gu');
check('source', '(?<year>\d{4})-(?<month>\d{2})', $date->source());
check('flags', 'gu', $date->flags());
check('group count', 2, $date->groupCount());
check('group names', ['year', 'month'], $date->groupNames());
check('flags in order', 'giy', (new Regex('a', 'yig'))->flags());
check('default flags', '', (new Regex('a'))->flags());
throws('invalid pattern', InvalidPatternException::class, fn() => new Regex('('));
throws('invalid property', InvalidPatternException::class, fn() => new Regex('\p{Nope}', 'u'));
throws('u with v', InvalidFlagsException::class, fn() => new Regex('a', 'uv'));
throws('unknown flag', InvalidFlagsException::class, fn() => new Regex('a', 'q'));
throws('too large', PatternTooLargeException::class, fn() => new Regex(str_repeat('(', 1001) . str_repeat(')', 1001)));
check('exceptions are RegexException', true, is_subclass_of(MatchLimitException::class, RegexException::class));
check('valid', true, Regex::isValid('a+', 'u'));
check('not valid', false, Regex::isValid('a{2,1}', 'u'));
// Annex B reads {2,1 as text without u.
check('annex B', true, Regex::isValid('a{2,1', ''));

// test and exec
$o = new Regex('o+');
check('test', true, $o->test('foo'));
check('test, no', false, $o->test('bar'));
$m = $o->exec('a foo, a boo', 3);
check('exec at 3', [3, 5, 'oo'], [$m->start(), $m->end(), $m->group()]);
check('exec at 5', 10, $o->exec('a foo, a boo', 5)->start());
check('exec, none', null, $o->exec('a foo', 5));
check('exec past the end', null, $o->exec('a foo', 99));
check('positions in bytes', [6, 8], [(new Regex('ok'))->exec('café ok')->start(), (new Regex('ok'))->exec('café ok')->end()]);
$sticky = new Regex('o', 'y');
check('sticky at 1', true, $sticky->test('foo', 1));
check('sticky at 0', false, $sticky->test('foo', 0));
throws('not UTF-8', ValueError::class, fn() => $o->test("\xff"));
throws('not a string', TypeError::class, fn() => $o->test(3));

// Without u a character beyond the Basic Multilingual Plane is two code units: over UTF-8 the position between the
// halves is the third byte, and the half is written as U+FFFD.
$half = (new Regex('^.'))->exec('😀x');
check('half', [0, 2, "\u{FFFD}"], [$half->start(), $half->end(), $half->group()]);
check('whole with u', '😀', (new Regex('^.', 'u'))->exec('😀x')->group());

// Utf16 text: positions in code units, lone surrogates and all.
$m16 = $o->exec(u16('a foo'));
check('utf16 positions', [3, 5], [$m16->start(), $m16->end()]);
check('utf16 group', 'oo', $m16->group()->toUtf8());
$lone = new Utf16("a\x00\x00\xd8b\x00");
check('lone surrogate, legacy', true, (new Regex('^...$'))->test($lone));
check('lone surrogate, u', true, (new Regex('^...$', 'u'))->test($lone));
check('lone surrogate escape', 1, (new Regex('\ud800'))->exec($lone)->start());
check('pair, legacy', true, (new Regex('^..$'))->test(u16('😀')));
check('pair, u', true, (new Regex('^.$', 'u'))->test(u16('😀')));
check('utf16 length', 2, u16('😀')->length());
check('utf16 bytes', "=\xd8\x00\xde", u16('😀')->bytes());
throws('odd bytes', ValueError::class, fn() => new Utf16('abc'));

// Groups
$r = new Regex('(?<year>\d{4})-(?<month>\d{2})(-(\d{2}))?', 'u');
$m = $r->exec('due 2026-10, then');
check('group 0', '2026-10', $m->group(0));
check('group by name', '2026', $m->group('year'));
check('group 3', null, $m->group(3));
check('groups', ['2026-10', '2026', '10', null, null], $m->groups());
check('named groups', ['year' => '2026', 'month' => '10'], $m->namedGroups());
check('start by name', 9, $m->start('month'));
check('start, no part', null, $m->start(3));
check('count', 5, $m->count());
throws('no group 9', ValueError::class, fn() => $m->group(9));
throws('no such name', ValueError::class, fn() => $m->group('nope'));
check('shared name', 'b', (new Regex('(?<d>a)|(?<d>b)', 'u'))->exec('b')->group('d'));

// The global search
$digits = new Regex('\d+', 'g');
check('match all', ['1', '22', '333', '4444'], array_map(fn(MatchResult $m) => $m->group(), $digits->matchAll('1 22 333 4444')));
check('count matches', 1000, (new Regex('a', 'g'))->countMatches(str_repeat('a ', 1000)));
check('empty, u', 2, count((new Regex('', 'gu'))->matchAll('😀')));
check('empty, legacy, utf16', 3, count((new Regex('', 'g'))->matchAll(u16('😀'))));
check('empty, legacy, utf8', [0, 2, 4, 5], array_map(fn($m) => $m->start(), (new Regex('', 'g'))->matchAll('😀,')));
check('sticky, global', 2, count((new Regex('a', 'gy'))->matchAll('aab')));
check('sticky, global, none', 0, count((new Regex('a', 'gy'))->matchAll('baa')));

// replace
$d = new Regex('(?<y>\d{4})-(?<m>\d{2})', 'g');
check('replace, global', '10/2026, 01/2027', $d->replace('2026-10, 2027-01', '$<m>/$1'));
$first = new Regex('\d+');
check('replace, first', '[1] 2 3', $first->replace('1 2 3', '[$&]'));
check('replace all', '[1] [2] [3]', $first->replaceAll('1 2 3', '[$&]'));
check('replace, forms', 'a<a|b|$>b', $first->replace('a1b', '<$`|$\'|$$>'));
check('replace, callable', '2 4 6', $first->replaceAll('1 2 3', fn(MatchResult $m) => (string) ((int) $m->group() * 2)));
check('replace, empty', '-a-b-', (new Regex('', 'g'))->replaceAll('ab', fn($m) => '-'));
check('replace, utf16', 'a<1>b', $first->replace(u16('a1b'), '<$&>')->toUtf8());
check('replace, half', "x\u{FFFD}", (new Regex('^.'))->replace('😀', 'x'));
throws('replace, bad callable result', TypeError::class, fn() => $first->replace('a1', fn($m) => 1));

// split
$comma = new Regex('\s*(;)\s*');
check('split', ['a', ';', 'b', ';', 'c'], $comma->split('a ; b;c'));
check('split, limit', ['a', ';'], $comma->split('a;b;c', 2));
check('split, undefined group', ['a', null, 'b'], (new Regex('(x)?,'))->split('a,b'));
check('split, utf16', ['a', ';', 'b'], array_map(fn(Utf16 $p) => $p->toUtf8(), $comma->split(u16('a;b'))));

// Limits
$slow = new Regex('^(a+)+\1$');
$text = str_repeat('a', 64) . '!';
throws('time limit, test', MatchLimitException::class, fn() => $slow->test($text, 0, 0.005));
throws('time limit, exec', MatchLimitException::class, fn() => $slow->exec($text, 0, 0.005));
check('linear', false, (new Regex('^(a+)+$'))->test($text, 0, 1.0));

// The match timeout of a pattern, which every method honours
$never = '^(?:(a+)\1*)+$';
$long = str_repeat('a', 60) . 'b';
$timed = new Regex($never, 'u', 0.03);
check('timeout', 0.03, $timed->timeout());
foreach ([
    'test' => fn() => $timed->test($long),
    'exec' => fn() => $timed->exec($long),
    'matchAll' => fn() => $timed->matchAll($long),
    'countMatches' => fn() => $timed->countMatches($long),
    'replace' => fn() => $timed->replace($long, 'x'),
    'replaceAll' => fn() => $timed->replaceAll($long, 'x'),
    'replace, callable' => fn() => $timed->replace($long, fn($m) => 'x'),
    'split' => fn() => $timed->split($long),
    'split, limit' => fn() => $timed->split($long, 2),
    'test, utf16' => fn() => $timed->test(u16($long)),
    'matchAll, utf16' => fn() => $timed->matchAll(u16($long)),
    'replace, utf16' => fn() => $timed->replace(u16($long), 'x'),
    'replace, utf16 template' => fn() => $timed->replace(u16($long), u16('x')),
    'split, utf16' => fn() => $timed->split(u16($long)),
] as $what => $f) {
    throws("the timeout, $what", MatchLimitException::class, $f);
}
check('answered within the timeout', true, $timed->test('aaaa'));
check('replace within the timeout', '<aaaa>', $timed->replace('aaaa', '<$&>'));
check('a limit of its own, none', true, (new Regex('^(a+)\1$', '', 0.000001))->test('aaaa', 0, 0.0));
throws('a limit of its own', MatchLimitException::class, fn() => (new Regex($never, 'u', 3600.0))->test($long, 0, 0.03));
try {
    (new Regex('(?:a?){2147483647}', '', 3600.0))->test('');
    check('the memory cap', 'thrown', 'nothing thrown');
} catch (MatchLimitException $e) {
    check('the memory cap', true, str_contains($e->getMessage(), 'memory'));
}
check('no default timeout', null, Regex::defaultTimeout());
check('no timeout', null, (new Regex('a'))->timeout());
Regex::setDefaultTimeout(0.03);
try {
    check('default timeout', 0.03, Regex::defaultTimeout());
    $defaulted = new Regex($never, 'u');
    $own = new Regex('a', '', 5.0);
    $none = new Regex('a', '', 0.0);
} finally {
    Regex::setDefaultTimeout(null);
}
check('default timeout, reset', null, Regex::defaultTimeout());
check('a pattern takes the default', 0.03, $defaulted->timeout());
throws('the default timeout', MatchLimitException::class, fn() => $defaulted->test($long));
check('a pattern keeps its own', 5.0, $own->timeout());
check('a pattern with none', null, $none->timeout());

// Nested classes on a small stack: with v a class holds classes, each a level of reading. On a stack of 256 KB the
// deepest nesting allowed is read, compiled and matched, and deeper is refused as too large, rather than overflowing
// the stack, which ends the process (as with the crate up to 0.1.1). PHP runs on the main thread, so a child is started
// with ulimit -s; it loads the extension named by CORVUS_REGEX_PHP_EXTENSION, as CI gives it.
$extension = getenv('CORVUS_REGEX_PHP_EXTENSION');
if (PHP_OS_FAMILY === 'Windows' || $extension === false) {
    echo "small stack: skipped (", PHP_OS_FAMILY === 'Windows' ? 'no ulimit on Windows' : 'CORVUS_REGEX_PHP_EXTENSION not set', ")\n";
} else {
    $code = <<<'CODE'
        $nest = fn(int $depth) => str_repeat('[', $depth) . 'a' . str_repeat(']', $depth);
        $out = [(new Corvus\RegexEcma262\Regex($nest(1000), 'v'))->test('a') ? 'matched' : 'not matched'];
        foreach ([$nest(1001), str_repeat('[', 200000)] as $pattern) {
            try {
                new Corvus\RegexEcma262\Regex($pattern, 'v');
                $out[] = 'compiled';
            } catch (Corvus\RegexEcma262\PatternTooLargeException) {
                $out[] = 'too large';
            }
        }
        echo implode(', ', $out);
        CODE;
    $command = 'ulimit -s 256 && exec ' . escapeshellarg(PHP_BINARY) . ' -n -d ' . escapeshellarg("extension=$extension")
        . ' -r ' . escapeshellarg($code);
    exec('sh -c ' . escapeshellarg($command) . ' 2>&1', $lines, $status);
    check('small stack, exit status', 0, $status);
    check('small stack', 'matched, too large, too large', implode("\n", $lines));
}

check('crate version', '0.2.1', \Corvus\RegexEcma262\crate_version());
check('extension loaded', true, extension_loaded('corvus_regex_ecma262'));

echo "$checks checks, " . count($failures) . " failed\n";
foreach ($failures as $f) {
    echo "  $f\n";
}
exit($failures ? 1 : 0);
