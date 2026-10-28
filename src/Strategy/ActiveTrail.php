<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Internal\CompiledPredicate;
use Ghispi\TreeTransform\Internal\GeneratorCursor;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamStrategy;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * Navigation menu expanded down to the active node(s): the predicate identifies the active node.
 *
 * Kept: all roots, every child of an ancestor of an active node (the trail and its siblings), the active node, and
 * $activeChildrenDepth levels below it. Example with active = E:
 *
 *     A                         A
 *     ├─ B                      ├─ B
 *     │  ├─ C                   │  ├─ C
 *     │  └─ D ─ E (active)      │  └─ D ─ E
 *     └─ F ─ G                  └─ F            (F's children collapsed)
 *     H ─ I                     H               (H collapsed)
 *
 * With $siblings = false only the trail itself is kept (a breadcrumb): A ─ B ─ D ─ E.
 *
 * Streaming. While it is unknown whether a node is on the trail, its direct children are buffered:
 * O(sum of child counts along the current path) memory, typically depth × branching.
 */
final readonly class ActiveTrail implements FilterStrategy, StreamStrategy
{
    /**
     * @param bool $siblings            keep the siblings of trail nodes (a menu); false keeps only the trail
     * @param int  $activeChildrenDepth levels shown below the active node: 0 none, 1 its children, PHP_INT_MAX all
     * @param bool $multiple            allow several active nodes (union of trails); false stops testing after
     *                                  the first match
     */
    public function __construct(
        private bool $siblings = true,
        private int $activeChildrenDepth = 1,
        private bool $multiple = true,
    ) {
        if ($activeChildrenDepth < 0) {
            throw new InvalidArgumentException('activeChildrenDepth cannot be negative.');
        }
    }

    public function apply(Cursor $input, Predicate $predicate): Cursor
    {
        return new GeneratorCursor($this->stream(Stream::of($input), $predicate));
    }

    /**
     * @internal
     *
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    public function stream(\Generator $input, Predicate $predicate): \Generator
    {
        $compiled = CompiledPredicate::of($predicate);

        return self::filter($input, $compiled->test, $compiled->needsAncestors, $this->siblings, $this->activeChildrenDepth, $this->multiple);
    }

    /**
     * @param \Generator<int, mixed, bool|null, mixed>  $input
     * @param \Closure(mixed, int, Ancestors): mixed $test
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function filter(
        \Generator $input,
        \Closure $test,
        bool $trackAncestors,
        bool $siblings,
        int $activeChildrenDepth,
        bool $multiple,
    ): \Generator {
        $ancestors = new Ancestors();
        /** @var array<int, mixed> $path current input path, indexed by depth */
        $path = [];
        /** @var array<int, bool> $emitted whether each path node has been emitted */
        $emitted = [];
        /** @var array<int, list<mixed>> $buffers buffered children (depth + 1) of path nodes not yet known to be on the trail */
        $buffers = [];
        $pathLength = 0;
        // Path nodes at depths 0 .. $trail - 1 are known ancestors of an active node.
        $trail = 0;
        /** @var array<int, int> $active depths of active nodes on the current path */
        $active = [];
        $activeCount = 0;
        $found = false;
        /** @var list<mixed> $queueNodes */
        $queueNodes = [];
        /** @var list<int> $queueDepths */
        $queueDepths = [];
        $skipBelow = -1;

        while ($input->valid()) {
            $depth = $input->key();
            $node = $input->current();

            for ($j = $depth; $j < $pathLength; ++$j) {
                $buffers[$j] = [];
            }
            $pathLength = $depth + 1;
            if ($trail > $depth) {
                $trail = $depth;
            }
            while ($activeCount > 0 && $active[$activeCount - 1] >= $depth) {
                --$activeCount;
            }

            // Queue position of the input's current node (to forward skips), or -1.
            $inputPosition = -1;
            $nearActive = $activeCount > 0 && $depth - $active[$activeCount - 1] <= $activeChildrenDepth;
            if ($nearActive || ($siblings && ($depth === 0 || $depth - 1 < $trail))) {
                $inputPosition = \count($queueNodes);
                $queueNodes[] = $node;
                $queueDepths[] = $depth;
                $emitted[$depth] = true;
            } else {
                $emitted[$depth] = false;
                if ($siblings) {
                    $buffers[$depth - 1][] = $node;
                }
            }
            $path[$depth] = $node;
            $buffers[$depth] = [];

            if (!$found || $multiple) {
                if ($trackAncestors) {
                    $ancestors->enter($node, $depth);
                }
                if ((bool) $test($node, $depth, $ancestors)) {
                    $found = true;
                    if ($siblings) {
                        for ($j = $trail; $j < $depth; ++$j) {
                            foreach ($buffers[$j] as $child) {
                                $queueNodes[] = $child;
                                $queueDepths[] = $j + 1;
                            }
                            $buffers[$j] = [];
                        }
                        for ($j = $trail; $j <= $depth; ++$j) {
                            $emitted[$j] = true;
                        }
                    } else {
                        for ($j = 0; $j <= $depth; ++$j) {
                            if (!$emitted[$j]) {
                                $queueNodes[] = $path[$j];
                                $queueDepths[] = $j;
                                $emitted[$j] = true;
                            }
                        }
                    }
                    $trail = $depth;
                    $active[$activeCount++] = $depth;
                }
            }

            $skipInput = false;
            foreach ($queueNodes as $position => $outNode) {
                $outDepth = $queueDepths[$position];
                if ($skipBelow >= 0) {
                    if ($outDepth > $skipBelow) {
                        continue;
                    }
                    $skipBelow = -1;
                }
                if ((yield $outDepth => $outNode) === true) {
                    $skipBelow = $outDepth;
                    // Once the single active node is found, the trail is fixed: skipping the current input node's
                    // subtree only removes descendants the consumer does not want. Before that, the subtree could
                    // hold the active node.
                    $skipInput = $found && !$multiple && $position === $inputPosition;
                }
            }
            if ($queueNodes !== []) {
                $queueNodes = $queueDepths = [];
            }
            if ($skipInput) {
                $input->send(true);
            } else {
                $input->next();
            }
        }
    }
}
