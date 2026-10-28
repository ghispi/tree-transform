<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Stage\Stage;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

/**
 * Adds the URL path of every node, built from the slugs of its ancestors: "/clothing/men/shirts".
 * Keeps one slug per open depth: O(depth) memory.
 */
final readonly class WithUrl implements Stage
{
    public function apply(Cursor $input): Cursor
    {
        return new WithUrlCursor($input);
    }
}

final class WithUrlCursor implements Cursor
{
    /** @var array<int, string> slugs of the current path, indexed by depth */
    private array $slugs = [];

    private mixed $node = null;

    public function __construct(private readonly Cursor $input) {}

    public function next(): bool
    {
        if (!$this->input->next()) {
            return false;
        }
        $depth = $this->input->depth();
        $node = $this->input->node();
        $this->slugs[$depth] = $node['slug'];
        $node['url'] = '/' . implode('/', array_slice($this->slugs, 0, $depth + 1));
        $this->node = $node; // a copy: the input row is not modified

        return true;
    }

    public function node(): mixed
    {
        return $this->node;
    }

    public function depth(): int
    {
        return $this->input->depth();
    }

    public function skipSubtree(): void
    {
        $this->input->skipSubtree();
    }
}

foreach (Tree::fromNestedSet($rows)->depthWindow(1)->pipe(new WithUrl())->maxDepth(1) as $depth => $category) {
    echo str_repeat('  ', $depth), $category['url'], "\n";
}
