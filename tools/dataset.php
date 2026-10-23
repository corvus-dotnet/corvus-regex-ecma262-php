<?php
// Runs this extension over the ECMA-262 regular expression test data (github.com/corvus-dotnet/ecma262-regex-test-data),
// judged by the data's own rule ("How a runner judges an engine" in its README) exactly as the C library's runner
// judges it:
//
//   php -d extension=corvus_regex_ecma262 tools/dataset.php <data directory> <wrong-cases file> [--files <glob>]
//       [--workers N]
//
// Each record is asked about under each grammar V8 found it valid in: the record's flags with u added (the unicode
// answers), the record's flags alone (the legacy answers) and, in the file that has them, the flags with v added (the
// unicode_sets answers). A text is matched as Utf16 text (positions in code units, as the data gives them) and as a
// UTF-8 string (positions in bytes); a text with a lone surrogate has no UTF-8 form and is matched as Utf16 alone.
// Records marked v8_deviation are left out. Any difference from V8 is a wrong answer; a refusal of a valid pattern, an
// acceptance of an invalid one, and a match that reaches its limit are counted apart and listed too. captures.jsonl is
// judged on exec, the global search, the sticky flag, replace and split. A match is given 2 seconds.
// ill-formed-utf8.jsonl holds bytes that are not UTF-8, each one answer over UTF-8 under each grammar the pattern is
// valid in, whose right answer is a refusal: the extension's ValueError for a string that is not UTF-8.
//
// PHP's json_decode refuses a lone surrogate, which the data holds, so each lone surrogate escape is read as a code
// point of a block of 2,048 private use code points (the block's first + its offset from U+D800) and turned back into
// its code unit wherever the text is used. The block is chosen for each line from the blocks of Planes 15 and 16: the
// first whose code points the line does not hold, which is known when the line, read with that block, holds as many
// of them as it had lone surrogate escapes. So no character of the data is taken for a lone surrogate (test262's
// texts hold the ends of the private use ranges). The workers are child processes of
// this script, each given a share of a file's records.
declare(strict_types=1);

use Corvus\RegexEcma262\{InvalidPatternException, MatchLimitException, MatchResult, PatternTooLargeException, Regex,
    RegexException, Utf16};

const LIMIT = 2.0;
const REPLACEMENT = '<$&|$1|$2|$10|$<a>|$$|$`|$\'>';
const SURROGATE_ESCAPE = '/\\\\\\\\|\\\\u(d[89ab][0-9a-f]{2})\\\\u(d[c-f][0-9a-f]{2})|\\\\u(d[89a-f][0-9a-f]{2})/i';

/** The first code point of the block the lone surrogates of the line being judged are read as. */
$BLOCK = 0xF0000;

/** The record of a line, its lone surrogates read as code points of the first block the line does not use. */
function read_record(string $line): array
{
    global $BLOCK;
    $blocks = array_merge(range(0xF0000, 0xFF000, 0x800), range(0x100000, 0x10F000, 0x800));
    foreach ($blocks as $block) {
        $written = 0;
        $rewritten = preg_replace_callback(SURROGATE_ESCAPE, function (array $m) use ($block, &$written): string {
            if (!isset($m[3]) || $m[3] === '') {
                return $m[0];
            }
            $written++;
            $v = $block + hexdec($m[3]) - 0xD800 - 0x10000;
            return sprintf('\\u%04x\\u%04x', 0xD800 + ($v >> 10), 0xDC00 + ($v & 0x3FF));
        }, $line);
        $r = json_decode($rewritten, true, 512, JSON_THROW_ON_ERROR);
        if (count_in($r, $block) === $written) {
            $BLOCK = $block;
            return $r;
        }
    }
    throw new RuntimeException('every block of Planes 15 and 16 is used by the line');
}

/** How many code points of a block the strings of a value hold. */
function count_in(mixed $value, int $block): int
{
    if (is_string($value)) {
        $n = 0;
        foreach (mb_str_split($value, 1, 'UTF-8') as $c) {
            $cp = mb_ord($c, 'UTF-8');
            if ($cp >= $block && $cp < $block + 0x800) {
                $n++;
            }
        }
        return $n;
    }
    if (is_array($value)) {
        $n = 0;
        foreach ($value as $k => $v) {
            $n += (is_string($k) ? count_in($k, $block) : 0) + count_in($v, $block);
        }
        return $n;
    }
    return 0;
}

/** The lone surrogates of the line being judged, as a character class of their block. */
function lone_class(): string
{
    global $BLOCK;
    return sprintf('/[\\x{%X}-\\x{%X}]/u', $BLOCK, $BLOCK + 0x7FF);
}

/** The UTF-16 code units of a text as read. */
function units(string $s): array
{
    global $BLOCK;
    $out = [];
    foreach (mb_str_split($s, 1, 'UTF-8') as $c) {
        $cp = mb_ord($c, 'UTF-8');
        if ($cp >= $BLOCK && $cp < $BLOCK + 0x800) {
            $out[] = 0xD800 + $cp - $BLOCK;
        } elseif ($cp > 0xFFFF) {
            $v = $cp - 0x10000;
            $out[] = 0xD800 + ($v >> 10);
            $out[] = 0xDC00 + ($v & 0x3FF);
        } else {
            $out[] = $cp;
        }
    }
    return $out;
}

function utf16(string $s): Utf16
{
    return new Utf16(pack('v*', ...units($s)));
}

function utf16_bytes(string $s): string
{
    return pack('v*', ...units($s));
}

function lone(string $s): bool
{
    return (bool) preg_match(lone_class(), $s);
}

/** UTF-8, a lone surrogate as U+FFFD (as String::from_utf16_lossy writes it). */
function utf8_lossy(string $s): string
{
    return preg_replace(lone_class(), "\u{FFFD}", $s);
}

function escape(string $s): string
{
    $out = '';
    foreach (units($s) as $u) {
        $out .= ($u >= 0x20 && $u < 0x7F && $u !== 0x5C) ? chr($u) : sprintf('\\u%04x', $u);
    }
    return $out;
}

/** The pattern as the Rust runner shows it: through UTF-8 (a lone surrogate as U+FFFD), escaped. */
function shown(string $pattern): string
{
    return escape(utf8_lossy($pattern));
}

/** For each UTF-16 code unit index of a text (and its end), the UTF-8 byte offset; the low half of a pair is its
 * character's third byte. */
function byte_offsets(string $s): array
{
    $at = [];
    $b = 0;
    foreach (mb_str_split($s, 1, 'UTF-8') as $c) {
        if (mb_ord($c, 'UTF-8') > 0xFFFF) {
            $at[] = $b;
            $at[] = $b + 2;
        } else {
            $at[] = $b;
        }
        $b += strlen($c);
    }
    $at[] = $b;
    return $at;
}

final class Counts
{
    public int $right = 0, $wrong = 0, $refused = 0, $accepted = 0, $limited = 0, $errors = 0;

    public function add(array $c): void
    {
        foreach (['right', 'wrong', 'refused', 'accepted', 'limited', 'errors'] as $k) {
            $this->$k += $c[$k];
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}

/** [regex or null, message, is_error] */
function compile_pattern(string $pattern, string $flags): array
{
    try {
        return [new Regex(utf8_lossy($pattern), $flags), null, false];
    } catch (InvalidPatternException $e) {
        return [null, 'PARSE: ' . $e->getMessage(), false];
    } catch (PatternTooLargeException $e) {
        return [null, 'TOO-LARGE: ' . $e->getMessage(), false];
    } catch (RegexException $e) {
        return [null, 'ERROR: ' . $e->getMessage(), true];
    }
}

function modes_of(array $r): array
{
    $valid = $r['valid'] ?? [];
    $modes = [['u', 'unicode', ($valid['unicode'] ?? false) === true], ['', 'legacy', ($valid['legacy'] ?? false) === true]];
    if (array_key_exists('unicode_sets', $valid)) {
        $modes[] = ['v', 'unicode_sets', $valid['unicode_sets'] === true];
    }
    return $modes;
}

function judge_tests(string $name, array $r, string $pattern, Counts $c, array &$w): void
{
    $flags = (string) ($r['flags'] ?? '');
    $headPattern = shown($pattern);
    foreach (modes_of($r) as [$suffix, $answer, $valid]) {
        $modeFlags = $flags . $suffix;
        $head = "$name\t/$headPattern/$modeFlags";
        [$re, $message, $isError] = compile_pattern($pattern, $modeFlags);
        if ($re === null) {
            if ($isError) {
                $c->errors++;
            }
            if ($valid) {
                $c->refused++;
                $w[] = "REFUSED\t$head\t" . strtok((string) $message, "\r\n");
            }
            continue;
        }
        if (!$valid) {
            $c->accepted++;
            $w[] = "ACCEPTED-INVALID\t$head";
            continue;
        }
        foreach ($r['tests'] ?? [] as $test) {
            $expected = $test[$answer] ?? null;
            $text = $test['text'] ?? null;
            if (!is_bool($expected) || $text === null) {
                continue;
            }
            foreach (['utf16', 'utf8'] as $enc) {
                if ($enc === 'utf8' && lone($text)) {
                    continue;
                }
                $subject = $enc === 'utf16' ? utf16($text) : $text;
                try {
                    $got = $re->test($subject, 0, LIMIT);
                } catch (MatchLimitException $e) {
                    $c->limited++;
                    $why = str_contains($e->getMessage(), 'memory') ? 'memory' : 'time';
                    $w[] = "LIMIT\t$head\t" . escape($text) . "\t$enc\t$why";
                    continue;
                }
                if ($got === $expected) {
                    $c->right++;
                } else {
                    $c->wrong++;
                    $w[] = "WRONG\t$head\t" . escape($text) . "\t$enc\texpected " . ($expected ? 'true' : 'false');
                }
            }
        }
    }
}

function judge_ill_formed(array $r, string $pattern, Counts $c, array &$w): void
{
    $name = 'ill-formed-utf8.jsonl';
    $flags = (string) ($r['flags'] ?? '');
    $headPattern = shown($pattern);
    foreach (modes_of($r) as [$suffix, , $valid]) {
        $modeFlags = $flags . $suffix;
        $head = "$name\t/$headPattern/$modeFlags";
        [$re, $message, $isError] = compile_pattern($pattern, $modeFlags);
        if ($re === null) {
            if ($isError) {
                $c->errors++;
            }
            if ($valid) {
                $c->refused++;
                $w[] = "REFUSED\t$head\t" . strtok((string) $message, "\r\n");
            }
            continue;
        }
        if (!$valid) {
            $c->accepted++;
            $w[] = "ACCEPTED-INVALID\t$head";
            continue;
        }
        foreach ($r['tests'] ?? [] as $test) {
            $hex = $test['bytes'];
            try {
                $got = $re->test(hex2bin($hex), 0, LIMIT);
            } catch (MatchLimitException $e) {
                $c->limited++;
                $why = str_contains($e->getMessage(), 'memory') ? 'memory' : 'time';
                $w[] = "LIMIT\t$head\t$hex\tutf8\t$why";
                continue;
            } catch (ValueError $e) {
                if (str_contains($e->getMessage(), 'UTF-8')) {
                    $c->right++;
                } else {
                    $c->errors++;
                    $w[] = "ERROR\t$head\t$hex\tutf8\t" . get_class($e) . ': ' . $e->getMessage();
                }
                continue;
            }
            $c->wrong++;
            $w[] = "WRONG\t$head\t$hex\tutf8\tread as UTF-8 (" . ($got ? 'true' : 'false') . '), not refused';
        }
    }
}

/** Whether a match's groups are the expected [start, end] pairs (null for a group that did not take part), the
 * expected positions mapped by $map (identity when null). */
function same_slots(?array $expected, ?MatchResult $m, ?array $map): bool
{
    if ($expected === null || $m === null) {
        return $expected === null && $m === null;
    }
    if (count($expected) !== $m->count()) {
        return false;
    }
    foreach ($expected as $g => $e) {
        $got = [$m->start($g), $m->end($g)];
        if ($e === null) {
            if ($got !== [null, null]) {
                return false;
            }
        } elseif ($got !== ($map ? [$map[$e[0]], $map[$e[1]]] : $e)) {
            return false;
        }
    }
    return true;
}

function judge_all(array $matches, array $all, ?array $map): bool
{
    $n = 0;
    foreach ($matches as $m) {
        if ($n >= count($all)) {
            break;
        }
        if ($all[$n] === null) {
            return false;
        }
        [$s, $t] = $all[$n][0];
        if ([$m->start(), $m->end()] !== ($map ? [$map[$s], $map[$t]] : [$s, $t])) {
            return false;
        }
        $n++;
    }
    return !((count($all) < 20 && $n !== count($all)) || (count($all) === 20 && $n < 20));
}

function same_split($expected, array $got): bool
{
    if (!is_array($expected) || count($expected) !== count($got)) {
        return false;
    }
    foreach ($expected as $k => $e) {
        if ($e === null) {
            if ($got[$k] !== null) {
                return false;
            }
        } elseif ($got[$k] === null || $got[$k]->bytes() !== utf16_bytes($e)) {
            return false;
        }
    }
    return true;
}

function judge_mode(string $where, array $a, string $text, Regex $re, Regex $g, Regex $y, Counts $c, array &$w): void
{
    $check = function (string $what, bool $ok) use ($c, &$w, $where): void {
        if ($ok) {
            $c->right++;
        } else {
            $c->wrong++;
            $w[] = "WRONG\tcaptures.jsonl\t$where\t$what";
        }
    };
    $u16 = utf16($text);
    $hasLone = lone($text);
    $byteAt = $hasLone ? null : byte_offsets($text);
    $exec = $a['exec'] ?? null;
    try {
        $m = $re->exec($u16, 0, LIMIT);
    } catch (MatchLimitException) {
        $c->limited++;
        $w[] = "LIMIT\tcaptures.jsonl\t$where";
        return;
    }
    $check('exec', same_slots($exec, $m, null));
    if (!$hasLone) {
        $check('exec-utf8', same_slots($exec, $re->exec($text), $byteAt));
    }

    $all = $a['all'] ?? [];
    $ok = true;
    $n = 0;
    $found = $g->matchAll($u16);
    foreach ($found as $mm) {
        if ($n >= count($all)) {
            break;
        }
        if (!same_slots($all[$n], $mm, null)) {
            $ok = false;
            break;
        }
        $n++;
    }
    if ((count($all) < 20 && $n !== count($all)) || (count($all) === 20 && $n < 20)) {
        $ok = false;
    }
    $check('all', $ok && judge_all($found, $all, null));
    if (!$hasLone) {
        $check('all-utf8', judge_all($g->matchAll($text), $all, $byteAt));
    }

    if ($text !== '') {
        $sticky = $a['sticky'] ?? null;
        $check('sticky', same_slots($sticky, $y->exec($u16, 1), null));
        if (!$hasLone && ($byteAt[1] >= strlen($text) || (ord($text[$byteAt[1]]) & 0xC0) !== 0x80)) {
            $check('sticky-utf8', same_slots($sticky, $y->exec($text, $byteAt[1]), $byteAt));
        }
    }

    $replace = $a['replace'] ?? null;
    $replaceAll = $a['replaceAll'] ?? null;
    $check('replace', $replace !== null && $re->replace($u16, REPLACEMENT)->bytes() === utf16_bytes($replace));
    $check('replaceAll', $replaceAll !== null && $g->replace($u16, REPLACEMENT)->bytes() === utf16_bytes($replaceAll));
    if (!$hasLone) {
        $check('replace-utf8', $replace !== null && $re->replace($text, REPLACEMENT) === utf8_lossy($replace));
        $check('replaceAll-utf8', $replaceAll !== null && $g->replace($text, REPLACEMENT) === utf8_lossy($replaceAll));
    }

    $check('split', same_split($a['split'] ?? null, $re->split($u16)));
    $check('split3', same_split($a['split3'] ?? null, $re->split($u16, 3)));
}

function judge_captures(array $r, string $pattern, Counts $c, array &$w): void
{
    $flags = (string) ($r['flags'] ?? '');
    $valid = $r['valid'] ?? [];
    $headPattern = shown($pattern);
    $cache = [];
    $make = function (string $f) use (&$cache, $pattern): ?Regex {
        if (!array_key_exists($f, $cache)) {
            $cache[$f] = compile_pattern($pattern, $f)[0];
        }
        return $cache[$f];
    };
    foreach ($r['tests'] ?? [] as $test) {
        if (!empty($test['v8_deviation'])) {
            continue;
        }
        $text = $test['text'] ?? null;
        if ($text === null) {
            continue;
        }
        foreach (['unicode', 'legacy'] as $mode) {
            if (($valid[$mode] ?? false) !== true || !array_key_exists($mode, $test)) {
                continue;
            }
            $modeFlags = $flags . ($mode === 'unicode' ? 'u' : '');
            $where = "/$headPattern/$modeFlags\t" . escape($text);
            $re = $make($modeFlags);
            $g = $make($modeFlags . 'g');
            $y = $make($modeFlags . 'y');
            if ($re === null || $g === null || $y === null) {
                $c->refused++;
                $w[] = "REFUSED\tcaptures.jsonl\t$where";
                continue;
            }
            try {
                judge_mode($where, $test[$mode], $text, $re, $g, $y, $c, $w);
            } catch (Throwable $e) {
                $c->errors++;
                $w[] = "ERROR\tcaptures.jsonl\t$where\t" . get_class($e) . ': ' . $e->getMessage();
            }
        }
    }
}

/** Judges the lines of a file whose index is $k modulo $n: [counts, wrong, records, skipped]. */
function judge_lines(string $name, array $lines, int $k, int $n): array
{
    $c = new Counts();
    $w = [];
    $records = $skipped = 0;
    foreach ($lines as $i => $line) {
        if ($i % $n !== $k || trim($line) === '') {
            continue;
        }
        $r = read_record($line);
        if (!empty($r['v8_deviation'])) {
            $skipped++;
            continue;
        }
        $records++;
        $pattern = $r['pattern'] ?? null;
        if ($pattern === null) {
            continue;
        }
        if ($name === 'captures.jsonl') {
            judge_captures($r, $pattern, $c, $w);
        } elseif ($name === 'ill-formed-utf8.jsonl') {
            judge_ill_formed($r, $pattern, $c, $w);
        } else {
            judge_tests($name, $r, $pattern, $c, $w);
        }
    }
    return [$c->toArray(), $w, $records, $skipped];
}

function main(array $argv): int
{
    // A worker: php dataset.php --child <file> <k> <n>, writing its results as one serialized line.
    if (($argv[1] ?? '') === '--child') {
        [, , $file, $k, $n] = $argv;
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        echo serialize(judge_lines(basename($file), $lines, (int) $k, (int) $n));
        return 0;
    }
    $data = $argv[1] ?? null;
    $wrongPath = $argv[2] ?? null;
    if ($wrongPath === null) {
        fwrite(STDERR, "usage: php tools/dataset.php <data directory> <wrong-cases file> [--files glob] [--workers N]\n");
        return 2;
    }
    $glob = '*.jsonl';
    $workers = 4;
    for ($i = 3; $i + 1 < count($argv); $i += 2) {
        match ($argv[$i]) {
            '--files' => $glob = $argv[$i + 1],
            '--workers' => $workers = (int) $argv[$i + 1],
        };
    }
    $files = array_values(array_filter(scandir($data), fn($f) => str_ends_with($f, '.jsonl') && fnmatch($glob, $f)));
    sort($files);
    $total = new Counts();
    $wrong = [];
    $records = $skipped = 0;
    $start = microtime(true);
    // The children load the extension as this process did.
    $php = [PHP_BINARY];
    foreach (ini_get_all(null, false) as $key => $value) {
        if ($key === 'extension_dir') {
            $php[] = "-d$key=$value";
        }
    }
    // The extension to load: CORVUS_REGEX_PHP_EXTENSION (a path) where this process was given one with -d.
    $extensionArg = getenv('CORVUS_REGEX_PHP_EXTENSION') ?: 'corvus_regex_ecma262';
    foreach ($files as $name) {
        $file = "$data/$name";
        $procs = [];
        for ($k = 0; $k < $workers; $k++) {
            $cmd = array_merge($php, ["-dextension=$extensionArg", "-dmemory_limit=-1", __FILE__, '--child', $file, (string) $k, (string) $workers]);
            $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
            $outs[$k] = $pipes[1];
        }
        $fileTotal = new Counts();
        foreach ($procs as $k => $proc) {
            $result = unserialize(stream_get_contents($outs[$k]));
            fclose($outs[$k]);
            proc_close($proc);
            if (!is_array($result)) {
                fwrite(STDERR, "a worker on $name gave no results\n");
                return 2;
            }
            [$c, $w, $rec, $skip] = $result;
            $fileTotal->add($c);
            array_push($wrong, ...$w);
            $records += $rec;
            $skipped += $skip;
        }
        printf("%-24s right %10d  wrong %6d  refused-valid %4d  accepted-invalid %4d  limit %4d  %6.1fs\n",
            $name, $fileTotal->right, $fileTotal->wrong, $fileTotal->refused, $fileTotal->accepted, $fileTotal->limited,
            microtime(true) - $start);
        $total->add($fileTotal->toArray());
    }
    sort($wrong);
    file_put_contents($wrongPath, implode('', array_map(fn($l) => "$l\n", $wrong)));
    printf("\nrecords %d (skipped %d deviations)  right %d  wrong %d  refused-valid %d  accepted-invalid %d  limit %d  errors %d  in %.1fs\n",
        $records, $skipped, $total->right, $total->wrong, $total->refused, $total->accepted, $total->limited,
        $total->errors, microtime(true) - $start);
    return $total->wrong + $total->refused + $total->accepted + $total->errors + $total->limited === 0 ? 0 : 1;
}

exit(main($argv));
