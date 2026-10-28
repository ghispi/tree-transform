<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\Rows;

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
final readonly class NestedSetSource implements Source
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
        $fields = $this->fields;

        return new NestedSetCursor(
            Rows::iterator($this->rows),
            $this->accessor->reader($fields->left),
            $this->accessor->reader($fields->right),
            $fields->root === null ? null : $this->accessor->reader($fields->root),
            $fields,
        );
    }

}
