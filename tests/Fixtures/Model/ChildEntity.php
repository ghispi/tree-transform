<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures\Model;

/**
 * Entity whose tree fields are private properties of its parent class.
 */
final class ChildEntity extends BaseEntity
{
    public string $title = 'child';
}
