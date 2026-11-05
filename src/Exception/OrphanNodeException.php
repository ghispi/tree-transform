<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Exception;

/**
 * Adjacency list input references a parent id that does not exist.
 */
final class OrphanNodeException extends InvalidTreeException
{
    private int|string $parentId = '';

    private int $childCount = 0;

    /**
     * @internal
     */
    public static function forParent(int|string $parentId, int $childCount): self
    {
        $exception = new self(\sprintf(
            'Parent %s of %d node(s) does not exist. If this value marks root nodes, pass it as "rootParent"; '
            . 'otherwise choose an OrphanPolicy (AsRoot or Drop).',
            var_export($parentId, true),
            $childCount,
        ));
        $exception->parentId = $parentId;
        $exception->childCount = $childCount;

        return $exception;
    }

    /**
     * The missing parent id (normalised: integer strings become integers).
     */
    public function parentId(): int|string
    {
        return $this->parentId;
    }

    /**
     * Number of nodes that reference the missing parent.
     */
    public function childCount(): int
    {
        return $this->childCount;
    }
}
