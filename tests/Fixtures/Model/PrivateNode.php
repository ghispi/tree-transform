<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures\Model;

/**
 * Entity-like payload with private fields and no getters (like a Gedmo entity read by the bridge).
 */
final class PrivateNode
{
    private ?int $lft;

    protected ?int $rgt;

    public function __construct(
        private int|string $id,
        ?int $lft = null,
        ?int $rgt = null,
    ) {
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
