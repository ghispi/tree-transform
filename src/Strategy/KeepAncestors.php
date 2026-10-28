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
 * Keeps matching nodes together with their path to the root; non-matching ancestors stay as context.
 * The classic "search in a tree" result.
 *
 *     A ✓                       A
 *     ├─ B ✗                    ├─ B
 *     │  ├─ C ✓                 │  ├─ C
 *     │  └─ D ✗ ─ E ✓           │  └─ D ─ E
 *     └─ F ✗ ─ G ✗              H ─ I
 *     H ✗ ─ I ✓
 *
 * Streaming; buffers the not-yet-emitted ancestors of the current node: O(depth) memory.
 */
final readonly class KeepAncestors implements FilterStrategy, StreamStrategy
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
        /** @var array<int, mixed> $path current input path, indexed by depth */
        $path = [];
        // Number of nodes at the top of the path that were already emitted.
        $emitted = 0;
        $keepBelow = -1;
        // Output depth whose subtree the consumer asked to skip while ancestors were emitted, or -1.
        $skipBelow = -1;
        while ($input->valid()) {
            $depth = $input->key();
            if ($skipBelow >= 0) {
                if ($depth > $skipBelow) {
                    $input->send(true);
                    continue;
                }
                $skipBelow = -1;
            }
            if ($emitted > $depth) {
                $emitted = $depth;
            }
            $node = $input->current();
            $path[$depth] = $node;

            if ($keepBelow >= 0) {
                if ($depth > $keepBelow) {
                    $emitted = $depth + 1;
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
                $input->next();
                continue;
            }
            if ($onMatch === OnMatch::KeepDescendants) {
                $keepBelow = $depth;
            }
            $skip = $onMatch === OnMatch::DropDescendants;
            for ($d = $emitted; $d < $depth; ++$d) {
                if ((yield $d => $path[$d]) === true) {
                    // An ancestor's subtree is skipped: drop the rest of the path and the input below it.
                    $skipBelow = $d;
                    break;
                }
            }
            if ($skipBelow >= 0) {
                $emitted = $skipBelow + 1;
                $input->send(true);
                continue;
            }
            $emitted = $depth + 1;
            if ((yield $depth => $node) === true || $skip) {
                $input->send(true);
            } else {
                $input->next();
            }
        }
    }
}
