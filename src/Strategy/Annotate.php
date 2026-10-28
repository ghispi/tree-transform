<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * Keeps every node and tells how it relates to the matches (MatchState) — for highlighting search hits and
 * greying out the rest instead of removing it.
 *
 *     A ✓  → Match                   F ✗ → DescendantOfMatch (under A)
 *     B ✗  → AncestorOfMatch (C)     H ✗ → AncestorOfMatch (I)
 *
 * By default each payload is wrapped in an Annotated object; pass $combine to merge the state into the payload,
 * e.g. fn (array $n, MatchState $s) => $n + ['state' => $s->value].
 *
 * Whether a node is an ancestor of a match is known only when its subtree has been read, so output waits for the
 * shallowest undecided node: memory up to the size of one root's tree.
 */
final readonly class Annotate implements FilterStrategy
{
    /** @var \Closure(mixed, MatchState): mixed */
    private \Closure $combine;

    /**
     * @param \Closure|null $combine
     */
    public function __construct(?\Closure $combine = null)
    {
        $this->combine = $combine ?? static fn(mixed $node, MatchState $state): Annotated => new Annotated($node, $state);
    }

    public function apply(Cursor $input, Predicate $predicate): Cursor
    {
        return new AnnotateCursor($input, $predicate, $this->combine);
    }
}
