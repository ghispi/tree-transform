<?php

declare(strict_types=1);

/*
 * Draws a tree as ASCII art from pre-order depth => node entries, e.g. a Tree or the output of Tree::iterate().
 *
 *     $printTree = require __DIR__ . '/support/print-tree.php';
 *     echo $printTree($tree, fn (array $node) => $node['title']);
 */
return static function (iterable $entries, Closure $label): string {
    $rows = [];
    foreach ($entries as $depth => $node) {
        $rows[] = [$depth, $label($node)];
    }

    // A node is the last of its siblings when no later sibling follows before its parent closes.
    $count = count($rows);
    $isLast = [];
    for ($i = 0; $i < $count; ++$i) {
        $isLast[$i] = true;
        for ($j = $i + 1; $j < $count; ++$j) {
            if ($rows[$j][0] <= $rows[$i][0]) {
                $isLast[$i] = $rows[$j][0] < $rows[$i][0];
                break;
            }
        }
    }

    $out = '';
    $lastAtDepth = [];
    foreach ($rows as $i => [$depth, $text]) {
        $lastAtDepth[$depth] = $isLast[$i];
        $prefix = '';
        for ($d = 1; $d < $depth; ++$d) {
            $prefix .= $lastAtDepth[$d] ? '   ' : '│  ';
        }
        if ($depth > 0) {
            $prefix .= $isLast[$i] ? '└─ ' : '├─ ';
        }
        $out .= $prefix . $text . "\n";
    }

    return $out === '' ? "(empty)\n" : $out;
};
