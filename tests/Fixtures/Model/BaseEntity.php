<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures\Model;

/**
 * Mapped-superclass-like parent declaring private tree fields.
 */
abstract class BaseEntity
{
    private int|string $id;

    private ?int $lft;

    private ?int $rgt;

    public function __construct(int|string $id, ?int $lft = null, ?int $rgt = null)
    {
        $this->id = $id;
        $this->lft = $lft;
        $this->rgt = $rgt;
    }

    /**
     * Not a getter (AutoAccessor would prefer getters); keeps the private fields observable.
     *
     * @return array{int|string, ?int, ?int}
     */
    protected function treeFields(): array
    {
        return [$this->id, $this->lft, $this->rgt];
    }
}
