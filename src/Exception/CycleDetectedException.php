<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Exception;

/**
 * Adjacency list input contains nodes that cannot be reached from any root (a parent cycle).
 */
final class CycleDetectedException extends InvalidTreeException
{
    /** @var list<int|string> */
    private array $ids = [];

    /**
     * @internal
     *
     * @param list<int|string> $ids
     */
    public static function forIds(array $ids): self
    {
        $shown = \array_slice($ids, 0, 10);

        $exception = new self(\sprintf(
            'Parent cycle detected: %d node(s) are not reachable from any root (e.g. %s).',
            \count($ids),
            implode(', ', array_map(static fn(int|string $id): string => var_export($id, true), $shown)),
        ));
        $exception->ids = $ids;

        return $exception;
    }

    /**
     * Ids of all nodes that are not reachable from a root, in input order.
     *
     * @return list<int|string>
     */
    public function ids(): array
    {
        return $this->ids;
    }
}
