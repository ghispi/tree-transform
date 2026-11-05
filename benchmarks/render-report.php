<?php

declare(strict_types=1);

/*
 * Renders the result tables of docs/benchmarks.md from the phpbench dumps written by benchmarks/run-report.sh.
 *
 *     php benchmarks/render-report.php var/bench-report                      # Markdown to stdout
 *     php benchmarks/render-report.php var/bench-report docs/benchmarks.md   # replaces the block between
 *                                                                            # <!-- results:start --> and <!-- results:end -->
 *
 * Expects php<version>-jit-<off|on>.xml in the directory (any subset). Each iteration is one fresh process; the
 * time is phpbench's time-avg per revolution. Cells read "median ±rstdev", where rstdev is the standard deviation
 * of the iterations as a percentage of their mean.
 */

if ($argc < 2 || !is_dir($argv[1])) {
    fwrite(\STDERR, "usage: php benchmarks/render-report.php <dump-dir> [target.md]\n");
    exit(1);
}

/** @var array<string, array<string, array<string, array<string, array{times: list<float>, peaks: list<int>, own: list<int>, nodes: int|null}>>>> $data config => class => subject => set => samples */
$data = [];
$versions = [];
$files = glob($argv[1] . '/php*-jit-*.xml') ?: [];
natsort($files);
foreach ($files as $file) {
    preg_match('/php([\d.]+)-jit-(off|on)\.xml$/', $file, $m);
    $config = "PHP $m[1], JIT $m[2]";
    $xml = simplexml_load_file($file);
    foreach ($xml->suite as $suite) {
        foreach ($suite->env->php->value as $value) {
            if ((string) $value['name'] === 'version') {
                $versions[$config] = (string) $value;
            }
        }
        foreach ($suite->benchmark as $benchmark) {
            $class = substr((string) strrchr((string) $benchmark['class'], '\\'), 1);
            foreach ($benchmark->subject as $subject) {
                $name = substr((string) $subject['name'], 5);
                foreach ($subject->variant as $variant) {
                    $set = (string) $variant->{'parameter-set'}['name'];
                    $nodes = null;
                    foreach ($variant->{'parameter-set'}->parameter as $parameter) {
                        if ((string) $parameter['name'] === 'nodes') {
                            $nodes = (int) $parameter['value'];
                        }
                    }
                    $cell = &$data[$config][$class][$name][$set];
                    $cell ??= ['times' => [], 'peaks' => [], 'own' => [], 'nodes' => $nodes];
                    foreach ($variant->iteration as $iteration) {
                        $cell['times'][] = (float) $iteration['time-avg'];
                        $cell['peaks'][] = (int) $iteration['mem-peak'];
                        $cell['own'][] = max(0, (int) $iteration['mem-peak'] - (int) $iteration['mem-final']);
                    }
                    unset($cell);
                }
            }
        }
    }
}
if ($data === []) {
    fwrite(\STDERR, "no dumps found in $argv[1]\n");
    exit(1);
}
$configs = array_keys($data);
$first = $configs[0];

/** @param list<float|int> $v */
function median(array $v): float
{
    sort($v);
    $n = count($v);

    return $n % 2 === 1 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

/** @param list<float|int> $v */
function rstdev(array $v): float
{
    $n = count($v);
    $mean = array_sum($v) / $n;
    if ($n < 2 || $mean == 0) {
        return 0.0;
    }
    $var = array_sum(array_map(static fn($x) => ($x - $mean) ** 2, $v)) / ($n - 1);

    return sqrt($var) / $mean * 100;
}

function duration(float $us): string
{
    return match (true) {
        $us >= 1_000_000 => sprintf('%.2f s', $us / 1_000_000),
        $us >= 1_000 => sprintf('%.1f ms', $us / 1_000),
        default => sprintf('%.0f µs', $us),
    };
}

function bytes(float $b): string
{
    return match (true) {
        $b >= 1_048_576 => sprintf('%.1f MiB', $b / 1_048_576),
        $b >= 1_024 => sprintf('%.0f KiB', $b / 1_024),
        default => sprintf('%.0f B', $b),
    };
}

function rate(float $perSecond): string
{
    return $perSecond >= 1_000_000 ? sprintf('%.1fM', $perSecond / 1_000_000) : sprintf('%.0fk', $perSecond / 1_000);
}

/** @return array{times: list<float>, peaks: list<int>, own: list<int>, nodes: int|null}|null */
$get = static fn(string $config, string $class, string $subject, string $set) => $data[$config][$class][$subject][$set] ?? null;

$time = static function (?array $c): string {
    return $c === null ? '–' : sprintf('%s ±%.1f%%', duration(median($c['times'])), rstdev($c['times']));
};
$nps = static function (?array $c): string {
    return $c === null || $c['nodes'] === null ? '–' : rate($c['nodes'] / median($c['times']) * 1e6);
};
$row = static fn(array $cells): string => '| ' . implode(' | ', $cells) . " |\n";
$head = static fn(array $cells): string => $row($cells) . '|' . str_repeat('---|', count($cells)) . "\n";

$out = '';

// ---- run quality
$out .= "### Run quality\n\n";
$out .= "Relative standard deviation (rstdev) of the iterations of each variant, per configuration. Each iteration is a\n";
$out .= "fresh process; phpbench retried variants above 5 %.\n\n";
$out .= $head(['Configuration', 'PHP', 'Variants', 'Median rstdev', '90th percentile', 'Worst']);
foreach ($configs as $config) {
    $values = [];
    foreach ($data[$config] as $subjects) {
        foreach ($subjects as $sets) {
            foreach ($sets as $c) {
                $values[] = rstdev($c['times']);
            }
        }
    }
    sort($values);
    $out .= $row([$config, $versions[$config] ?? '?', (string) count($values), sprintf('%.1f%%', median($values)), sprintf('%.1f%%', $values[(int) floor(0.9 * (count($values) - 1))]), sprintf('%.1f%%', end($values))]);
}
$out .= "\n";

// ---- targets
$out .= "### Target 1: streaming memory does not grow with n\n\n";
$out .= "Rows come from a generator (balanced tree, branching 10), through the pipeline into `iterate()`. Peak memory of the\n";
$out .= "process (`mem_peak`, maximum over all iterations and both PHP versions), JIT off / JIT on (the JIT's compiler allocates\n";
$out .= "about 1.2 MiB of its own, independent of the tree):\n\n";
$sizes = array_keys($data[$first]['StreamingBench']['NestedSet'] ?? []);
$out .= $head(array_merge(['Pipeline'], array_map(static fn($s) => "$s nodes", $sizes)));
foreach (array_keys($data[$first]['StreamingBench'] ?? []) as $subject) {
    if ($subject === 'GeneratorOnly') {
        continue;
    }
    $cells = [$subject];
    foreach ($sizes as $size) {
        $parts = [];
        foreach (['off', 'on'] as $jit) {
            $peaks = [0];
            foreach ($configs as $config) {
                $c = str_ends_with($config, "JIT $jit") ? $get($config, 'StreamingBench', $subject, $size) : null;
                $peaks[] = $c === null ? 0 : max($c['peaks']);
            }
            $parts[] = bytes(max($peaks));
        }
        $cells[] = implode(' / ', $parts);
    }
    $out .= $row($cells);
}
$out .= "\n";

$out .= "### Target 2: Prune over an array nested set, nodes per second\n\n";
$out .= "`StrategyBench`: nested set rows → `Prune` → `count()`; `Prune 90 %` keeps about 90 % of the nodes (the realistic\n";
$out .= "\"hide unpublished\" filter), `Prune 10 %` about 10 %. Nodes per second counts every input row. Median of the\n";
$out .= "iterations; the time table further down has the spread.\n\n";
$out .= $head(array_merge(['Shape', 'Predicate'], $configs));
foreach (array_keys($data[$first]['StrategyBench']['PruneVisible'] ?? []) as $set) {
    foreach (['PruneVisible' => 'Prune 90 %', 'PruneHit' => 'Prune 10 %'] as $subject => $label) {
        $out .= $row(array_merge([$set, $label], array_map(static fn($config) => $nps($get($config, 'StrategyBench', $subject, $set)), $configs)));
    }
}
$out .= "\n";

$pairs = [
    'Nested set → `KeepAncestors` → nested array' => ['NaiveNestedSetKeepAncestors', 'LibraryNestedSetKeepAncestors'],
    'Adjacency list (any order) → `KeepAncestors` → nested array' => ['NaiveAdjacencyKeepAncestors', 'LibraryAdjacencyKeepAncestors'],
    'Adjacency list (any order) → `Prune` → nested array' => ['NaiveAdjacencyPrune', 'LibraryAdjacencyPrune'],
];
$out .= "### Targets 3 to 5: against the naive build-then-filter baseline\n\n";
$out .= "`NaiveBaselineBench`: same input rows, same output (checked by `benchmarks/verify.php`). Speed-up is naive time ÷\n";
$out .= "library time (above 1 means the library is faster); memory ratio is naive ÷ library own peak (`mem_peak − mem_final`).\n\n";
$out .= "**Speed-up**\n\n";
$out .= $head(array_merge(['Pipeline', 'Shape'], $configs));
foreach ($pairs as $label => [$naive, $lib]) {
    foreach (array_keys($data[$first]['NaiveBaselineBench'][$naive] ?? []) as $set) {
        $cells = [$label, $set];
        foreach ($configs as $config) {
            $n = $get($config, 'NaiveBaselineBench', $naive, $set);
            $l = $get($config, 'NaiveBaselineBench', $lib, $set);
            $cells[] = $n && $l ? sprintf('%.2f×', median($n['times']) / median($l['times'])) : '–';
        }
        $out .= $row($cells);
    }
}
$out .= "\n**Memory** (naive own peak / library own peak, PHP and JIT make no difference; shown for $first)\n\n";
$out .= $head(['Pipeline', 'Shape', 'Naive', 'Library', 'Ratio']);
foreach ($pairs as $label => [$naive, $lib]) {
    foreach (array_keys($data[$first]['NaiveBaselineBench'][$naive] ?? []) as $set) {
        $n = $get($first, 'NaiveBaselineBench', $naive, $set);
        $l = $get($first, 'NaiveBaselineBench', $lib, $set);
        if ($n && $l) {
            $out .= $row([$label, $set, bytes(median($n['own'])), bytes(median($l['own'])), sprintf('%.1f×', median($n['own']) / max(1, median($l['own'])))]);
        }
    }
}
$out .= "\n";

// ---- JIT effect
$out .= "### JIT effect\n\n";
$out .= "Median time with the JIT on ÷ median time with it off, per class, as the geometric mean over all variants of\n";
$out .= "at least 1 ms (below that, timer noise dominates). Below 1.00 the JIT helps.\n\n";
$out .= $head(['PHP', 'Source', 'Strategy', 'Sink', 'Naive baseline', 'Streaming']);
foreach (['8.4', '8.5'] as $v) {
    $off = "PHP $v, JIT off";
    $on = "PHP $v, JIT on";
    if (!isset($data[$off], $data[$on])) {
        continue;
    }
    $cells = ["PHP $v"];
    foreach (['SourceBench', 'StrategyBench', 'SinkBench', 'NaiveBaselineBench', 'StreamingBench'] as $class) {
        $logs = [];
        foreach ($data[$off][$class] ?? [] as $subject => $sets) {
            foreach ($sets as $set => $c) {
                $other = $data[$on][$class][$subject][$set] ?? null;
                if ($other && median($c['times']) >= 1000) {
                    $logs[] = log(median($other['times']) / median($c['times']));
                }
            }
        }
        $cells[] = $logs === [] ? '–' : sprintf('%.2f', exp(array_sum($logs) / count($logs)));
    }
    $out .= $row($cells);
}
$out .= "\n";

// ---- PHP 8.4 vs 8.5
$out .= "### PHP 8.4 compared with 8.5\n\n";
$out .= "Median time on 8.5 ÷ median time on 8.4 (geometric mean over variants of at least 1 ms). Below 1.00 means 8.5 is faster.\n\n";
$out .= $head(['JIT', 'Source', 'Strategy', 'Sink', 'Naive baseline', 'Streaming']);
foreach (['off', 'on'] as $j) {
    $a = "PHP 8.4, JIT $j";
    $b = "PHP 8.5, JIT $j";
    if (!isset($data[$a], $data[$b])) {
        continue;
    }
    $cells = ["JIT $j"];
    foreach (['SourceBench', 'StrategyBench', 'SinkBench', 'NaiveBaselineBench', 'StreamingBench'] as $class) {
        $logs = [];
        foreach ($data[$a][$class] ?? [] as $subject => $sets) {
            foreach ($sets as $set => $c) {
                $other = $data[$b][$class][$subject][$set] ?? null;
                if ($other && median($c['times']) >= 1000) {
                    $logs[] = log(median($other['times']) / median($c['times']));
                }
            }
        }
        $cells[] = $logs === [] ? '–' : sprintf('%.2f', exp(array_sum($logs) / count($logs)));
    }
    $out .= $row($cells);
}
$out .= "\n";

// ---- full matrix
$titles = [
    'SourceBench' => ['Sources', "Each source read to the end through `count()`; the rows are plain arrays in memory. \"own peak\" is the working memory of the read."],
    'StrategyBench' => ['Strategies', 'Nested set rows → strategy → `count()`. `NoFilter` is the source alone; `Prune` and `ActiveTrailSingle` are the realistic cases, `PruneHit`, `ActiveTrailHit` and `Annotate` stress cases (see the benchmark file).'],
    'SinkBench' => ['Sinks', 'Nested set rows → sink. `KeepAncestorsToNestedArray` adds the strategy in front of the nested array sink.'],
    'NaiveBaselineBench' => ['Naive baseline', '`Naive*` rows are the recursive build-the-whole-tree-then-filter code in `benchmarks/Support/Naive.php`; `Library*` rows the same result through the library.'],
    'StreamingBench' => ['Streaming from a generator', 'Rows generated lazily (time includes generating them; `GeneratorOnly` is that part alone). Sizes are the node counts of balanced trees with branching 10.'],
];
$out .= "## Full matrix\n\n";
$out .= "Time per revolution, \"median ±rstdev\". Variants named `… x100` run 100 passes per revolution (divide by 100 for one\n";
$out .= "pass). `nodes/s` and the memory columns are for $first; mem_peak includes the fixture, own peak is the subject's own\n";
$out .= "working memory.\n\n";
foreach ($titles as $class => [$title, $note]) {
    if (!isset($data[$first][$class])) {
        continue;
    }
    $out .= "### $title\n\n$note\n\n";
    $out .= $head(array_merge(['Subject', 'Set'], $configs, ["nodes/s ($first)", 'mem_peak', 'own peak']));
    foreach ($data[$first][$class] as $subject => $sets) {
        foreach (array_keys($sets) as $set) {
            $cells = [$subject, $set];
            foreach ($configs as $config) {
                $cells[] = $time($get($config, $class, $subject, $set));
            }
            $c = $get($first, $class, $subject, $set);
            $cells[] = $nps($c);
            $cells[] = bytes(max($c['peaks']));
            $cells[] = bytes(median($c['own']));
            $out .= $row($cells);
        }
    }
    $out .= "\n";
}

if (isset($argv[2])) {
    $doc = (string) file_get_contents($argv[2]);
    $start = '<!-- results:start -->';
    $end = '<!-- results:end -->';
    $a = strpos($doc, $start);
    $b = strpos($doc, $end);
    if ($a === false || $b === false) {
        fwrite(\STDERR, "markers not found in $argv[2]\n");
        exit(1);
    }
    file_put_contents($argv[2], substr($doc, 0, $a + strlen($start)) . "\n\n" . rtrim($out) . "\n\n" . substr($doc, $b));
} else {
    echo $out;
}
