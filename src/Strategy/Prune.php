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
 * A non-matching node is removed together with its whole subtree; its descendants are never tested.
 *
 *     A ✓                       A
 *     ├─ B ✗   (and C, D, E)    (B, F and their subtrees are cut,
 *     └─ F ✗   (and G)           H is cut with I although I matches)
 *     H ✗ ─ I ✓
 *
 * Streaming, O(depth) memory (the ancestor path for predicates). The cheapest strategy, and the default.
 */
final readonly class Prune implements FilterStrategy, StreamStrategy
{
    public function __construct(private OnMatch $onMatch = OnMatch::EvaluateDescendants) {}

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

        return self::filter($input, $compiled->test, $compiled->needsAncestors, $this->onMatch);
    }

    /**
     * @param \Generator<int, mixed, bool|null, mixed>  $input
     * @param \Closure(mixed, int, Ancestors): mixed $test
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function filter(\Generator $input, \Closure $test, bool $trackAncestors, OnMatch $onMatch): \Generator
    {
        $ancestors = new Ancestors();
        // Depth of a match whose subtree is kept untested (OnMatch::KeepDescendants), or -1.
        $keepBelow = -1;
        while ($input->valid()) {
            $depth = $input->key();
            $node = $input->current();
            if ($keepBelow >= 0) {
                if ($depth > $keepBelow) {
                    if ((yield $depth => $node) === true) {
                        $input->send(true);
                    } else {
                        $input->next();
                    }
                    continue;
                }
                $keepBelow = -1;
            }
            if ($trackAncestors) {
                $ancestors->enter($node, $depth);
            }
            if (!(bool) $test($node, $depth, $ancestors)) {
                $input->send(true);
                continue;
            }
            if ($onMatch === OnMatch::KeepDescendants) {
                $keepBelow = $depth;
            }
            if ((yield $depth => $node) === true || $onMatch === OnMatch::DropDescendants) {
                $input->send(true);
            } else {
                $input->next();
            }
        }
    }
}
