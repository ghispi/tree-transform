<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks\Prototype;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Sink\TreeNode;

/**
 * Pipeline prototype: src/Sink/NestedArraySink without the per-node build() method call — the common case
 * (array payload, no node factory) is inlined; other payloads still go through the same rules. Same output.
 */
final readonly class FastNestedArraySink
{
    public function __construct(
        private string $childrenKey = 'children',
        private bool $omitEmptyChildren = false,
    ) {}

    /**
     * @return list<mixed>
     */
    public function consume(Cursor $cursor): array
    {
        $key = $this->childrenKey;
        $omit = $this->omitEmptyChildren;
        $roots = [];
        /** @var array<int, mixed> $nodes */
        $nodes = [];
        /** @var array<int, list<mixed>> $children */
        $children = [];
        $top = -1;

        while (true) {
            $more = $cursor->next();
            $depth = $more ? $cursor->depth() : 0;
            while ($top >= $depth) {
                $node = $nodes[$top];
                if (\is_array($node)) {
                    if (!$omit || $children[$top] !== []) {
                        $node[$key] = $children[$top];
                    }
                } else {
                    $node = new TreeNode($node, $children[$top]);
                }
                unset($nodes[$top], $children[$top]);
                if (--$top >= 0) {
                    $children[$top][] = $node;
                } else {
                    $roots[] = $node;
                }
            }
            if (!$more) {
                return $roots;
            }
            $top = $depth;
            $nodes[$top] = $cursor->node();
            $children[$top] = [];
        }
    }
}
