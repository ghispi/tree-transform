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
 * Streams id/parent rows that are already in pre-order (every node directly followed by its subtree), e.g. a
 * nested set table queried with its parent column and ordered by left value. Memory: O(depth).
 *
 * A row whose parent is not one of the currently open ancestors raises an InvalidTreeException.
 */
final readonly class PreorderedAdjacencySource implements Source, StreamSource
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
        return new GeneratorCursor($this->stream());
    }

    /**
     * @internal
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    public function stream(): \Generator
    {
        return self::read(
            Rows::each($this->rows),
            $this->accessor->reader($this->fields->id),
            $this->accessor->reader($this->fields->parent),
            $this->rootParent === null ? null : Rows::key($this->rootParent),
        );
    }

    /**
     * @param iterable<mixed>        $rows
     * @param \Closure(mixed): mixed $readId
     * @param \Closure(mixed): mixed $readParent
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function read(iterable $rows, \Closure $readId, \Closure $readParent, int|string|null $rootParent): \Generator
    {
        /** @var array<int, int|string> $ids ids of open ancestors, indexed by depth */
        $ids = [];
        $open = 0;
        $skipBelow = -1;

        foreach ($rows as $row) {
            $id = $readId($row);
            if (!\is_int($id)) {
                $id = Rows::key($id);
            }
            $parent = $readParent($row);

            if ($parent === null || ($rootParent !== null && Rows::key($parent) === $rootParent)) {
                $open = 0;
            } else {
                $parentKey = \is_int($parent) ? $parent : Rows::key($parent);
                while ($open > 0 && $ids[$open - 1] !== $parentKey) {
                    --$open;
                }
                if ($open === 0) {
                    throw InvalidTreeException::at($id, \sprintf(
                        'parent %s is not an open ancestor. Rows must be in pre-order with parents before children.',
                        var_export($parentKey, true),
                    ));
                }
            }
            $depth = $open;
            $ids[$open++] = $id;

            if ($skipBelow >= 0) {
                if ($depth > $skipBelow) {
                    continue;
                }
                $skipBelow = -1;
            }

            if ((yield $depth => $row) === true) {
                $skipBelow = $depth;
            }
        }
    }
}
