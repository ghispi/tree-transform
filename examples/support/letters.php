<?php

declare(strict_types=1);

/*
 * The running example of the documentation. "match" says whether a node matches the predicate.
 *
 *     A ✓
 *     ├─ B ✗
 *     │  ├─ C ✓
 *     │  └─ D ✗
 *     │     └─ E ✓
 *     └─ F ✗
 *        └─ G ✗
 *     H ✗
 *     └─ I ✓
 */
return [
    ['name' => 'A', 'match' => true, 'children' => [
        ['name' => 'B', 'match' => false, 'children' => [
            ['name' => 'C', 'match' => true],
            ['name' => 'D', 'match' => false, 'children' => [
                ['name' => 'E', 'match' => true],
            ]],
        ]],
        ['name' => 'F', 'match' => false, 'children' => [
            ['name' => 'G', 'match' => false],
        ]],
    ]],
    ['name' => 'H', 'match' => false, 'children' => [
        ['name' => 'I', 'match' => true],
    ]],
];
