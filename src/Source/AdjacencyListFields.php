<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

/**
 * Field names of an adjacency list node.
 */
final readonly class AdjacencyListFields
{
    /**
     * @param string $id     node id field
     * @param string $parent parent id field; null (or $rootParent) marks a root
     */
    public function __construct(
        public string $id = 'id',
        public string $parent = 'parent_id',
    ) {}

    /**
     * Gedmo-style naming: "id" and "parent" (array hydration with IDENTITY(n.parent) AS parent).
     */
    public static function gedmo(): self
    {
        return new self('id', 'parent');
    }
}
