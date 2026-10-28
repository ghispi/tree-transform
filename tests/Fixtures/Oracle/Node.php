<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures\Oracle;

/**
 * Plain recursive tree node used by the reference oracle.
 */
final class Node
{
    /**
     * @param list<Node> $children
     */
    public function __construct(
        public readonly mixed $payload,
        public array $children = [],
    ) {}

    /**
     * Builds a forest from pre-order (depth, node) pairs. Iterative so deep inputs work.
     *
     * @param iterable<array{0: int, 1: mixed}> $pairs
     *
     * @return list<Node>
     */
    public static function forestFromPairs(iterable $pairs): array
    {
        $roots = [];
        /** @var array<int, Node> $open */
        $open = [];
        foreach ($pairs as [$depth, $payload]) {
            $node = new self($payload);
            if ($depth === 0) {
                $roots[] = $node;
            } else {
                $open[$depth - 1]->children[] = $node;
            }
            $open[$depth] = $node;
        }

        return $roots;
    }

    /**
     * Pre-order (depth, payload) pairs of a forest.
     *
     * @param list<Node> $forest
     *
     * @return list<array{0: int, 1: mixed}>
     */
    public static function flatten(array $forest, int $depth = 0): array
    {
        $out = [];
        foreach ($forest as $node) {
            $out[] = [$depth, $node->payload];
            foreach (self::flatten($node->children, $depth + 1) as $pair) {
                $out[] = $pair;
            }
        }

        return $out;
    }
}
