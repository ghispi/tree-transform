<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Internal;

use Ghispi\TreeTransform\Predicate\AllOf;
use Ghispi\TreeTransform\Predicate\AnyOf;
use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Predicate\DepthRange;
use Ghispi\TreeTransform\Predicate\FieldEquals;
use Ghispi\TreeTransform\Predicate\FieldIn;
use Ghispi\TreeTransform\Predicate\Not;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * Prepares a predicate for the hot loop of a strategy: the closure to call, and whether the predicate reads its
 * Ancestors argument (if not, strategies skip tracking ancestors).
 *
 * @internal
 */
final readonly class CompiledPredicate
{
    /**
     * @param \Closure(mixed, int, \Ghispi\TreeTransform\Predicate\Ancestors): mixed $test result is used as a boolean
     */
    private function __construct(
        public \Closure $test,
        public bool $needsAncestors,
    ) {}

    public static function of(Predicate $predicate): self
    {
        if ($predicate instanceof Callback) {
            return new self($predicate->closure(), self::closureNeedsAncestors($predicate->closure()));
        }

        return new self($predicate->matches(...), self::needsAncestors($predicate));
    }

    /**
     * Built-in predicates that never read ancestors are known; closures are checked for a third parameter; any other
     * predicate is assumed to read them.
     */
    public static function needsAncestors(Predicate $predicate): bool
    {
        return match (true) {
            $predicate instanceof Callback => self::closureNeedsAncestors($predicate->closure()),
            $predicate instanceof AllOf, $predicate instanceof AnyOf => array_any(
                $predicate->predicates(),
                static fn(Predicate $inner): bool => self::needsAncestors($inner),
            ),
            $predicate instanceof Not => self::needsAncestors($predicate->inner()),
            $predicate instanceof DepthRange, $predicate instanceof FieldEquals, $predicate instanceof FieldIn => false,
            default => true,
        };
    }

    private static function closureNeedsAncestors(\Closure $closure): bool
    {
        $function = new \ReflectionFunction($closure);

        return $function->getNumberOfParameters() >= 3 || $function->isVariadic();
    }
}
