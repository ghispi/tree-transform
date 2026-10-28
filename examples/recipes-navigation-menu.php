<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

// Site pages as an adjacency list, e.g. SELECT id, parent_id, title, url FROM page ORDER BY position
$pages = [
    ['id' => 1, 'parent_id' => null, 'title' => 'Home',        'url' => '/'],
    ['id' => 2, 'parent_id' => null, 'title' => 'Products',    'url' => '/products'],
    ['id' => 3, 'parent_id' => 2,    'title' => 'Laptops',     'url' => '/products/laptops'],
    ['id' => 4, 'parent_id' => 3,    'title' => 'Ultrabooks',  'url' => '/products/laptops/ultrabooks'],
    ['id' => 5, 'parent_id' => 3,    'title' => 'Gaming',      'url' => '/products/laptops/gaming'],
    ['id' => 6, 'parent_id' => 2,    'title' => 'Phones',      'url' => '/products/phones'],
    ['id' => 7, 'parent_id' => 6,    'title' => 'Android',     'url' => '/products/phones/android'],
    ['id' => 8, 'parent_id' => null, 'title' => 'Support',     'url' => '/support'],
    ['id' => 9, 'parent_id' => 8,    'title' => 'Returns',     'url' => '/support/returns'],
];
$currentUrl = '/products/laptops';

$menu = Tree::fromAdjacencyList($pages)
    ->activeTrail(fn(array $page) => $page['url'] === $currentUrl)
    ->map(fn(array $page) => $page + ['active' => $page['url'] === $currentUrl])
    ->toNestedArray();

// Render as HTML. The menu is small, so a recursive closure is fine here.
$render = function (array $items, int $indent = 0) use (&$render): string {
    $pad = str_repeat('  ', $indent);
    $html = "{$pad}<ul>\n";
    foreach ($items as $item) {
        $class = $item['active'] ? ' class="active"' : '';
        $html .= "{$pad}  <li{$class}><a href=\"{$item['url']}\">{$item['title']}</a>";
        $html .= $item['children'] === [] ? '' : "\n" . $render($item['children'], $indent + 2) . "{$pad}  ";
        $html .= "</li>\n";
    }

    return $html . "{$pad}</ul>\n";
};

echo $render($menu);
