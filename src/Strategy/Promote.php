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
 * Removes non-matching nodes but keeps their matching descendants, which move up to the nearest kept ancestor
 * (or become roots). Also known as hoisting or collapsing.
 *
 *     A ✓                       A
 *     ├─ B ✗                    ├─ C
 *     │  ├─ C ✓                 └─ E
 *     │  └─ D ✗ ─ E ✓           I
 *     └─ F ✗ ─ G ✗
 *     H ✗ ─ I ✓
 *
 * Streaming, O(depth) memory. Output depths change; sinks recompute parents and nested set values.
 */
final readonly class Promote implements FilterStrategy, StreamStrategy
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
        /** @var array<int, int> $kept input depths of kept ancestors; their count is the output depth */
        $kept = [];
        $keptCount = 0;
        $keepBelow = -1;
        while ($input->valid()) {
            $depth = $input->key();
            $node = $input->current();
            while ($keptCount > 0 && $kept[$keptCount - 1] >= $depth) {
                --$keptCount;
            }
            if ($keepBelow >= 0) {
                if ($depth > $keepBelow) {
                    $outDepth = $keptCount;
                    $kept[$keptCount++] = $depth;
                    if ((yield $outDepth => $node) === true) {
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
                $input->next();
                continue;
            }
            if ($onMatch === OnMatch::KeepDescendants) {
                $keepBelow = $depth;
            }
            $outDepth = $keptCount;
            $kept[$keptCount++] = $depth;
            if ((yield $outDepth => $node) === true || $onMatch === OnMatch::DropDescendants) {
                $input->send(true);
            } else {
                $input->next();
            }
        }
    }
}
