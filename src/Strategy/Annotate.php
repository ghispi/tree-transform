<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\CompiledPredicate;
use Ghispi\TreeTransform\Internal\GeneratorCursor;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamStrategy;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * Keeps every node and tells how it relates to the matches (MatchState) — for highlighting search hits and
 * greying out the rest instead of removing it.
 *
 *     A ✓  → Match                   F ✗ → DescendantOfMatch (under A)
 *     B ✗  → AncestorOfMatch (C)     H ✗ → AncestorOfMatch (I)
 *
 * By default each payload is wrapped in an Annotated object; pass $combine to merge the state into the payload,
 * e.g. fn (array $n, MatchState $s) => $n + ['state' => $s->value].
 *
 * Whether a node is an ancestor of a match is known only when its subtree has been read, so output waits for the
 * shallowest undecided node: memory up to the size of one root's tree.
 */
final readonly class Annotate implements FilterStrategy, StreamStrategy
{
    /** @var \Closure(mixed, MatchState): mixed */
    private \Closure $combine;

    /**
     * @param \Closure|null $combine
     */
    public function __construct(?\Closure $combine = null)
    {
        $this->combine = $combine ?? static fn(mixed $node, MatchState $state): Annotated => new Annotated($node, $state);
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

        return self::annotate($input, $compiled->test, $compiled->needsAncestors, $this->combine);
    }

    /**
     * Nodes wait in a queue until their state is decided; a matching node decides its undecided ancestors, and
     * leaving a subtree decides the nodes in it that are still undecided.
     *
     * @param \Generator<int, mixed, bool|null, mixed>  $input
     * @param \Closure(mixed, int, Ancestors): mixed $test
     * @param \Closure(mixed, MatchState): mixed     $combine
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function annotate(\Generator $input, \Closure $test, bool $trackAncestors, \Closure $combine): \Generator
    {
        $ancestors = new Ancestors();
        /** @var array<int, mixed> $queueNodes */
        $queueNodes = [];
        /** @var array<int, int> $queueDepths */
        $queueDepths = [];
        /** @var array<int, MatchState|null> $queueStates null while undecided */
        $queueStates = [];
        $head = 0;
        $tail = 0;
        /** @var array<int, int> $pathEntry queue position of each node on the current path, indexed by depth */
        $pathEntry = [];
        /** @var array<int, bool> $pathMatches whether each path node matches */
        $pathMatches = [];
        /** @var array<int, bool> $pathDecided whether each path node's state is decided */
        $pathDecided = [];
        $pathLength = 0;
        // Number of matching nodes on the current path (to tell DescendantOfMatch from None).
        $matchesOnPath = 0;
        $skipBelow = -1;
        $inputPosition = -1;

        while (true) {
            $exhausted = !$input->valid();
            $depth = $exhausted ? 0 : $input->key();

            // Close the path nodes at $depth and deeper: undecided ones had no matching descendant.
            for ($j = $pathLength - 1; $j >= $depth; --$j) {
                if ($pathMatches[$j]) {
                    --$matchesOnPath;
                    continue;
                }
                if (!$pathDecided[$j]) {
                    $queueStates[$pathEntry[$j]] = $matchesOnPath > 0 ? MatchState::DescendantOfMatch : MatchState::None;
                    $pathDecided[$j] = true;
                }
            }
            if ($pathLength > $depth) {
                $pathLength = $depth;
            }

            if (!$exhausted) {
                $node = $input->current();
                if ($trackAncestors) {
                    $ancestors->enter($node, $depth);
                }
                $matches = (bool) $test($node, $depth, $ancestors);

                $inputPosition = $tail++;
                $queueNodes[$inputPosition] = $node;
                $queueDepths[$inputPosition] = $depth;
                $queueStates[$inputPosition] = $matches ? MatchState::Match : null;
                $pathEntry[$depth] = $inputPosition;
                $pathMatches[$depth] = $matches;
                $pathDecided[$depth] = $matches;
                $pathLength = $depth + 1;

                if ($matches) {
                    // Every undecided ancestor now has a matching descendant.
                    for ($j = $depth - 1; $j >= 0 && !$pathDecided[$j]; --$j) {
                        $queueStates[$pathEntry[$j]] = MatchState::AncestorOfMatch;
                        $pathDecided[$j] = true;
                    }
                    ++$matchesOnPath;
                }
            }

            $skipInput = false;
            while ($head < $tail && $queueStates[$head] !== null) {
                $position = $head++;
                $outDepth = $queueDepths[$position];
                $outNode = $queueNodes[$position];
                /** @var MatchState $state checked by the loop condition */
                $state = $queueStates[$position];
                unset($queueNodes[$position], $queueDepths[$position], $queueStates[$position]);
                if ($skipBelow >= 0) {
                    if ($outDepth > $skipBelow) {
                        continue;
                    }
                    $skipBelow = -1;
                }
                if ((yield $outDepth => $combine($outNode, $state)) === true) {
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
