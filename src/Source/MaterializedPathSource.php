<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * Reads materialized path rows ordered by path.
 *
 * Ordering by path gives pre-order only when the separator sorts before every character used in ids; in SQL use a
 * binary collation (PostgreSQL COLLATE "C", MySQL utf8mb4_bin), as locale collations may ignore punctuation.
 * A row that is not below any open row starts a new root, so a subtree (all paths with a common prefix) can be
 * read on its own. Below a root, every row's parent path must be present before it, unless $allowGaps is true:
 * then a row attaches to its nearest present ancestor (as if the missing nodes were removed with Promote).
 * Memory: O(depth). skipSubtree() skips rows by path prefix.
 */
final readonly class MaterializedPathSource implements Source
{
    /**
     * @param iterable<mixed> $rows rows ordered by path
     */
    public function __construct(
        private iterable $rows,
        private MaterializedPathFields $fields = new MaterializedPathFields(),
        private Accessor $accessor = new AutoAccessor(),
        private bool $allowGaps = false,
    ) {}

    public function open(): Cursor
    {
        return new MaterializedPathCursor(
            $this->rows,
            $this->accessor->reader($this->fields->path),
            $this->fields,
            $this->allowGaps,
        );
    }
}
