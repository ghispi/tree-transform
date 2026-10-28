<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// A generator stands in for a database cursor; it reports how many rows were fetched.
$fetched = 0;
$rows = (function () use (&$fetched): Generator {
    foreach (require __DIR__ . '/support/shop.php' as $row) {
        ++$fetched;
        yield $row;
    }
})();

echo $printTree(Tree::fromNestedSet($rows)->limit(4), fn(array $c) => $c['title']);
echo "Rows fetched: {$fetched} of 13\n";
