<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Exception;

/**
 * Adjacency list input references a parent id that does not exist.
 */
final class OrphanNodeException extends InvalidTreeException
{
    public static function forParent(int|string $parentId, int $childCount): self
    {
        return new self(\sprintf(
            'Parent %s of %d node(s) does not exist. If this value marks root nodes, pass it as "rootParent"; '
            . 'otherwise choose an OrphanPolicy (AsRoot or Drop).',
            var_export($parentId, true),
            $childCount,
        ));
    }
}
