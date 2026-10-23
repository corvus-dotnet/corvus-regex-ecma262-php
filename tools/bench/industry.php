<?php
// The Industry benchmark from PHP: each workload counted by each engine, in nanoseconds per count, under the
// interleaved protocol every harness of the central benchmark page follows (docs/benchmarks/README.md in
// corvus-regex-ecma262-rs), a port of the C harness's driver (tools/c/industry.c there):
//
//   php tools/bench/industry.php <workloads.json> [--engines corvus-utf8,corvus-utf16,pcre,v8] [--v8 <industry.mjs>]
//       [--rounds 3] [--seconds 0.7] [--passes 7] [--spread 0.05] [--filter <substring of the id>] [--out <json>]
//
// The workloads are the outer loop, each workload's rounds next and the engines innermost, their order turned by one
// each round, so that a workload's rounds are seconds apart, as its engines are. Each figure is a child process of its
// own (`industry.php --one`, or `node industry.mjs --one` for V8), which warms for two fifths of its seconds, then
// times `passes` passes of as many counts as fit the rest and reports their median. A workload's figure for an engine
// is the median of its rounds, with the fastest and the slowest round as its spread; a figure whose spread, (slowest -
// fastest) / median, is over --spread is listed in the run's facts (spread_over) and on stderr. A figure that does not
// finish in 30 s is stopped and recorded as giving no result within 30 s, and is not run in the workload's later
// rounds.
//
// The engines, each counting the matches of a global search as a PHP program counts them:
// - corvus-utf8 and corvus-utf16: this extension with the workload's flags and u, over the text as a string and as
//   Utf16 text, with Regex::countMatches;
// - pcre: PHP's PCRE functions, which do not claim ECMA-262, given the pattern as it is with the flags i, m and s and
//   in UTF mode (u), with preg_match_all;
// - v8: Node's RegExp (--v8), with gu added.
//
// The texts are those of the reference run, in CORVUS_REGEX_TEXTS (or ~/.local/share/corvus-regex-benchmarks); the
// run unzips each once into a directory of its own (CORVUS_REGEX_PLAIN), which the children read. POSIX only: the
// benchmark runs on Linux, in the image.
declare(strict_types=1);

use Corvus\RegexEcma262\{Regex, Utf16};

const NOT_ECMA262 = ['pcre'];
const LABELS = ['corvus-utf8' => 'corvus-regex-ecma262 UTF-8', 'corvus-utf16' => 'corvus-regex-ecma262 UTF-16',
    'pcre' => 'PCRE', 'v8' => 'V8'];

function text_dir(): string
{
    return getenv('CORVUS_REGEX_TEXTS') ?: (getenv('HOME') . '/.local/share/corvus-regex-benchmarks');
}

function load_text(string $file): string
{
    $plain = getenv('CORVUS_REGEX_PLAIN');
    if ($plain) {
        $path = $plain . '/' . preg_replace('/\.gz$/', '', $file);
        if (is_file($path)) {
            return file_get_contents($path);
        }
    }
    $s = gzdecode(file_get_contents(text_dir() . '/' . $file));
    return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
}

function flags_u(string $flags): string
{
    return preg_match('/[uv]/', $flags) ? $flags : $flags . 'u';
}

/** Warms, then times passes: [median, fastest, slowest] in nanoseconds per count, the count, and the counts timed. */
function measure(float $seconds, int $passes, callable $count): array
{
    $expected = $count();
    $warmEnd = hrtime(true) + $seconds * 0.4 * 1e9;
    $reps = 0;
    while (hrtime(true) < $warmEnd) {
        if ($count() !== $expected) {
            throw new RuntimeException('the count changed');
        }
        $reps++;
    }
    $perPass = max(1, (int) round($reps * 0.6 / $passes));
    $times = [];
    for ($p = 0; $p < $passes; $p++) {
        $t0 = hrtime(true);
        for ($k = 0; $k < $perPass; $k++) {
            $count();
        }
        $times[] = (hrtime(true) - $t0) / $perPass;
    }
    sort($times);
    return [$times[intdiv($passes, 2)], $times[0], $times[$passes - 1], $expected, $perPass * $passes];
}

function one(string $path, int $index, string $engine, float $seconds, int $passes): array
{
    $workloads = json_decode(file_get_contents($path), true);
    if ($index >= count($workloads)) {
        return ['error' => "no workload $index"];
    }
    $w = $workloads[$index];
    $text = load_text($w['file']);
    try {
        switch ($engine) {
            case 'corvus-utf8':
            case 'corvus-utf16':
                $re = new Regex($w['pattern'], flags_u($w['flags']));
                $subject = $engine === 'corvus-utf8' ? $text : Utf16::fromUtf8($text);
                $count = fn() => $re->countMatches($subject);
                break;
            case 'pcre':
                $modifiers = 'u' . implode('', array_intersect(str_split($w['flags']), ['i', 'm', 's']));
                $pattern = '/' . str_replace('/', '\/', $w['pattern']) . '/' . $modifiers;
                if (@preg_match($pattern, '') === false) {
                    return ['error' => preg_last_error_msg()];
                }
                $count = function () use ($pattern, $text): int {
                    $n = preg_match_all($pattern, $text);
                    if ($n === false) {
                        throw new RuntimeException(preg_last_error_msg());
                    }
                    return $n;
                };
                break;
            default:
                return ['error' => "unknown engine $engine"];
        }
        [$median, $low, $high, $expected, $reps] = measure($seconds, $passes, $count);
    } catch (Throwable $e) {
        return ['error' => get_class($e) . ': ' . $e->getMessage()];
    }
    return ['median' => round($median, 1), 'min' => round($low, 1), 'max' => round($high, 1), 'count' => $expected,
        'reps' => $reps];
}

/** A figure stopped at the limit, which the page shows as giving no result within 30 s. */
const STOPPED = ['error' => 'did not produce a result within 30 s', 'limit' => 30];

/** One figure in a child process: the child's line of JSON, or one with the error. */
function figure(?string $v8, string $path, int $index, string $spec, string $seconds, string $passes): array
{
    [$engine, $program] = array_pad(explode('@', $spec, 2), 2, null);
    if ($engine === 'v8') {
        $cmd = ['node', $v8, '--one', $path, (string) $index, $seconds, $passes];
    } elseif ($program) {
        $cmd = [$program, '--one', $path, (string) $index, $engine, $seconds, $passes];
    } else {
        $ext = getenv('CORVUS_REGEX_PHP_EXTENSION');
        $cmd = array_merge([PHP_BINARY], $ext ? ["-dextension=$ext"] : [], [__FILE__, '--one', $path, (string) $index,
            $engine, $seconds, $passes]);
    }
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    stream_set_blocking($pipes[1], false);
    $out = '';
    $deadline = microtime(true) + 30;
    while (true) {
        $read = [$pipes[1]];
        $write = $except = null;
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            proc_terminate($proc, 9);
            proc_close($proc);
            return STOPPED;
        }
        if (stream_select($read, $write, $except, (int) ceil($left)) > 0) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === '' || $chunk === false) {
                if (feof($pipes[1])) {
                    break;
                }
                continue;
            }
            $out .= $chunk;
        }
    }
    fclose($pipes[1]);
    proc_close($proc);
    $lines = array_values(array_filter(explode("\n", trim($out))));
    if (!$lines) {
        return ['error' => 'the process ended without figures'];
    }
    $line = json_decode(end($lines), true);
    return is_array($line) ? $line : ['error' => 'the process wrote no figures'];
}

function field(string $path, string $prefix): string
{
    foreach (@file($path) ?: [] as $line) {
        if (str_starts_with($line, $prefix)) {
            return trim(explode(':', $line, 2)[1] ?? '');
        }
    }
    return '';
}

function main(array $argv): int
{
    if (($argv[1] ?? '') === '--one') {
        [, , $path, $index, $engine, $seconds, $passes] = $argv;
        echo json_encode(one($path, (int) $index, $engine, (float) $seconds, (int) $passes)), "\n";
        return 0;
    }
    $path = $argv[1];
    $opts = ['rounds' => '3', 'seconds' => '0.7', 'passes' => '7', 'spread' => '0.05'];
    for ($i = 2; $i + 1 < count($argv); $i += 2) {
        $opts[ltrim($argv[$i], '-')] = $argv[$i + 1];
    }
    $engines = isset($opts['engines']) ? explode(',', $opts['engines'])
        : array_merge(['corvus-utf8', 'corvus-utf16', 'pcre'], isset($opts['v8']) ? ['v8'] : []);
    $rounds = (int) $opts['rounds'];
    $limit = (float) $opts['spread'];
    $workloads = json_decode(file_get_contents($path), true);
    $chosen = array_keys(array_filter($workloads, fn($w) => !isset($opts['filter']) || str_contains($w['id'], $opts['filter'])));

    // The texts unzipped once, for every child.
    $plain = sys_get_temp_dir() . '/corvus-regex-texts-php-' . getmypid();
    @mkdir($plain);
    foreach ($workloads as $w) {
        $file = $plain . '/' . preg_replace('/\.gz$/', '', $w['file']);
        if (!is_file($file)) {
            file_put_contents($file, load_text($w['file']));
        }
    }
    putenv("CORVUS_REGEX_PLAIN=$plain");

    $figures = array_fill_keys($engines, []);
    $v8Engine = '';
    foreach ($chosen as $c => $k) {
        for ($round = 0; $round < $rounds; $round++) {
            for ($turn = 0; $turn < count($engines); $turn++) {
                $e = $engines[($turn + $round) % count($engines)];
                // A figure stopped at the limit in an earlier round is not run again.
                if (($figures[$e][$k]['line'] ?? null) === STOPPED) {
                    continue;
                }
                $line = figure($opts['v8'] ?? null, $path, $k, $e, $opts['seconds'], $opts['passes']);
                $figures[$e][$k] ??= ['each' => [], 'line' => $line];
                $figures[$e][$k]['line'] = $line;
                if (isset($line['median'])) {
                    $figures[$e][$k]['each'][] = $line['median'];
                } else {
                    fprintf(STDERR, "%-13s %-62s %s\n", $e, $workloads[$k]['id'], json_encode($line));
                }
                if ($e === 'v8' && isset($line['engine'])) {
                    $v8Engine = $line['engine'];
                }
            }
        }
        fprintf(STDERR, "workload %d of %d done\n", $c + 1, count($chosen));
    }
    array_map('unlink', glob("$plain/*"));
    @rmdir($plain);

    $over = $differ = [];
    $results = [];
    foreach ($engines as $e) {
        $rows = [];
        foreach ($chosen as $k) {
            $fig = $figures[$e][$k] ?? ['each' => [], 'line' => []];
            $line = $fig['line'];
            $each = $fig['each'];
            $wid = $workloads[$k]['id'];
            if (count($each) < $rounds) {
                // A round with no figure (an error, a limit) makes the figure an error.
                $rows[$wid] = isset($line['error']) ? $line : ['error' => 'a round gave no figure'];
                continue;
            }
            $sorted = $each;
            sort($sorted);
            $n = count($sorted);
            $m = $n % 2 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2;
            $spread = $m > 0 ? ($sorted[$n - 1] - $sorted[0]) / $m : 0;
            if ($spread > $limit) {
                $over[] = sprintf('%s %s %.1f%%', $e, $wid, $spread * 100);
                fprintf(STDERR, "SPREAD %-13s %-62s %.1f%% over %.1f%%\n", $e, $wid, $spread * 100, $limit * 100);
            }
            $count = $line['count'] ?? null;
            $entry = ['median' => round($m, 1), 'min' => $sorted[0], 'max' => $sorted[$n - 1], 'count' => $count,
                'rounds' => $n, 'spread' => round($spread, 4), 'each' => $each];
            // Without V8 in the run (V8=0), its count as the workloads carry it (v8Count, from the central run).
            $oracle = isset($figures['v8']) ? ($figures['v8'][$k]['line']['count'] ?? null) : ($workloads[$k]['v8Count'] ?? null);
            if ($oracle !== null && $count !== $oracle && $e !== 'v8') {
                $differ[] = "$e $wid $count vs V8 $oracle";
                fprintf(STDERR, "COUNT  %-13s %-62s %s against V8's %s\n", $e, $wid, $count, $oracle);
                $entry = in_array($e, NOT_ECMA262, true) ? $entry + ['differs' => LABELS[$e] . " $count, V8 $oracle"]
                    : ['error' => "counted $count where V8 counts $oracle"];
            }
            $rows[$wid] = $entry;
        }
        $results[$e] = $rows;
    }
    $facts = [
        'cpu' => field('/proc/cpuinfo', 'model name'),
        'cores' => trim((string) shell_exec('nproc')),
        'affinity' => field('/proc/self/status', 'Cpus_allowed_list'),
        'os' => php_uname('s') . ' ' . php_uname('r'),
        'load' => explode(' ', (string) @file_get_contents('/proc/loadavg'))[0] ?? '',
        'php' => 'PHP ' . PHP_VERSION . (PHP_ZTS ? ' ZTS' : ' NTS'),
        'commit' => getenv('CORVUS_COMMIT') ?: trim((string) shell_exec('git rev-parse HEAD 2>/dev/null')),
        'date' => gmdate('Y-m-d\TH:i:s\Z'),
        'seconds' => $opts['seconds'], 'passes' => $opts['passes'],
        'runner' => getenv('RUNNER_NAME') ?: '', 'runner_os' => getenv('RUNNER_OS') ?: '',
        'image' => trim((getenv('ImageOS') ?: '') . ' ' . (getenv('ImageVersion') ?: '')),
        'corvus-regex-ecma262' => phpversion('corvus_regex_ecma262') ?: '',
        'pcre' => PCRE_VERSION,
        'rounds' => (string) $rounds, 'protocol' => 'interleaved', 'spread_limit' => (string) $limit,
        'spread_over' => implode('; ', $over), 'counts_differ' => implode('; ', $differ), 'v8' => $v8Engine,
    ];
    $data = json_encode(['facts' => $facts, 'end' => gmdate('Y-m-d\TH:i:s\Z'), 'results' => $results],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (isset($opts['out'])) {
        file_put_contents($opts['out'], $data);
    } else {
        echo $data, "\n";
    }
    return 0;
}

exit(main($argv));
