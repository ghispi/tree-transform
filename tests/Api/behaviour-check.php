<?php

declare(strict_types=1);

/*
 * Compares the current behaviour with the snapshot of a release:
 *
 *     php tests/Api/behaviour-check.php <release snapshot> <release tag>
 *
 * Every case of the release snapshot must render the same today. New cases are fine. A changed or removed case fails,
 * unless tests/Api/behaviour-changes.txt lists it for that tag (a bug fix the changelog explains, or a major
 * release). Run through `make behaviour-check`.
 */

use Ghispi\TreeTransform\Tests\Api\Behaviour;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $snapshotFile, $tag] = $argv + [null, null, null];
if (!is_string($snapshotFile) || !is_string($tag) || !is_file($snapshotFile)) {
    fwrite(STDERR, "usage: php tests/Api/behaviour-check.php <release snapshot> <release tag>\n");
    exit(2);
}

$accepted = [];
$lines = file(__DIR__ . '/behaviour-changes.txt', FILE_IGNORE_NEW_LINES);
foreach ($lines === false ? [] : $lines as $line) {
    if (trim($line) === '' || str_starts_with($line, '#')) {
        continue;
    }
    $parts = array_map(trim(...), explode('|', $line, 3));
    if (count($parts) === 3 && $parts[0] === $tag) {
        $accepted[$parts[1]] = $parts[2];
    }
}

$released = Behaviour::parse((string) file_get_contents($snapshotFile));
$current = Behaviour::cases();
$failures = 0;
foreach ($released as $name => $before) {
    $after = $current[$name] ?? null;
    if ($after === $before) {
        continue;
    }
    if (isset($accepted[$name])) {
        echo "accepted: {$name} ({$accepted[$name]})\n";
        continue;
    }
    ++$failures;
    echo "changed since {$tag}: {$name}\n  before: {$before}\n  after:  " . ($after ?? '(case removed)') . "\n";
}

$added = count(array_diff_key($current, $released));
echo sprintf("%d released cases checked against %s, %d new, %d changed.\n", count($released), $tag, $added, $failures);
exit($failures === 0 ? 0 : 1);
