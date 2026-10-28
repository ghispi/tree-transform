<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\ArrayKeyAccessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\CycleDetectedException;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Exception\OrphanNodeException;
use Ghispi\TreeTransform\Internal\GeneratorCursor;
use Ghispi\TreeTransform\Internal\OffsetTree;
use Ghispi\TreeTransform\Internal\Rows;
use Ghispi\TreeTransform\Internal\StreamSource;

/**
 * Reads id/parent rows in any order.
 *
 * Because rows can come in any order, open() reads all of them and builds an index of integer offsets (no objects
 * per node): memory O(n). If your rows are already in pre-order (e.g. ordered by a nested set left value), use
 * PreorderedAdjacencySource, which streams.
 *
 * Roots are rows whose parent is null or equal to $rootParent. Siblings keep input order unless $siblingOrder names
 * a field to sort them by (ascending, <=>). Duplicate ids, parent cycles and (by default) missing parents throw.
 */
final readonly class AdjacencyListSource implements Source, StreamSource
{
    /**
     * @param iterable<mixed> $rows
     * @param mixed           $rootParent parent value that marks a root besides null, e.g. 0
     * @param string|null     $siblingOrder field to sort siblings by
     */
    public function __construct(
        private iterable $rows,
        private AdjacencyListFields $fields = new AdjacencyListFields(),
        private Accessor $accessor = new AutoAccessor(),
        private OrphanPolicy $orphans = OrphanPolicy::Throw,
        private mixed $rootParent = null,
        private ?string $siblingOrder = null,
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
        $idField = $this->fields->id;
        $parentField = $this->fields->parent;
        $readId = $this->accessor->reader($idField);
        $readParent = $this->accessor->reader($parentField);
        // Array rows read through the default accessors are read inline, without a closure call per field.
        $inline = $this->accessor instanceof ArrayKeyAccessor || $this->accessor instanceof AutoAccessor;
        $rootParent = $this->rootParent === null ? null : Rows::key($this->rootParent);

        $nodes = [];
        /** @var array<int|string, int> $offsetById */
        $offsetById = [];
        /** @var array<int, int|string> $parentIds parent id of every non-root row, by offset */
        $parentIds = [];
        $roots = [];

        $offset = 0;
        foreach (Rows::each($this->rows) as $row) {
            $nodes[$offset] = $row;
            if ($inline && \is_array($row)) {
                $id = $row[$idField] ?? null;
                $parent = $row[$parentField] ?? null;
            } else {
                $id = $readId($row);
                $parent = $readParent($row);
            }
            if (!\is_int($id)) {
                $id = Rows::key($id);
            }
            if (isset($offsetById[$id])) {
                throw InvalidTreeException::at($id, 'duplicate id.');
            }
            $offsetById[$id] = $offset;
            if ($parent === null) {
                $roots[] = $offset;
            } else {
                $parentKey = \is_int($parent) ? $parent : Rows::key($parent);
                if ($parentKey === $rootParent) {
                    $roots[] = $offset;
                } elseif ($parentKey === $id) {
                    throw CycleDetectedException::forIds([$id]);
                } else {
                    $parentIds[$offset] = $parentKey;
                }
            }
            ++$offset;
        }

        // Second pass, in input order: child lists keep input order without merging.
        /** @var array<int, list<int>> $children child offsets by parent offset */
        $children = [];
        /** @var array<int|string, list<int>> $orphans by missing parent id */
        $orphans = [];
        // Whether a child comes before its parent; if none does, following parents always leads to earlier rows.
        $forward = false;
        foreach ($parentIds as $child => $parentId) {
            if (isset($offsetById[$parentId])) {
                $parentOffset = $offsetById[$parentId];
                if ($parentOffset > $child) {
                    $forward = true;
                }
                $children[$parentOffset][] = $child;
            } else {
                $orphans[$parentId][] = $child;
            }
        }
        unset($parentIds);

        foreach ($orphans as $parentId => $list) {
            match ($this->orphans) {
                OrphanPolicy::Throw => throw OrphanNodeException::forParent($parentId, \count($list)),
                OrphanPolicy::AsRoot => array_push($roots, ...$list),
                OrphanPolicy::Drop => null,
            };
        }

        if ($this->orphans === OrphanPolicy::AsRoot) {
            sort($roots); // keep input order across real roots and promoted orphans
        }

        if ($this->siblingOrder !== null) {
            $readOrder = $this->accessor->reader($this->siblingOrder);
            $compare = static fn(int $a, int $b): int => $readOrder($nodes[$a]) <=> $readOrder($nodes[$b]);
            usort($roots, $compare);
            foreach ($children as &$list) {
                usort($list, $compare);
            }
            unset($list);
        }

        if ($forward) {
            $this->assertNoCycles($roots, $children, $offset, $offsetById);
        }

        return OffsetTree::stream($nodes, $roots, $children);
    }

    /**
     * @param list<int>              $roots
     * @param array<int, list<int>>  $children
     * @param array<int|string, int> $offsetById
     */
    private function assertNoCycles(array $roots, array $children, int $total, array $offsetById): void
    {
        // Dropped orphans are unreachable on purpose; only unreachable nodes in cycles are an error.
        $starts = $this->orphans === OrphanPolicy::Drop
            ? [...$roots, ...self::droppedOrphans($roots, $children, $total)]
            : $roots;

        $reachable = 0;
        $stack = $starts;
        while ($stack !== []) {
            $offset = array_pop($stack);
            ++$reachable;
            if (isset($children[$offset])) {
                array_push($stack, ...$children[$offset]);
            }
        }
        if ($reachable === $total) {
            return;
        }

        $seen = [];
        $stack = $starts;
        while ($stack !== []) {
            $offset = array_pop($stack);
            $seen[$offset] = true;
            if (isset($children[$offset])) {
                array_push($stack, ...$children[$offset]);
            }
        }
        $ids = [];
        foreach ($offsetById as $id => $offset) {
            if (!isset($seen[$offset])) {
                $ids[] = $id;
            }
        }

        throw CycleDetectedException::forIds($ids);
    }

    /**
     * Offsets of rows whose parent is missing (dropped orphans), as opposed to nodes in a cycle: a cycle has no
     * member whose parent is missing.
     *
     * @param list<int>             $roots
     * @param array<int, list<int>> $children
     *
     * @return list<int>
     */
    private static function droppedOrphans(array $roots, array $children, int $total): array
    {
        // Every offset that appears as someone's child has an existing parent.
        $hasParent = [];
        foreach ($children as $list) {
            foreach ($list as $offset) {
                $hasParent[$offset] = true;
            }
        }
        $isRoot = array_fill_keys($roots, true);
        $orphans = [];
        for ($offset = 0; $offset < $total; ++$offset) {
            if (!isset($hasParent[$offset]) && !isset($isRoot[$offset])) {
                $orphans[] = $offset;
            }
        }

        return $orphans;
    }
}
