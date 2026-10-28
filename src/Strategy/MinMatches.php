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
 * Keeps a node when its subtree (the node itself included) contains at least $min matching nodes.
 * "Categories with at least 3 products", "folders with at least one large file".
 *
 * With $min = 1 this is KeepAncestors. Kept nodes always form a valid tree (ancestors of a kept node have at
 * least as many matches), and depths are unchanged. On the running example (C, E, I and A match) with $min = 2:
 *
 *     A (3)                     A
 *     ├─ B (2)                  └─ B
 *     │  ├─ C (1)
 *     │  └─ D (1) ─ E (1)
 *     H (1) ─ I (1)
 *
 * A node is emitted as soon as its count reaches $min; one that never does is known only when its subtree ends,
 * so output waits for it: memory up to the size of one undecided subtree.
 */
final readonly class MinMatches implements FilterStrategy, StreamStrategy
{
    private const int UNDECIDED = 0;

    private const int KEEP = 1;

    private const int DROP = 2;

    public function __construct(private int $min)
    {
        if ($min < 1) {
            throw new InvalidArgumentException('MinMatches needs a minimum of at least 1.');
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

        return self::filter($input, $compiled->test, $compiled->needsAncestors, $this->min);
    }

    /**
     * Nodes wait in a queue until they are kept (their count reaches the minimum) or dropped (their subtree ends
     * below it).
     *
     * @param \Generator<int, mixed, bool|null, mixed>  $input
     * @param \Closure(mixed, int, Ancestors): mixed $test
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function filter(\Generator $input, \Closure $test, bool $trackAncestors, int $min): \Generator
    {
        $ancestors = new Ancestors();
        /** @var array<int, mixed> $queueNodes */
        $queueNodes = [];
        /** @var array<int, int> $queueDepths */
        $queueDepths = [];
        /** @var array<int, int> $queueStates one of the constants UNDECIDED, KEEP, DROP */
        $queueStates = [];
        $head = 0;
        $tail = 0;
        /** @var array<int, int> $pathEntry queue position of each open path node, indexed by depth */
        $pathEntry = [];
        /** @var array<int, int> $pathCount matches counted so far in each open path node's subtree */
        $pathCount = [];
        /** @var array<int, bool> $pathKept whether each open path node is decided (kept) */
        $pathKept = [];
        $pathLength = 0;
        $skipBelow = -1;
        $inputPosition = -1;

        while (true) {
            $exhausted = !$input->valid();
            $depth = $exhausted ? 0 : $input->key();

            // Close path nodes at $depth and deeper; the undecided ones did not reach the minimum.
            for ($j = $pathLength - 1; $j >= $depth; --$j) {
                if (!$pathKept[$j]) {
                    $queueStates[$pathEntry[$j]] = self::DROP;
                }
            }
            if ($pathLength > $depth) {
                $pathLength = $depth;
            }

            if (!$exhausted) {
                $node = $input->current();
                $inputPosition = $tail++;
                $queueNodes[$inputPosition] = $node;
                $queueDepths[$inputPosition] = $depth;
                $queueStates[$inputPosition] = self::UNDECIDED;
                $pathEntry[$depth] = $inputPosition;
                $pathCount[$depth] = 0;
                $pathKept[$depth] = false;
                $pathLength = $depth + 1;

                if ($trackAncestors) {
                    $ancestors->enter($node, $depth);
                }
                if ((bool) $test($node, $depth, $ancestors)) {
                    // Count the match for the node and every undecided ancestor; kept ones are a prefix of the path.
                    for ($j = $depth; $j >= 0 && !$pathKept[$j]; --$j) {
                        if (++$pathCount[$j] >= $min) {
                            $pathKept[$j] = true;
                            $queueStates[$pathEntry[$j]] = self::KEEP;
                        }
                    }
                }
            }

            $skipInput = false;
            while ($head < $tail && $queueStates[$head] !== self::UNDECIDED) {
                $position = $head++;
                $state = $queueStates[$position];
                $outDepth = $queueDepths[$position];
                $outNode = $queueNodes[$position];
                unset($queueNodes[$position], $queueDepths[$position], $queueStates[$position]);
                if ($state === self::DROP) {
                    continue;
                }
                if ($skipBelow >= 0) {
                    if ($outDepth > $skipBelow) {
                        continue;
                    }
                    $skipBelow = -1;
                }
                if ((yield $outDepth => $outNode) === true) {
                    $skipBelow = $outDepth;
                    // The input's current node: its state and its ancestors' are decided, so the input can skip
                    // its subtree too instead of reading and testing every descendant.
                    $skipInput = $position === $inputPosition;
                }
            }
            if ($head === $tail) {
                $head = $tail = 0;
            }
            if ($exhausted) {
                return;
            }
            if ($skipInput) {
                $input->send(true);
            } else {
                $input->next();
            }
        }
    }
}
