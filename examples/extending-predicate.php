<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

/**
 * Case- and accent-insensitive search in a field. The search term is normalised once, not per node.
 */
final readonly class TitleContains implements Predicate
{
    private string $needle;

    public function __construct(string $needle, private string $field = 'title')
    {
        $this->needle = self::normalise($needle);
    }

    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool
    {
        return str_contains(self::normalise($node[$this->field]), $this->needle);
    }

    private static function normalise(string $text): string
    {
        return strtr(mb_strtolower($text), ['é' => 'e', 'è' => 'e', 'ü' => 'u', 'ö' => 'o']);
    }
}

echo $printTree(
    Tree::fromNestedSet($rows)->filter(new TitleContains('LAPTÖPS'), new KeepAncestors()),
    fn(array $c) => $c['title'],
);
