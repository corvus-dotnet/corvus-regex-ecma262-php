<?php
// The examples of the getting-started page the documentation site publishes (docs/getting-started.md), run as tests:
// each block of PHP runs on its own, with assertions on, so that the page shows only code that works. Run it as
// tests/api.php is run, with zend.assertions=1 and assert.exception=1.
declare(strict_types=1);

if (ini_get('zend.assertions') !== '1' || !ini_get('assert.exception')) {
    fwrite(STDERR, "Run with -d zend.assertions=1 -d assert.exception=1.\n");
    exit(1);
}

$page = file_get_contents(__DIR__ . '/../docs/getting-started.md');
preg_match_all('/^```php\n(.*?)^```$/ms', $page, $blocks, PREG_OFFSET_CAPTURE);
if (count($blocks[1]) < 5) {
    fwrite(STDERR, 'The page has ' . count($blocks[1]) . " examples.\n");
    exit(1);
}

$failures = 0;
foreach ($blocks[1] as $n => [$code, $offset]) {
    $line = substr_count($page, "\n", 0, $offset) + 1;
    $file = tempnam(sys_get_temp_dir(), 'getting-started');
    file_put_contents($file, "<?php\n" . $code);
    try {
        (static function (string $file): void {
            include $file;
        })($file);
        echo 'example ' . ($n + 1) . ": ok\n";
    } catch (Throwable $e) {
        $failures++;
        echo "docs/getting-started.md:$line: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    } finally {
        unlink($file);
    }
}
if ($failures > 0) {
    exit(1);
}
echo count($blocks[1]) . " getting-started examples ran.\n";
