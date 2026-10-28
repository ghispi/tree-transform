<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

// The trail without siblings, and nothing below the current page.
$crumbs = Tree::fromNestedSet($rows)
    ->activeTrail(fn(array $c) => $c['slug'] === 'cases', siblings: false, activeChildrenDepth: 0, multiple: false)
    ->map(fn(array $c) => $c['title'])
    ->iterate();

echo implode(' › ', iterator_to_array($crumbs, false)), "\n";
