<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures\Oracle;

use Ghispi\TreeTransform\Strategy\MatchState;
use Ghispi\TreeTransform\Strategy\OnMatch;

/**
 * Reference implementations: obvious recursive code over a fully built tree, no streaming, no optimisation.
 *
 * Predicates take (payload, depth, list of ancestor payloads root first). Ancestors are always those of the
 * input tree, like the library's Ancestors view.
 *
 * @phpstan-type OraclePredicate \Closure(mixed, int, list<mixed>): bool
 */
final class Oracle
{
    // ------------------------------------------------------------------ strategies

    /**
     * @param array<mixed>    $forest
     * @param OraclePredicate $predicate
     *
     * @return list<Node>
     */
    public static function prune(array $forest, \Closure $predicate, OnMatch $onMatch = OnMatch::EvaluateDescendants): array
    {
        return self::pruneLevel(self::nodes($forest), $predicate, $onMatch, 0, []);
    }

    /**
     * @param array<mixed>    $forest
     * @param OraclePredicate $predicate
     *
     * @return list<Node>
     */
    public static function keepAncestors(array $forest, \Closure $predicate, OnMatch $onMatch = OnMatch::EvaluateDescendants): array
    {
        return self::keepAncestorsLevel(self::nodes($forest), $predicate, $onMatch, 0, []);
    }

    /**
     * @param array<mixed>    $forest
     * @param OraclePredicate $predicate
     *
     * @return list<Node>
     */
    public static function promote(array $forest, \Closure $predicate, OnMatch $onMatch = OnMatch::EvaluateDescendants): array
    {
        return self::promoteLevel(self::nodes($forest), $predicate, $onMatch, 0, []);
    }

    /**
     * Kept: (siblings) every root, every child of a strict ancestor of an active node, and nodes at most
     * $activeChildrenDepth levels below an active node; (no siblings) strict ancestors of active nodes and nodes at
     * most $activeChildrenDepth levels below an active node (the active node itself included).
     *
     * @param array<mixed>    $forest
     * @param OraclePredicate $predicate
     *
     * @return list<Node>
     */
    public static function activeTrail(
        array $forest,
        \Closure $predicate,
        bool $siblings = true,
        int $activeChildrenDepth = 1,
        bool $multiple = true,
    ): array {
        /** @var \SplObjectStorage<Node, true> $active */
        $active = new \SplObjectStorage();
        self::findActive(self::nodes($forest), $predicate, 0, [], $multiple, $active);
        /** @var \SplObjectStorage<Node, true> $trail */
        $trail = new \SplObjectStorage();
        self::markTrail(self::nodes($forest), $active, $trail);

        return self::trailLevel(self::nodes($forest), 0, null, false, $active, $trail, $siblings, $activeChildrenDepth);
    }

    /**
     * Every node kept; payloads replaced by $combine(payload, state).
     *
     * @param array<mixed>                        $forest
     * @param OraclePredicate                     $predicate
     * @param \Closure(mixed, MatchState): mixed  $combine
     *
     * @return list<Node>
     */
    public static function annotate(array $forest, \Closure $predicate, \Closure $combine): array
    {
        return self::annotateLevel(self::nodes($forest), $predicate, $combine, 0, [], false)[0];
    }

    /**
     * Keeps a node when its subtree (itself included) has at least $min matching nodes. Depths unchanged.
     *
     * @param array<mixed>    $forest
     * @param OraclePredicate $predicate
     *
     * @return list<Node>
     */
    public static function minMatches(array $forest, \Closure $predicate, int $min): array
    {
        return self::minMatchesLevel(self::nodes($forest), $predicate, $min, 0, [])[0];
    }

    /**
     * Prune, but each run of consecutive removed siblings becomes one $factory(count, depth) node in its place.
     *
     * @param array<mixed>              $forest
     * @param OraclePredicate           $predicate
     * @param \Closure(int, int): mixed $factory
     *
     * @return list<Node>
     */
    public static function placeholder(array $forest, \Closure $predicate, \Closure $factory, OnMatch $onMatch = OnMatch::EvaluateDescendants): array
    {
        return self::placeholderLevel(self::nodes($forest), $predicate, $factory, $onMatch, 0, []);
    }

    // ------------------------------------------------------------------ stages

    /**
     * @param array<mixed> $forest
     *
     * @return list<Node>
     */
    public static function maxDepth(array $forest, int $max, int $depth = 0): array
    {
        $out = [];
        foreach (self::nodes($forest) as $node) {
            $out[] = new Node($node->payload, $depth >= $max ? [] : self::maxDepth($node->children, $max, $depth + 1));
        }

        return $out;
    }

    /**
     * @param array<mixed> $forest
     *
     * @return list<Node>
     */
    public static function depthWindow(array $forest, int $min, int $max): array
    {
        $roots = self::nodesAtDepth(self::nodes($forest), $min);

        return $max === \PHP_INT_MAX ? $roots : self::maxDepth($roots, $max - $min);
    }

    /**
     * @param array<mixed> $forest
     *
     * @return list<array{0: int, 1: mixed}>
     */
    public static function limit(array $forest, int $count): array
    {
        return \array_slice(Node::flatten(self::nodes($forest)), 0, $count);
    }

    /**
     * @param array<mixed>               $forest
     * @param \Closure(mixed, mixed): int $compare
     *
     * @return list<Node>
     */
    public static function sortSiblings(array $forest, \Closure $compare): array
    {
        $out = [];
        foreach (self::nodes($forest) as $node) {
            $out[] = new Node($node->payload, self::sortSiblings($node->children, $compare));
        }
        usort($out, static fn(Node $a, Node $b): int => $compare($a->payload, $b->payload));

        return $out;
    }

    /**
     * @param array<mixed>           $forest
     * @param \Closure(mixed, int): mixed $transform
     *
     * @return list<Node>
     */
    public static function map(array $forest, \Closure $transform, int $depth = 0): array
    {
        $out = [];
        foreach (self::nodes($forest) as $node) {
            $out[] = new Node($transform($node->payload, $depth), self::map($node->children, $transform, $depth + 1));
        }

        return $out;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param array<mixed> $forest
     *
     * @return list<Node>
     */
    private static function nodes(array $forest): array
    {
        $out = [];
        foreach ($forest as $node) {
            if (!$node instanceof Node) {
                throw new \InvalidArgumentException('Oracle input must be a list of Node.');
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * @param list<Node>      $nodes
     * @param OraclePredicate $predicate
     * @param list<mixed>     $ancestors
     *
     * @return array{0: list<Node>, 1: int} kept nodes, total matches in $nodes' subtrees
     */
    private static function minMatchesLevel(array $nodes, \Closure $predicate, int $min, int $depth, array $ancestors): array
    {
        $out = [];
        $total = 0;
        foreach ($nodes as $node) {
            $count = $predicate($node->payload, $depth, $ancestors) ? 1 : 0;
            [$children, $below] = self::minMatchesLevel($node->children, $predicate, $min, $depth + 1, [...$ancestors, $node->payload]);
            $count += $below;
            if ($count >= $min) {
                $out[] = new Node($node->payload, $children);
            }
            $total += $count;
        }

        return [$out, $total];
    }

    /**
     * @param list<Node>                $nodes
     * @param OraclePredicate           $predicate
     * @param \Closure(int, int): mixed $factory
     * @param list<mixed>               $ancestors
     *
     * @return list<Node>
     */
    private static function placeholderLevel(array $nodes, \Closure $predicate, \Closure $factory, OnMatch $onMatch, int $depth, array $ancestors): array
    {
        $out = [];
        $run = 0;
        foreach ($nodes as $node) {
            if (!$predicate($node->payload, $depth, $ancestors)) {
                ++$run;
                continue;
            }
            if ($run > 0) {
                $out[] = new Node($factory($run, $depth));
                $run = 0;
            }
            $out[] = match ($onMatch) {
                OnMatch::KeepDescendants => $node,
                OnMatch::DropDescendants => new Node($node->payload),
                OnMatch::EvaluateDescendants => new Node(
                    $node->payload,
                    self::placeholderLevel($node->children, $predicate, $factory, $onMatch, $depth + 1, [...$ancestors, $node->payload]),
                ),
            };
        }
        if ($run > 0) {
            $out[] = new Node($factory($run, $depth));
        }

        return $out;
    }

    /**
     * @param list<Node>      $nodes
     * @param OraclePredicate $predicate
     * @param list<mixed>     $ancestors
     *
     * @return list<Node>
     */
    private static function pruneLevel(array $nodes, \Closure $predicate, OnMatch $onMatch, int $depth, array $ancestors): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!$predicate($node->payload, $depth, $ancestors)) {
                continue;
            }
            $out[] = match ($onMatch) {
                OnMatch::KeepDescendants => $node,
                OnMatch::DropDescendants => new Node($node->payload),
                OnMatch::EvaluateDescendants => new Node(
                    $node->payload,
                    self::pruneLevel($node->children, $predicate, $onMatch, $depth + 1, [...$ancestors, $node->payload]),
                ),
            };
        }

        return $out;
    }

    /**
     * @param list<Node>      $nodes
     * @param OraclePredicate $predicate
     * @param list<mixed>     $ancestors
     *
     * @return list<Node>
     */
    private static function keepAncestorsLevel(array $nodes, \Closure $predicate, OnMatch $onMatch, int $depth, array $ancestors): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $path = [...$ancestors, $node->payload];
            if ($predicate($node->payload, $depth, $ancestors)) {
                $out[] = match ($onMatch) {
                    OnMatch::KeepDescendants => $node,
                    OnMatch::DropDescendants => new Node($node->payload),
                    OnMatch::EvaluateDescendants => new Node(
                        $node->payload,
                        self::keepAncestorsLevel($node->children, $predicate, $onMatch, $depth + 1, $path),
                    ),
                };
                continue;
            }
            $children = self::keepAncestorsLevel($node->children, $predicate, $onMatch, $depth + 1, $path);
            if ($children !== []) {
                $out[] = new Node($node->payload, $children);
            }
        }

        return $out;
    }

    /**
     * @param list<Node>      $nodes
     * @param OraclePredicate $predicate
     * @param list<mixed>     $ancestors
     *
     * @return list<Node>
     */
    private static function promoteLevel(array $nodes, \Closure $predicate, OnMatch $onMatch, int $depth, array $ancestors): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $path = [...$ancestors, $node->payload];
            if ($predicate($node->payload, $depth, $ancestors)) {
                $out[] = match ($onMatch) {
                    OnMatch::KeepDescendants => $node,
                    OnMatch::DropDescendants => new Node($node->payload),
                    OnMatch::EvaluateDescendants => new Node(
                        $node->payload,
                        self::promoteLevel($node->children, $predicate, $onMatch, $depth + 1, $path),
                    ),
                };
                continue;
            }
            foreach (self::promoteLevel($node->children, $predicate, $onMatch, $depth + 1, $path) as $promoted) {
                $out[] = $promoted;
            }
        }

        return $out;
    }

    /**
     * Pre-order search for active nodes. Returns true when the search must stop (first match, not multiple).
     *
     * @param list<Node>                    $nodes
     * @param OraclePredicate               $predicate
     * @param list<mixed>                   $ancestors
     * @param \SplObjectStorage<Node, true> $active
     */
    private static function findActive(array $nodes, \Closure $predicate, int $depth, array $ancestors, bool $multiple, \SplObjectStorage $active): bool
    {
        foreach ($nodes as $node) {
            if ($predicate($node->payload, $depth, $ancestors)) {
                $active[$node] = true;
                if (!$multiple) {
                    return true;
                }
            }
            if (self::findActive($node->children, $predicate, $depth + 1, [...$ancestors, $node->payload], $multiple, $active)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Marks nodes with an active strict descendant. Returns whether any node in $nodes' subtrees is active.
     *
     * @param list<Node>                    $nodes
     * @param \SplObjectStorage<Node, true> $active
     * @param \SplObjectStorage<Node, true> $trail
     */
    private static function markTrail(array $nodes, \SplObjectStorage $active, \SplObjectStorage $trail): bool
    {
        $any = false;
        foreach ($nodes as $node) {
            if (self::markTrail($node->children, $active, $trail)) {
                $trail[$node] = true;
                $any = true;
            }
            if ($active->offsetExists($node)) {
                $any = true;
            }
        }

        return $any;
    }

    /**
     * @param list<Node>                    $nodes
     * @param \SplObjectStorage<Node, true> $active
     * @param \SplObjectStorage<Node, true> $trail
     *
     * @return list<Node>
     */
    private static function trailLevel(
        array $nodes,
        int $depth,
        ?int $parentDistance,
        bool $parentOnTrail,
        \SplObjectStorage $active,
        \SplObjectStorage $trail,
        bool $siblings,
        int $activeChildrenDepth,
    ): array {
        $out = [];
        foreach ($nodes as $node) {
            $distance = $active->offsetExists($node) ? 0 : ($parentDistance === null ? null : $parentDistance + 1);
            $near = $distance !== null && $distance <= $activeChildrenDepth;
            $keep = $siblings
                ? ($depth === 0 || $parentOnTrail || $near)
                : ($trail->offsetExists($node) || $near);
            if (!$keep) {
                continue;
            }
            $out[] = new Node($node->payload, self::trailLevel(
                $node->children,
                $depth + 1,
                $distance,
                $trail->offsetExists($node),
                $active,
                $trail,
                $siblings,
                $activeChildrenDepth,
            ));
        }

        return $out;
    }

    /**
     * @param list<Node>                         $nodes
     * @param OraclePredicate                    $predicate
     * @param \Closure(mixed, MatchState): mixed $combine
     * @param list<mixed>                        $ancestors
     *
     * @return array{0: list<Node>, 1: bool} annotated nodes, whether any node in them matched
     */
    private static function annotateLevel(array $nodes, \Closure $predicate, \Closure $combine, int $depth, array $ancestors, bool $ancestorMatches): array
    {
        $out = [];
        $any = false;
        foreach ($nodes as $node) {
            $matches = $predicate($node->payload, $depth, $ancestors);
            [$children, $descendantMatches] = self::annotateLevel(
                $node->children,
                $predicate,
                $combine,
                $depth + 1,
                [...$ancestors, $node->payload],
                $ancestorMatches || $matches,
            );
            $state = match (true) {
                $matches => MatchState::Match,
                $descendantMatches => MatchState::AncestorOfMatch,
                $ancestorMatches => MatchState::DescendantOfMatch,
                default => MatchState::None,
            };
            $out[] = new Node($combine($node->payload, $state), $children);
            $any = $any || $matches || $descendantMatches;
        }

        return [$out, $any];
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<Node>
     */
    private static function nodesAtDepth(array $nodes, int $depth): array
    {
        if ($depth === 0) {
            return $nodes;
        }
        $out = [];
        foreach ($nodes as $node) {
            foreach (self::nodesAtDepth($node->children, $depth - 1) as $found) {
                $out[] = $found;
            }
        }

        return $out;
    }
}
