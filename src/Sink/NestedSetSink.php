<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Sink;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;

/**
 * Builds a flat list in pre-order with recomputed nested set values — e.g. to store a filtered or restructured
 * tree, or to hand it to code that expects nested sets.
 *
 * Array payloads get the left/right/level fields set; for objects pass a $nodeFactory:
 * fn (mixed $node, int $left, int $right, int $depth): mixed.
 *
 * @implements Sink<list<mixed>>
 */
final readonly class NestedSetSink implements Sink
{
    /**
     * @param string|null                                    $levelField  null to leave the level out
     * @param int                                            $levelBase   level of roots (Gedmo uses 0; some schemas 1)
     * @param bool                                           $restartPerRoot number every root's tree from 1
     *                                                                       (Gedmo with a TreeRoot field)
     * @param \Closure|null  $nodeFactory
     */
    public function __construct(
        private string $leftField = 'lft',
        private string $rightField = 'rgt',
        private ?string $levelField = 'lvl',
        private int $levelBase = 0,
        private bool $restartPerRoot = false,
        private ?\Closure $nodeFactory = null,
    ) {}

    /**
     * @return list<mixed>
     */
    public function consume(Cursor $cursor): array
    {
        /** @var list<mixed> $nodes */
        $nodes = [];
        /** @var list<int> $lefts */
        $lefts = [];
        /** @var list<int> $rights */
        $rights = [];
        /** @var list<int> $depths */
        $depths = [];
        /** @var array<int, int> $open output positions of open ancestors, indexed by depth */
        $open = [];
        $top = -1;
        $counter = 0;

        while ($cursor->next()) {
            $depth = $cursor->depth();
            while ($top >= $depth) {
                $rights[$open[$top--]] = ++$counter;
            }
            if ($depth === 0 && $this->restartPerRoot) {
                $counter = 0;
            }
            $position = \count($nodes);
            $nodes[] = $cursor->node();
            $lefts[] = ++$counter;
            $rights[] = 0;
            $depths[] = $depth;
            $open[++$top] = $position;
        }
        while ($top >= 0) {
            $rights[$open[$top--]] = ++$counter;
        }

        $out = [];
        foreach ($nodes as $i => $node) {
            $out[] = $this->build($node, $lefts[$i], $rights[$i], $depths[$i]);
        }

        return $out;
    }

    private function build(mixed $node, int $left, int $right, int $depth): mixed
    {
        if ($this->nodeFactory !== null) {
            return ($this->nodeFactory)($node, $left, $right, $depth);
        }
        if (!\is_array($node)) {
            throw new InvalidArgumentException(\sprintf(
                'NestedSetSink cannot set nested set values on %s payloads; pass a nodeFactory or map() nodes to arrays.',
                get_debug_type($node),
            ));
        }
        $node[$this->leftField] = $left;
        $node[$this->rightField] = $right;
        if ($this->levelField !== null) {
            $node[$this->levelField] = $depth + $this->levelBase;
        }

        return $node;
    }
}
