<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Predicate;

/**
 * Keeps an {@see Ancestors} view up to date for a custom filter strategy.
 *
 *     $tracker = new AncestorTracker();
 *     while ($input->next()) {
 *         $tracker->enter($input->node(), $input->depth());
 *         $matches = $predicate->matches($input->node(), $input->depth(), $tracker->ancestors());
 *     }
 *
 * Call enter() with every node the strategy reads, in pre-order, right before testing it. One tracker per
 * traversal; the view it returns is the same object for every node.
 */
final readonly class AncestorTracker
{
    private Ancestors $ancestors;

    public function __construct()
    {
        $this->ancestors = Ancestors::of();
    }

    /**
     * Records the node about to be tested; its ancestors are the nodes recorded at lower depths.
     */
    public function enter(mixed $node, int $depth): void
    {
        $this->ancestors->enter($node, $depth);
    }

    /**
     * The view to pass to Predicate::matches(): the ancestors of the node last entered.
     */
    public function ancestors(): Ancestors
    {
        return $this->ancestors;
    }
}
