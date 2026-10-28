<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\Rows;

/**
 * Streams id/parent rows that are already in pre-order (every node directly followed by its subtree), e.g. a
 * nested set table queried with its parent column and ordered by left value. Memory: O(depth).
 *
 * A row whose parent is not one of the currently open ancestors raises an InvalidTreeException.
 */
final readonly class PreorderedAdjacencySource implements Source
{
    /**
     * @param iterable<mixed> $rows       rows in pre-order
     * @param mixed           $rootParent parent value that marks a root besides null, e.g. 0
     */
    public function __construct(
        private iterable $rows,
        private AdjacencyListFields $fields = new AdjacencyListFields(),
        private Accessor $accessor = new AutoAccessor(),
        private mixed $rootParent = null,
    ) {}

    public function open(): Cursor
    {
        return new PreorderedAdjacencyCursor(
            $this->rows,
            $this->accessor->reader($this->fields->id),
            $this->accessor->reader($this->fields->parent),
            $this->rootParent === null ? null : Rows::key($this->rootParent),
        );
    }
}
