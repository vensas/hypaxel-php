<?php

declare(strict_types=1);

/*
 * Fails when the line coverage in a Clover report is below a threshold:
 *
 *   php tests/check-coverage.php build/clover.xml 80
 */

$report = $argv[1] ?? 'build/clover.xml';
$threshold = (float) ($argv[2] ?? 80);

$xml = is_file($report) ? simplexml_load_file($report) : false;
if ($xml === false) {
    fwrite(STDERR, "Cannot read coverage report {$report}\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$coverage = $statements === 0 ? 0.0 : $covered / $statements * 100;

printf("Line coverage: %.2f%% (%d/%d), required: %.2f%%\n", $coverage, $covered, $statements, $threshold);
exit($coverage >= $threshold ? 0 : 1);
