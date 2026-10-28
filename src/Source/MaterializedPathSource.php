<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\Rows;

/**
 * Reads materialized path rows ordered by path.
 *
 * Ordering by path gives pre-order only when the separator sorts before every character used in ids; in SQL use a
 * binary collation (PostgreSQL COLLATE "C", MySQL utf8mb4_bin), as locale collations may ignore punctuation.
 * Every row's parent path must be present before it; violations are reported. Memory: O(depth).
 * skipSubtree() skips rows by path prefix.
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
    ) {}

    public function open(): Cursor
    {
        return new MaterializedPathCursor(
            Rows::iterator($this->rows),
            $this->accessor->reader($this->fields->path),
            $this->fields,
        );
    }
}
