<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Predicate;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;

/**
 * Matches nodes whose field equals a value. Works with arrays and objects.
 *
 * Comparison is strict (===), except that integers and numeric strings are equal ("5" matches 5), because
 * databases often return integers as strings.
 */
final readonly class FieldEquals implements Predicate
{
    /** @var \Closure(mixed): mixed */
    private \Closure $read;

    /** The value as an integer string when it is an integer or integer string, else null. */
    private ?string $numeric;

    public function __construct(string $field, private mixed $value, ?Accessor $accessor = null)
    {
        $this->read = ($accessor ?? new AutoAccessor())->reader($field);
        $this->numeric = \is_int($value) || (\is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1)
            ? (string) $value
            : null;
    }

    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool
    {
        $actual = ($this->read)($node);
        if ($actual === $this->value) {
            return true;
        }

        return $this->numeric !== null && (\is_int($actual) || \is_string($actual)) && (string) $actual === $this->numeric;
    }
}
