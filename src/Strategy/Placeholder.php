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
 * Like Prune, but every run of consecutive removed siblings is replaced by one placeholder node, so readers can
 * see that something was hidden: fn (int $hidden, int $depth): mixed creates it, where $hidden is the number of
 * removed siblings in the run (their descendants are not counted).
 *
 *     A ✓                       A
 *     ├─ B ✗                    └─ "2 hidden"
 *     └─ F ✗                    "1 hidden"
 *     H ✗
 *
 * Streaming, O(depth) memory.
 */
final readonly class Placeholder implements FilterStrategy, StreamStrategy
{
    /**
     * @param \Closure $factory fn (int $hidden, int $depth): mixed
     */
    public function __construct(
        private \Closure $factory,
        private OnMatch $onMatch = OnMatch::EvaluateDescendants,
    ) {}

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

        return self::filter($input, $compiled->test, $compiled->needsAncestors, $this->factory, $this->onMatch);
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
        \Closure $factory,
        OnMatch $onMatch,
    ): \Generator {
        $ancestors = new Ancestors();
        /** @var array<int, int> $runs removed siblings waiting for their placeholder, indexed by depth */
        $runs = [];
        // Deepest depth that may have a pending run.
        $deepest = -1;
        $keepBelow = -1;
        while ($input->valid()) {
            $depth = $input->key();
            $node = $input->current();

            // Runs below this depth belong to parents that just closed. Placeholders have no subtree to skip.
            if ($deepest > $depth) {
                yield from self::placeholders($runs, $deepest, $depth + 1, $factory);
            }

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
                $runs[$depth] = ($runs[$depth] ?? 0) + 1;
                if ($depth > $deepest) {
                    $deepest = $depth;
                }
                $input->send(true);
                continue;
            }
            if ($deepest >= $depth) {
                yield from self::placeholders($runs, $deepest, $depth, $factory);
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
        yield from self::placeholders($runs, $deepest, 0, $factory);
    }

    /**
     * Emits the placeholders of runs at $depth and deeper, deepest first (pre-order).
     *
     * @param array<int, int> $runs
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function placeholders(array &$runs, int &$deepest, int $depth, \Closure $factory): \Generator
    {
        for ($j = $deepest; $j >= $depth; --$j) {
            if (($runs[$j] ?? 0) > 0) {
                $hidden = $runs[$j];
                $runs[$j] = 0;
                yield $j => $factory($hidden, $j);
            }
        }
        if ($deepest >= $depth) {
            $deepest = $depth - 1;
        }
    }
}
