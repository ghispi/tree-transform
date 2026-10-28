<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\CycleDetectedException;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Exception\OrphanNodeException;
use Ghispi\TreeTransform\Internal\Rows;

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
final readonly class AdjacencyListSource implements Source
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
        $readId = $this->accessor->reader($this->fields->id);
        $readParent = $this->accessor->reader($this->fields->parent);
        $rootParent = $this->rootParent === null ? null : Rows::key($this->rootParent);

        $nodes = [];
        /** @var array<int|string, int> $offsetById */
        $offsetById = [];
        /** @var array<int|string, list<int>> $childrenByParentId */
        $childrenByParentId = [];
        $roots = [];

        $offset = 0;
        $iterator = Rows::iterator($this->rows);
        for ($iterator->rewind(); $iterator->valid(); $iterator->next()) {
            $row = $iterator->current();
            $nodes[$offset] = $row;
            $id = Rows::key($readId($row));
            if (isset($offsetById[$id])) {
                throw InvalidTreeException::at($id, 'duplicate id.');
            }
            $offsetById[$id] = $offset;
            $parent = $readParent($row);
            if ($parent === null || ($rootParent !== null && Rows::key($parent) === $rootParent)) {
                $roots[] = $offset;
            } else {
                $parentKey = Rows::key($parent);
                if ($parentKey === $id) {
                    throw CycleDetectedException::forIds([$id]);
                }
                $childrenByParentId[$parentKey][] = $offset;
            }
            ++$offset;
        }

        /** @var array<int, list<int>> $children */
        $children = [];
        foreach ($childrenByParentId as $parentId => $list) {
            if (isset($offsetById[$parentId])) {
                $children[$offsetById[$parentId]] = $list;
                continue;
            }
            match ($this->orphans) {
                OrphanPolicy::Throw => throw OrphanNodeException::forParent($parentId, \count($list)),
                OrphanPolicy::AsRoot => array_push($roots, ...$list),
                OrphanPolicy::Drop => null,
            };
        }
        unset($childrenByParentId);

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

        $this->assertNoCycles($roots, $children, $offset, $offsetById);

        return new AdjacencyListCursor($nodes, $roots, $children);
    }

    /**
     * @param list<int>              $roots
     * @param array<int, list<int>>  $children
     * @param array<int|string, int> $offsetById
     */
    private function assertNoCycles(array $roots, array $children, int $total, array $offsetById): void
    {
        $reachable = 0;
        $stack = $roots;
        $dropped = 0;
        while ($stack !== []) {
            $offset = array_pop($stack);
            ++$reachable;
            if (isset($children[$offset])) {
                array_push($stack, ...$children[$offset]);
            }
        }
        if ($this->orphans === OrphanPolicy::Drop) {
            // Dropped orphans are unreachable on purpose; only unreachable nodes in cycles are an error.
            $dropped = $this->countDroppedSubtrees($roots, $children, $total);
        }
        if ($reachable + $dropped === $total) {
            return;
        }

        $seen = [];
        $stack = $roots;
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
     * Counts nodes that are unreachable because an ancestor's parent is missing (dropped orphans), as opposed to
     * nodes in a cycle. A cycle has no member whose parent is missing.
     *
     * @param list<int>             $roots
     * @param array<int, list<int>> $children
     */
    private function countDroppedSubtrees(array $roots, array $children, int $total): int
    {
        // Every offset that appears as someone's child has an existing parent.
        $hasParent = [];
        foreach ($children as $list) {
            foreach ($list as $offset) {
                $hasParent[$offset] = true;
            }
        }
        $isRoot = array_fill_keys($roots, true);
        $stack = [];
        for ($offset = 0; $offset < $total; ++$offset) {
            if (!isset($hasParent[$offset]) && !isset($isRoot[$offset])) {
                $stack[] = $offset; // a dropped orphan
            }
        }
        $count = 0;
        while ($stack !== []) {
            $offset = array_pop($stack);
            ++$count;
            if (isset($children[$offset])) {
                array_push($stack, ...$children[$offset]);
            }
        }

        return $count;
    }
}
