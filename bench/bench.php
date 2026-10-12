<?php

declare(strict_types=1);

use Miua\Wellset\Wellset;

require __DIR__ . '/../vendor/autoload.php';

$sample = (string) file_get_contents(__DIR__ . '/sample.cs.html');
$text = str_repeat($sample, intdiv(100 * 1024, strlen($sample)) + 1);
$runs = 200;

Wellset::apply($text, 'cs', html: true);
$start = hrtime(true);
for ($i = 0; $i < $runs; $i++) {
    Wellset::apply($text, 'cs', html: true);
}
$perRun = (hrtime(true) - $start) / $runs / 1e6;

printf("PHP %s, %d kB, %d runs: %.2f ms per run, %.1f MB/s\n", PHP_VERSION, intdiv(strlen($text), 1024), $runs, $perRun, strlen($text) / 1e6 / ($perRun / 1000));
