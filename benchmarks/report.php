<?php

declare(strict_types=1);

/*
 * Turns one or more phpbench XML dumps into Markdown tables with throughput and the subject's own memory.
 *
 *     docker compose run --rm php vendor/bin/phpbench run benchmarks --dump-file=var/bench-1.xml
 *     docker compose run --rm php vendor/bin/phpbench run benchmarks --dump-file=var/bench-2.xml
 *     docker compose run --rm php php benchmarks/report.php var/bench-1.xml var/bench-2.xml
 *
 * Iterations of the same variant from several dumps are pooled, so the ranges cover run-to-run noise too.
 *
 * Columns:
 * - time:      median time per revolution, [min – max] over all iterations of all dumps;
 * - nodes/s:   input nodes per second (parameter "nodes" / median time), with the range from max/min time;
 * - mem_peak:  phpbench's mem_peak (max): fixture + the subject's peak, as the fixture is built before the peak
 *              is reset;
 * - own peak:  median of mem_peak - mem_final: the subject's own working memory (its result is freed by then).
 */

if ($argc < 2) {
    fwrite(\STDERR, "usage: php benchmarks/report.php dump.xml [dump.xml …]\n");
    exit(1);
}

/** @var array<string, array<string, array{set: string, nodes: int|null, times: list<float>, peaks: list<int>, own: list<int>}>> $data */
$data = [];
$php = '';
foreach (array_slice($argv, 1) as $file) {
    $xml = simplexml_load_file($file);
    if ($xml === false) {
        fwrite(\STDERR, "cannot read $file\n");
        exit(1);
    }
    foreach ($xml->suite as $suite) {
        foreach ($suite->env->php->value as $value) {
            if ((string) $value['name'] === 'version') {
                $php = (string) $value;
            }
        }
        foreach ($suite->benchmark as $benchmark) {
            $class = substr(strrchr((string) $benchmark['class'], '\\') ?: '', 1);
            foreach ($benchmark->subject as $subject) {
                foreach ($subject->variant as $variant) {
                    $set = (string) $variant->{'parameter-set'}['name'];
                    $nodes = null;
                    foreach ($variant->{'parameter-set'}->parameter as $parameter) {
                        if ((string) $parameter['name'] === 'nodes') {
                            $nodes = (int) $parameter['value'];
                        }
                    }
                    $key = $subject['name'] . "\0" . $set;
                    $data[$class][$key] ??= ['set' => $set, 'nodes' => $nodes, 'times' => [], 'peaks' => [], 'own' => []];
                    foreach ($variant->iteration as $iteration) {
                        $data[$class][$key]['times'][] = (float) $iteration['time-avg'];
                        $data[$class][$key]['peaks'][] = (int) $iteration['mem-peak'];
                        $data[$class][$key]['own'][] = max(0, (int) $iteration['mem-peak'] - (int) $iteration['mem-final']);
                    }
                }
            }
        }
    }
}

/**
 * @param list<float|int> $values
 */
function median(array $values): float
{
    sort($values);
    $n = count($values);

    return $n % 2 === 1 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

function duration(float $microseconds): string
{
    return match (true) {
        $microseconds >= 1_000_000 => sprintf('%.2f s', $microseconds / 1_000_000),
        $microseconds >= 1_000 => sprintf('%.1f ms', $microseconds / 1_000),
        default => sprintf('%.0f µs', $microseconds),
    };
}

function bytes(float $bytes): string
{
    return match (true) {
        $bytes >= 1_048_576 => sprintf('%.1f MiB', $bytes / 1_048_576),
        $bytes >= 1_024 => sprintf('%.0f KiB', $bytes / 1_024),
        default => sprintf('%.0f B', $bytes),
    };
}

function rate(float $perSecond): string
{
    return $perSecond >= 1_000_000 ? sprintf('%.2fM', $perSecond / 1_000_000) : sprintf('%.0fk', $perSecond / 1_000);
}

echo "PHP $php, opcache on, JIT off. Times per revolution: median [min – max] over all iterations.\n\n";
foreach ($data as $class => $variants) {
    echo "### $class\n\n";
    echo "| subject | set | time | nodes/s | mem_peak | own peak |\n";
    echo "|---|---|---|---|---|---|\n";
    foreach ($variants as $key => $row) {
        $subject = substr(explode("\0", $key)[0], 5);
        $time = median($row['times']);
        $min = min($row['times']);
        $max = max($row['times']);
        $throughput = $row['nodes'] === null || $time <= 0
            ? '–'
            : sprintf('%s [%s – %s]', rate($row['nodes'] / $time * 1e6), rate($row['nodes'] / $max * 1e6), rate($row['nodes'] / $min * 1e6));
        printf(
            "| %s | %s | %s [%s – %s] | %s | %s | %s |\n",
            $subject,
            $row['set'],
            duration($time),
            duration($min),
            duration($max),
            $throughput,
            bytes(max($row['peaks'])),
            bytes(median($row['own'])),
        );
    }
    echo "\n";
}
