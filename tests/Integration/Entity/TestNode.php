<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Integration\Entity;

interface TestNode
{
    public function getId(): ?int;

    public function getTitle(): string;
}
