<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Predicate;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;

/**
 * Matches nodes whose field is identical (===) to a value. Works with arrays and objects.
 */
final readonly class FieldEquals implements Predicate
{
    /** @var \Closure(mixed): mixed */
    private \Closure $read;

    public function __construct(string $field, private mixed $value, ?Accessor $accessor = null)
    {
        $this->read = ($accessor ?? new AutoAccessor())->reader($field);
    }

    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool
    {
        return ($this->read)($node) === $this->value;
    }
}
