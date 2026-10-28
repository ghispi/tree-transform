<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

// A sitemap that does not descend into Electronics: send(true) instead of next() skips the current node's subtree.
// The source skips those rows without reading them further.
$nodes = Tree::fromNestedSet($rows)->iterate();
while ($nodes->valid()) {
    $depth = $nodes->key();
    $category = $nodes->current();
    echo str_repeat('    ', $depth), $category['title'], "\n";

    if ($category['title'] === 'Electronics') {
        $nodes->send(true);
    } else {
        $nodes->next();
    }
}
