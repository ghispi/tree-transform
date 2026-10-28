<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks\Prototype;

use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Internal\Rows;
use Ghispi\TreeTransform\Predicate\Ancestors;

/**
 * Phase 0 prototype (c): the same nested set source and Prune strategy as generators yielding depth => node.
 *
 * Two ways to ask the source to skip a subtree are measured:
 * - send(): the consumer calls $generator->send(true) instead of next(); `yield` returns true in the producer.
 * - flag:   a shared SkipFlag object the consumer sets before advancing with foreach.
 *
 * The source does the same validation as src/Source/NestedSetCursor (with inline key reads, like the
 * InlineInt/List cursor prototypes), without multi-root support.
 */
final class GeneratorPipeline
{
    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return \Generator<int, array<string, mixed>, bool|null, void>
     */
    public static function nestedSetSend(iterable $rows, string $leftKey = 'lft', string $rightKey = 'rgt'): \Generator
    {
        $rights = [];
        $open = 0;
        $lastLeft = \PHP_INT_MIN;
        $skipBefore = \PHP_INT_MIN;
        foreach ($rows as $row) {
            $left = $row[$leftKey] ?? null;
            if (!\is_int($left)) {
                $left = Rows::int($left, $leftKey, $row);
            }
            if ($left < $skipBefore) {
                continue;
            }
            $right = $row[$rightKey] ?? null;
            if (!\is_int($right)) {
                $right = Rows::int($right, $rightKey, $row);
            }
            if ($left >= $right) {
                throw InvalidTreeException::at(null, \sprintf('left value %d is not lower than right value %d.', $left, $right));
            }
            if ($left <= $lastLeft) {
                throw InvalidTreeException::at(null, \sprintf('left value %d does not increase (previous %d).', $left, $lastLeft));
            }
            while ($open > 0 && $rights[$open - 1] < $left) {
                --$open;
            }
            if ($open > 0 && $right > $rights[$open - 1]) {
                throw InvalidTreeException::at(null, \sprintf('interval %d..%d overlaps its parent.', $left, $right));
            }
            $depth = $open;
            $rights[$open++] = $right;
            $lastLeft = $left;

            if ((yield $depth => $row) === true) {
                $skipBefore = $right;
            }
        }
    }

    /**
     * @param \Generator<int, mixed, bool|null, void> $input
     * @param \Closure(mixed, int, Ancestors): bool    $test
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    public static function pruneSend(\Generator $input, \Closure $test): \Generator
    {
        $ancestors = new Ancestors();
        while ($input->valid()) {
            $depth = $input->key();
            $node = $input->current();
            if (!$test($node, $depth, $ancestors)) {
                $input->send(true);
                continue;
            }
            if ((yield $depth => $node) === true) {
                $input->send(true);
            } else {
                $input->next();
            }
        }
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function nestedSetFlag(iterable $rows, SkipFlag $flag, string $leftKey = 'lft', string $rightKey = 'rgt'): \Generator
    {
        $rights = [];
        $open = 0;
        $lastLeft = \PHP_INT_MIN;
        $skipBefore = \PHP_INT_MIN;
        foreach ($rows as $row) {
            $left = $row[$leftKey] ?? null;
            if (!\is_int($left)) {
                $left = Rows::int($left, $leftKey, $row);
            }
            if ($left < $skipBefore) {
                continue;
            }
            $right = $row[$rightKey] ?? null;
            if (!\is_int($right)) {
                $right = Rows::int($right, $rightKey, $row);
            }
            if ($left >= $right) {
                throw InvalidTreeException::at(null, \sprintf('left value %d is not lower than right value %d.', $left, $right));
            }
            if ($left <= $lastLeft) {
                throw InvalidTreeException::at(null, \sprintf('left value %d does not increase (previous %d).', $left, $lastLeft));
            }
            while ($open > 0 && $rights[$open - 1] < $left) {
                --$open;
            }
            if ($open > 0 && $right > $rights[$open - 1]) {
                throw InvalidTreeException::at(null, \sprintf('interval %d..%d overlaps its parent.', $left, $right));
            }
            $depth = $open;
            $rights[$open++] = $right;
            $lastLeft = $left;

            yield $depth => $row;
            if ($flag->skip) {
                $flag->skip = false;
                $skipBefore = $right;
            }
        }
    }

    /**
     * @param \Generator<int, mixed>               $input
     * @param \Closure(mixed, int, Ancestors): bool $test
     *
     * @return \Generator<int, mixed>
     */
    public static function pruneFlag(\Generator $input, SkipFlag $flag, \Closure $test): \Generator
    {
        $ancestors = new Ancestors();
        foreach ($input as $depth => $node) {
            if (!$test($node, $depth, $ancestors)) {
                $flag->skip = true;
                continue;
            }
            yield $depth => $node;
        }
    }

    /**
     * KeepAncestors (EvaluateDescendants) as a generator: emits pending ancestors, then the match.
     *
     * @param \Generator<int, mixed>               $input
     * @param \Closure(mixed, int, Ancestors): bool $test
     *
     * @return \Generator<int, mixed>
     */
    public static function keepAncestors(\Generator $input, \Closure $test): \Generator
    {
        $ancestors = new Ancestors();
        $path = [];
        $emitted = 0;
        foreach ($input as $depth => $node) {
            if ($emitted > $depth) {
                $emitted = $depth;
            }
            $path[$depth] = $node;
            if (!$test($node, $depth, $ancestors)) {
                continue;
            }
            for ($d = $emitted; $d <= $depth; ++$d) {
                yield $d => $path[$d];
            }
            $emitted = $depth + 1;
        }
    }

    /**
     * NestedArraySink over depth => node entries (array payloads only, like FastNestedArraySink).
     *
     * @param iterable<int, mixed> $entries
     *
     * @return list<mixed>
     */
    public static function toNestedArray(iterable $entries, string $key = 'children'): array
    {
        $roots = [];
        $nodes = [];
        $children = [];
        $top = -1;
        foreach ($entries as $depth => $node) {
            while ($top >= $depth) {
                $built = $nodes[$top];
                $built[$key] = $children[$top];
                unset($nodes[$top], $children[$top]);
                if (--$top >= 0) {
                    $children[$top][] = $built;
                } else {
                    $roots[] = $built;
                }
            }
            $top = $depth;
            $nodes[$top] = $node;
            $children[$top] = [];
        }
        while ($top >= 0) {
            $built = $nodes[$top];
            $built[$key] = $children[$top];
            unset($nodes[$top], $children[$top]);
            if (--$top >= 0) {
                $children[$top][] = $built;
            } else {
                $roots[] = $built;
            }
        }

        return $roots;
    }
}
