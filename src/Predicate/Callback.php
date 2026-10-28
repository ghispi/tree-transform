<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Predicate;

/**
 * Wraps a closure taking one to three arguments: fn (mixed $node, int $depth, Ancestors $ancestors): bool.
 */
final readonly class Callback implements Predicate
{
    /**
     * @param \Closure $test
     */
    public function __construct(private \Closure $test) {}

    /**
     * Returns the predicate unchanged, or wraps a closure.
     *
     * @param Predicate|\Closure $predicate
     */
    public static function of(Predicate|\Closure $predicate): Predicate
    {
        return $predicate instanceof Predicate ? $predicate : new self($predicate);
    }

    /**
     * The wrapped closure.
     */
    public function closure(): \Closure
    {
        return $this->test;
    }

    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool
    {
        return (bool) ($this->test)($node, $depth, $ancestors);
    }
}
