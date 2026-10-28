<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\MatchState;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

// Show the whole tree, but highlight hits, open their ancestors and grey out the rest.
$query = 'es';
$items = Tree::fromNestedSet($rows)
    ->annotate(
        fn(array $c) => str_contains(strtolower($c['title']), $query),
        fn(array $c, MatchState $state) => [
            'title' => $c['title'],
            'class' => match ($state) {
                MatchState::Match => 'hit',
                MatchState::AncestorOfMatch => 'open',
                MatchState::DescendantOfMatch => 'inside-hit',
                MatchState::None => 'dimmed',
            },
        ],
    )
    ->iterate();

foreach ($items as $depth => $item) {
    printf("%s<li class=\"%s\">%s</li>\n", str_repeat('  ', $depth), $item['class'], $item['title']);
}
