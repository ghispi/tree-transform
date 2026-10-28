<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$selectedId = 9;

// iterate() streams depth => node, so the options are written without building a tree in memory.
echo "<select name=\"category\">\n";
foreach (Tree::fromNestedSet($rows)->depthWindow(1)->iterate() as $depth => $category) {
    printf(
        "  <option value=\"%d\"%s>%s%s</option>\n",
        $category['id'],
        $category['id'] === $selectedId ? ' selected' : '',
        str_repeat('&nbsp;&nbsp;', $depth),
        htmlspecialchars($category['title']),
    );
}
echo "</select>\n";
