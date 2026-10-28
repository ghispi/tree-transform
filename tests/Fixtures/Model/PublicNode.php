<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures\Model;

/**
 * Payload with public properties.
 */
final class PublicNode
{
    /**
     * @param list<PublicNode> $children
     */
    public function __construct(
        public int|string $id,
        public int|string|null $parentId = null,
        public array $children = [],
        public ?int $lft = null,
        public ?int $rgt = null,
        public ?string $path = null,
        public bool $match = false,
    ) {}
}
