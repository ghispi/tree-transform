<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Predicate;

/**
 * Decides whether a node matches. What happens to matching and non-matching nodes is up to the filter strategy.
 */
interface Predicate
{
    /**
     * @param mixed     $node      payload of the node being tested
     * @param int       $depth     depth of the node, 0 for roots
     * @param Ancestors $ancestors lazy view of the node's ancestors; ignore it if not needed
     */
    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool;
}
