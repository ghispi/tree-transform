<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Exception;

/**
 * Adjacency list input contains nodes that cannot be reached from any root (a parent cycle).
 */
final class CycleDetectedException extends InvalidTreeException
{
    /**
     * @param list<int|string> $ids
     */
    public static function forIds(array $ids): self
    {
        $shown = \array_slice($ids, 0, 10);

        return new self(\sprintf(
            'Parent cycle detected: %d node(s) are not reachable from any root (e.g. %s).',
            \count($ids),
            implode(', ', array_map(static fn(int|string $id): string => var_export($id, true), $shown)),
        ));
    }
}
