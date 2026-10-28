<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\ArrayKeyAccessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Internal\GeneratorCursor;
use Ghispi\TreeTransform\Internal\Rows;
use Ghispi\TreeTransform\Internal\StreamSource;

/**
 * Reads nested set rows ordered by left value (and by root first, when several trees share the rows).
 *
 * Depth is computed from the right values of the open ancestors, so a level column is not needed and any subset
 * of a valid nested set (e.g. only some branches) is still read correctly. Memory: O(depth).
 * skipSubtree() skips rows by comparing left values only.
 *
 * Checks performed while reading: integer left/right values, left < right, increasing left values within a tree,
 * and child intervals inside their parent's interval.
 */
final readonly class NestedSetSource implements Source, StreamSource
{
    /**
     * @param iterable<mixed> $rows rows ordered by (root,) left
     */
    public function __construct(
        private iterable $rows,
        private NestedSetFields $fields = new NestedSetFields(),
        private Accessor $accessor = new AutoAccessor(),
    ) {}

    public function open(): Cursor
    {
        return new GeneratorCursor($this->stream());
    }

    /**
     * @internal
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    public function stream(): \Generator
    {
        $fields = $this->fields;

        return self::read(
            Rows::each($this->rows),
            $this->accessor->reader($fields->left),
            $this->accessor->reader($fields->right),
            $fields->root === null ? null : $this->accessor->reader($fields->root),
            $fields,
            // Array rows read through the default accessors are read inline, without a closure call per field.
            $this->accessor instanceof ArrayKeyAccessor || $this->accessor instanceof AutoAccessor,
        );
    }

    /**
     * @param iterable<mixed>               $rows
     * @param \Closure(mixed): mixed        $readLeft
     * @param \Closure(mixed): mixed        $readRight
     * @param (\Closure(mixed): mixed)|null $readRoot
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function read(
        iterable $rows,
        \Closure $readLeft,
        \Closure $readRight,
        ?\Closure $readRoot,
        NestedSetFields $fields,
        bool $inline,
    ): \Generator {
        $leftKey = $fields->left;
        $rightKey = $fields->right;
        /** @var array<int, int> $rights right values of open ancestors, indexed by depth */
        $rights = [];
        $open = 0;
        $lastLeft = \PHP_INT_MIN;
        $skipBefore = \PHP_INT_MIN;
        $hasRoot = false;
        $lastRootKey = null;

        foreach ($rows as $row) {
            if ($readRoot !== null) {
                $root = $readRoot($row);
                $rootKey = match (true) {
                    $root === null => "\0null",
                    \is_object($root) && !$root instanceof \Stringable => "\0object#" . spl_object_id($root),
                    default => Rows::key($root),
                };
                if (!$hasRoot || $rootKey !== $lastRootKey) {
                    // A new tree starts: forget everything about the previous one.
                    $hasRoot = true;
                    $lastRootKey = $rootKey;
                    $open = 0;
                    $lastLeft = \PHP_INT_MIN;
                    $skipBefore = \PHP_INT_MIN;
                }
            }

            $array = $inline && \is_array($row);
            $left = $array ? ($row[$leftKey] ?? null) : $readLeft($row);
            if (!\is_int($left)) {
                $left = Rows::int($left, $leftKey, $row);
            }
            if ($left < $skipBefore) {
                continue;
            }
            $right = $array ? ($row[$rightKey] ?? null) : $readRight($row);
            if (!\is_int($right)) {
                $right = Rows::int($right, $rightKey, $row);
            }

            if ($left >= $right) {
                throw InvalidTreeException::at(null, \sprintf('left value %d is not lower than right value %d.', $left, $right));
            }
            if ($left <= $lastLeft) {
                throw InvalidTreeException::at(null, \sprintf(
                    'left value %d does not increase (previous %d). Order rows by %s%s.',
                    $left,
                    $lastLeft,
                    $fields->root === null ? '' : $fields->root . ', ',
                    $fields->left,
                ));
            }
            while ($open > 0 && $rights[$open - 1] < $left) {
                --$open;
            }
            if ($open > 0 && $right > $rights[$open - 1]) {
                throw InvalidTreeException::at(null, \sprintf(
                    'interval %d..%d overlaps its parent interval ending at %d.',
                    $left,
                    $right,
                    $rights[$open - 1],
                ));
            }

            $depth = $open;
            $rights[$open++] = $right;
            $lastLeft = $left;

            if ((yield $depth => $row) === true) {
                $skipBefore = $right;
            }
        }
    }
}
