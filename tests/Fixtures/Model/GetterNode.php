<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures\Model;

/**
 * Payload exposing fields only through getters (get…, is…, has…, bare name).
 */
final class GetterNode
{
    /**
     * @param list<GetterNode> $children
     */
    public function __construct(
        private readonly int|string $id,
        private readonly int|string|null $parentId = null,
        private readonly array $children = [],
        private readonly bool $active = false,
        private readonly bool $kids = false,
        private readonly string $title = '',
    ) {}

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getParentId(): int|string|null
    {
        return $this->parentId;
    }

    /**
     * @return list<GetterNode>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function hasKids(): bool
    {
        return $this->kids;
    }

    public function title(): string
    {
        return $this->title;
    }
}
