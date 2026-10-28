<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Predicate;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;

/**
 * Matches nodes whose field value is one of the given int/string values (hash lookup, O(1) per node).
 *
 * Numeric strings and integers are treated as equal ("5" matches 5), like PHP array keys.
 */
final readonly class FieldIn implements Predicate
{
    /** @var \Closure(mixed): mixed */
    private \Closure $read;

    /** @var array<int|string, true> */
    private array $values;

    /**
     * @param iterable<int|string> $values
     */
    public function __construct(string $field, iterable $values, ?Accessor $accessor = null)
    {
        $this->read = ($accessor ?? new AutoAccessor())->reader($field);
        $set = [];
        foreach ($values as $value) {
            // @phpstan-ignore booleanAnd.alwaysFalse, function.alreadyNarrowedType (guards untyped callers)
            if (!\is_int($value) && !\is_string($value)) {
                throw new InvalidArgumentException('FieldIn only supports int and string values.');
            }
            $set[$value] = true;
        }
        $this->values = $set;
    }

    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool
    {
        $value = ($this->read)($node);

        return (\is_int($value) || \is_string($value)) && isset($this->values[$value]);
    }
}
