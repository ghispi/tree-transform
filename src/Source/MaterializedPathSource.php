<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Internal\GeneratorCursor;
use Ghispi\TreeTransform\Internal\Rows;
use Ghispi\TreeTransform\Internal\StreamSource;

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
final readonly class MaterializedPathSource implements Source, StreamSource
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
        return new GeneratorCursor($this->stream());
    }

    /**
     * @internal
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    public function stream(): \Generator
    {
        return self::read(Rows::each($this->rows), $this->accessor->reader($this->fields->path), $this->fields, $this->allowGaps);
    }

    /**
     * @param iterable<mixed>        $rows
     * @param \Closure(mixed): mixed $readPath
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function read(iterable $rows, \Closure $readPath, MaterializedPathFields $fields, bool $allowGaps): \Generator
    {
        $sep = $fields->separator;
        /** @var array<int, string> $open normalised paths ("a,b,") of open ancestors, indexed by depth */
        $open = [];
        /** @var array<int, int> $segmentCounts segment counts of open ancestors, indexed by depth */
        $segmentCounts = [];
        $openCount = 0;
        $skipPrefix = null;
        // Segment count of the first root; every root must have it unless gaps are allowed (ordering check).
        $rootSegments = 0;

        foreach ($rows as $row) {
            $raw = $readPath($row);
            if (!\is_string($raw) && !\is_int($raw)) {
                throw InvalidTreeException::at(null, \sprintf('path field "%s" must be a string, got %s.', $fields->path, get_debug_type($raw)));
            }
            $path = (string) $raw;
            if (str_starts_with($path, $sep)) {
                $path = substr($path, \strlen($sep));
            }
            if (str_ends_with($path, $sep)) {
                $path = substr($path, 0, -\strlen($sep));
            }
            if ($path === '') {
                throw InvalidTreeException::at(null, 'empty path.');
            }
            $key = $path . $sep;
            if ($skipPrefix !== null) {
                if (str_starts_with($key, $skipPrefix)) {
                    continue;
                }
                $skipPrefix = null;
            }
            while ($openCount > 0 && !str_starts_with($key, $open[$openCount - 1])) {
                --$openCount;
            }
            $segments = substr_count($key, $sep);
            if ($openCount === 0 && !$allowGaps) {
                // Roots of one read share a level. A deeper "root" is a row whose ancestors came earlier and were
                // closed by misordered rows — typically a collation that ignores the separator.
                if ($rootSegments === 0) {
                    $rootSegments = $segments;
                } elseif ($segments !== $rootSegments) {
                    throw InvalidTreeException::at($raw, 'rows are not in tree order: it is not below the open rows, and not at the level of the first root. Order by path with a binary collation, e.g. COLLATE "C" or utf8mb4_bin.');
                }
            }
            if ($openCount > 0) {
                $parentSegments = $segmentCounts[$openCount - 1];
                if ($segments <= $parentSegments) {
                    throw InvalidTreeException::at($raw, 'duplicate path.');
                }
                if ($segments !== $parentSegments + 1 && !$allowGaps) {
                    throw InvalidTreeException::at($raw, 'its parent path is missing or comes later. Order rows by path (binary collation) and include every ancestor.');
                }
            }
            $depth = $openCount;
            $segmentCounts[$openCount] = $segments;
            $open[$openCount++] = $key;

            if ((yield $depth => $row) === true) {
                $skipPrefix = $key;
            }
        }
    }
}
