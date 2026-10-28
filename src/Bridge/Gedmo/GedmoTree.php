<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Bridge\Gedmo;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Gedmo\Tree\TreeListener;
use Ghispi\TreeTransform\Accessor\ArrayKeyAccessor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Exception\LogicException;
use Ghispi\TreeTransform\Source\AdjacencyListFields;
use Ghispi\TreeTransform\Source\AdjacencyListSource;
use Ghispi\TreeTransform\Source\LazySource;
use Ghispi\TreeTransform\Source\MaterializedPathFields;
use Ghispi\TreeTransform\Source\MaterializedPathSource;
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Source\NestedSetSource;
use Ghispi\TreeTransform\Source\OrphanPolicy;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Tree;

/**
 * Loads Gedmo trees (nested set, closure, materialized path) from Doctrine ORM as a Tree, with correct ordering,
 * streaming queries and structural pushdown (subtree, max depth, active trail).
 *
 *     $gedmo = new GedmoTree($entityManager);
 *     $menu = $gedmo->tree(Category::class)
 *         ->filter(fn (array $c) => $c['published'])
 *         ->toNestedArray();
 *
 * Rows are streamed with Query::toIterable(); with Hydration::Arrays (default) each node is an array of the
 * entity's fields, with the parent and root associations replaced by their ids.
 */
final class GedmoTree
{
    private const string ALIAS = 'node';

    private readonly TreeListener $listener;

    /** @var array<class-string, TreeConfig> */
    private array $configs = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        ?TreeListener $listener = null,
    ) {
        $this->listener = $listener ?? self::findListener($em);
    }

    /**
     * The whole tree, or the subtree of $root (included as the only root), optionally limited to $maxDepth levels
     * below it.
     *
     * @param class-string                                $class
     * @param object|int|string|null                      $root   entity or id of the subtree root
     * @param \Closure|null                                $modify adds conditions/joins to the query; the second
     *                                                            argument is the node alias. Note: rows removed by
     *                                                            SQL disappear before the tree is built — for nested
     *                                                            sets and materialized paths their children move up
     *                                                            (like Promote), for closure trees they become
     *                                                            orphans and are dropped.
     */
    public function tree(
        string $class,
        object|int|string|null $root = null,
        ?int $maxDepth = null,
        Hydration $hydration = Hydration::Arrays,
        ?\Closure $modify = null,
    ): Tree {
        if ($maxDepth !== null && $maxDepth < 0) {
            throw new InvalidArgumentException('Maximum depth cannot be negative.');
        }
        $config = $this->config($class);
        $qb = $this->select($config);
        $anchor = $root === null ? null : $this->anchor($config, $root);

        $orphans = OrphanPolicy::Throw;
        $rootParent = null;
        switch ($config->strategy) {
            case TreeConfig::NESTED:
                if ($anchor !== null) {
                    $qb->andWhere(\sprintf('%1$s.%2$s >= :tt_l AND %1$s.%3$s <= :tt_r', self::ALIAS, $config->left, $config->right))
                        ->setParameter('tt_l', $anchor['left'])
                        ->setParameter('tt_r', $anchor['right']);
                    $this->sameRoot($qb, $config, $anchor);
                }
                break;
            case TreeConfig::MATERIALIZED_PATH:
                if ($anchor !== null) {
                    // Descendants start with the anchor's path followed by the separator; "!" escapes LIKE wildcards
                    // portably (no reliance on a database's default escape character).
                    $prefix = $anchor['path'];
                    if (!$config->pathEndsWithSeparator) {
                        $prefix .= $config->pathSeparator;
                    }
                    $qb->andWhere(\sprintf("(%1\$s.%2\$s = :tt_exact OR %1\$s.%2\$s LIKE :tt_path ESCAPE '!')", self::ALIAS, $config->path))
                        ->setParameter('tt_exact', $anchor['path'])
                        ->setParameter('tt_path', str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix) . '%');
                }
                break;
            case TreeConfig::CLOSURE:
                if ($anchor !== null) {
                    $qb->innerJoin($config->closureClass ?? '', 'tt_c', 'WITH', \sprintf('tt_c.descendant = %s', self::ALIAS))
                        ->andWhere('tt_c.ancestor = :tt_anchor')
                        ->setParameter('tt_anchor', $anchor['id']);
                    if ($maxDepth !== null) {
                        $qb->andWhere('tt_c.depth <= :tt_depth')->setParameter('tt_depth', $maxDepth);
                    }
                    // The subtree root's parent is outside the result: its parent value marks the root.
                    $rootParent = $anchor['parent'];
                }
                break;
        }
        // With $modify, removed rows shift depths, so depth is only applied in PHP to keep one meaning of maxDepth.
        if ($maxDepth !== null && $modify === null && $config->level !== null && $config->strategy !== TreeConfig::CLOSURE) {
            $qb->andWhere(\sprintf('%s.%s <= :tt_level', self::ALIAS, $config->level))
                ->setParameter('tt_level', ($anchor['level'] ?? $this->minLevel($config)) + $maxDepth);
        }
        if ($modify !== null) {
            $modify($qb, self::ALIAS);
            if ($config->strategy === TreeConfig::CLOSURE) {
                $orphans = OrphanPolicy::Drop;
            }
        }

        $tree = Tree::from($this->source($config, $qb, $hydration, $orphans, $modify !== null, $rootParent))->map(self::unwrap($config, $hydration));

        return $maxDepth === null ? $tree : $tree->maxDepth($maxDepth);
    }

    /**
     * A navigation menu expanded down to $active (see ActiveTrail). For nested sets only the needed rows are loaded:
     * roots, the active node's ancestors, their children and the active node's children.
     *
     * @param class-string      $class
     * @param object|int|string $active entity or id of the active node
     */
    public function activeTrail(
        string $class,
        object|int|string $active,
        bool $siblings = true,
        int $activeChildrenDepth = 1,
        Hydration $hydration = Hydration::Arrays,
    ): Tree {
        $config = $this->config($class);
        $anchor = $this->anchor($config, $active);
        $activeId = $anchor['id'];
        $isActive = fn(mixed $node): bool => (string) $this->idOf($node, $config) === (string) $activeId;

        if ($config->strategy !== TreeConfig::NESTED) {
            return $this->tree($class, hydration: $hydration)
                ->activeTrail($isActive, $siblings, $activeChildrenDepth, multiple: false);
        }

        $a = self::ALIAS;
        $qb = $this->select($config);
        $ancestorsOrSelf = \sprintf('%1$s.%2$s <= :tt_l AND %1$s.%3$s >= :tt_r', '%1$s', $config->left, $config->right);
        $conditions = [\sprintf($ancestorsOrSelf, $a)];
        if ($config->root !== null) {
            $conditions[0] .= \sprintf(' AND %s = :tt_root', $this->rootExpression($config, $a));
        }
        if ($siblings && $config->parent !== null) {
            $sub = $this->em->createQueryBuilder()
                ->select(\sprintf('tt_a.%s', $config->id))
                ->from($config->class, 'tt_a')
                ->where(\sprintf($ancestorsOrSelf, 'tt_a'));
            if ($config->root !== null) {
                $sub->andWhere(\sprintf('%s = :tt_root', $this->rootExpression($config, 'tt_a')));
            }
            $conditions[] = \sprintf('IDENTITY(%s.%s) IN (%s)', $a, $config->parent, $sub->getDQL());
            $conditions[] = \sprintf('%s.%s IS NULL', $a, $config->parent);
        }
        if ($activeChildrenDepth > 0) {
            $below = \sprintf('%1$s.%2$s > :tt_l AND %1$s.%3$s < :tt_r', $a, $config->left, $config->right);
            if ($config->root !== null) {
                $below .= \sprintf(' AND %s = :tt_root', $this->rootExpression($config, $a));
            }
            if ($config->level !== null && $activeChildrenDepth !== \PHP_INT_MAX) {
                $below .= \sprintf(' AND %s.%s <= :tt_level', $a, $config->level);
                $qb->setParameter('tt_level', $anchor['level'] + $activeChildrenDepth);
            }
            $conditions[] = $below;
        }
        $qb->andWhere('(' . implode(') OR (', $conditions) . ')')
            ->setParameter('tt_l', $anchor['left'])
            ->setParameter('tt_r', $anchor['right']);
        if ($config->root !== null) {
            $qb->setParameter('tt_root', $anchor['root']);
        }

        return Tree::from($this->source($config, $qb, $hydration, OrphanPolicy::Throw))
            ->map(self::unwrap($config, $hydration))
            ->activeTrail($isActive, $siblings, $activeChildrenDepth, multiple: false);
    }

    /**
     * The Gedmo/Doctrine tree configuration of a class.
     *
     * @param class-string $class
     */
    public function config(string $class): TreeConfig
    {
        return $this->configs[$class] ??= TreeConfig::read($this->em, $this->listener, $class);
    }

    private function select(TreeConfig $config): QueryBuilder
    {
        $a = self::ALIAS;
        $qb = $this->em->createQueryBuilder()
            ->select($a)
            ->addSelect(\sprintf('%s.%s AS tt_id', $a, $config->id))
            ->from($config->class, $a);
        if ($config->parent !== null) {
            $qb->addSelect(\sprintf('IDENTITY(%s.%s) AS tt_parent', $a, $config->parent));
        }
        if ($config->root !== null) {
            $qb->addSelect(\sprintf('%s AS tt_root', $this->rootExpression($config, $a)));
        }

        switch ($config->strategy) {
            case TreeConfig::NESTED:
                if ($config->left === null || $config->right === null) {
                    throw new LogicException(\sprintf('%s: nested set mapping without left/right fields.', $config->class));
                }
                $qb->addSelect(\sprintf('%s.%s AS tt_left, %1$s.%3$s AS tt_right', $a, $config->left, $config->right));
                if ($config->root !== null) {
                    $qb->addOrderBy('tt_root');
                }
                $qb->addOrderBy(\sprintf('%s.%s', $a, $config->left));
                break;
            case TreeConfig::MATERIALIZED_PATH:
                if ($config->path === null) {
                    throw new LogicException(\sprintf('%s: materialized path mapping without path field.', $config->class));
                }
                $qb->addSelect(\sprintf('%s.%s AS tt_path', $a, $config->path))->addOrderBy(\sprintf('%s.%s', $a, $config->path));
                break;
            case TreeConfig::CLOSURE:
                if ($config->parent === null) {
                    throw new LogicException(\sprintf('%s: closure mapping without parent field.', $config->class));
                }
                $qb->addOrderBy(\sprintf('%s.%s', $a, $config->id));
                break;
            default:
                throw new LogicException(\sprintf('Unsupported Gedmo tree strategy "%s".', $config->strategy));
        }

        return $qb;
    }

    /**
     * The query runs each time the tree is read, so one Tree can produce several outputs.
     */
    private function source(TreeConfig $config, QueryBuilder $qb, Hydration $hydration, OrphanPolicy $orphans, bool $allowGaps = false, int|string|null $rootParent = null): Source
    {
        $query = $qb->getQuery();

        return new LazySource(fn(): Source => $this->sourceFor($config, $query->toIterable(
            [],
            $hydration === Hydration::Arrays ? AbstractQuery::HYDRATE_ARRAY : AbstractQuery::HYDRATE_OBJECT,
        ), $orphans, $allowGaps, $rootParent));
    }

    /**
     * @param iterable<mixed> $rows
     */
    private function sourceFor(TreeConfig $config, iterable $rows, OrphanPolicy $orphans, bool $allowGaps, int|string|null $rootParent): Source
    {
        $accessor = new ArrayKeyAccessor();

        return match ($config->strategy) {
            TreeConfig::NESTED => new NestedSetSource(
                $rows,
                new NestedSetFields('tt_left', 'tt_right', $config->root === null ? null : 'tt_root'),
                $accessor,
            ),
            TreeConfig::MATERIALIZED_PATH => new MaterializedPathSource(
                $rows,
                new MaterializedPathFields('tt_path', $config->pathSeparator),
                $accessor,
                allowGaps: $allowGaps,
            ),
            default => new AdjacencyListSource(
                $rows,
                new AdjacencyListFields('tt_id', 'tt_parent'),
                $accessor,
                $orphans,
                $rootParent,
            ),
        };
    }

    /**
     * Turns a query row ([0 => node, 'tt_…' => scalars]) into the node users see.
     *
     * @return \Closure(mixed, int): mixed
     */
    private static function unwrap(TreeConfig $config, Hydration $hydration): \Closure
    {
        if ($hydration === Hydration::Entities) {
            return static fn(mixed $row): mixed => \is_array($row) ? $row[0] : $row;
        }
        $parent = $config->parent;
        $root = $config->rootIsAssociation ? $config->root : null;

        return static function (mixed $row) use ($parent, $root): mixed {
            if (!\is_array($row) || !\is_array($row[0] ?? null)) {
                return $row;
            }
            $node = $row[0];
            if ($parent !== null) {
                $node[$parent] = $row['tt_parent'] ?? null;
            }
            if ($root !== null) {
                $node[$root] = $row['tt_root'] ?? null;
            }

            return $node;
        };
    }

    private function idOf(mixed $node, TreeConfig $config): int|string
    {
        $id = match (true) {
            \is_array($node) => $node[$config->id] ?? '',
            \is_object($node) => $this->em->getUnitOfWork()->getSingleIdentifierValue($node),
            default => '',
        };

        return \is_int($id) || \is_string($id) ? $id : '';
    }

    /**
     * Reads the structural values of one node (entity or id).
     *
     * @return array{id: int|string, left: int, right: int, level: int, root: mixed, path: string, parent: int|string|null}
     */
    private function anchor(TreeConfig $config, object|int|string $node): array
    {
        $a = self::ALIAS;
        $qb = $this->em->createQueryBuilder()
            ->select(\sprintf('%s.%s AS id', $a, $config->id))
            ->from($config->class, $a)
            ->where(\sprintf('%s.%s = :tt_id', $a, $config->id))
            ->setParameter('tt_id', \is_object($node) ? $this->em->getUnitOfWork()->getSingleIdentifierValue($node) : $node);
        if ($config->left !== null && $config->right !== null) {
            $qb->addSelect(\sprintf('%s.%s AS lft, %1$s.%3$s AS rgt', $a, $config->left, $config->right));
        }
        if ($config->level !== null) {
            $qb->addSelect(\sprintf('%s.%s AS lvl', $a, $config->level));
        }
        if ($config->root !== null) {
            $qb->addSelect(\sprintf('%s AS root', $this->rootExpression($config, $a)));
        }
        if ($config->path !== null) {
            $qb->addSelect(\sprintf('%s.%s AS path', $a, $config->path));
        }
        if ($config->parent !== null) {
            $qb->addSelect(\sprintf('IDENTITY(%s.%s) AS parent', $a, $config->parent));
        }
        /** @var list<array<string, mixed>> $result */
        $result = $qb->getQuery()->getArrayResult();
        if ($result === []) {
            throw new InvalidArgumentException(\sprintf('%s node not found.', $config->class));
        }
        $row = $result[0];
        $int = static fn(mixed $v): int => \is_int($v) || (\is_string($v) && is_numeric($v)) ? (int) $v : 0;

        $id = $row['id'] ?? null;
        if (!\is_int($id) && !\is_string($id)) {
            throw new LogicException(\sprintf('%s: unsupported identifier type %s.', $config->class, get_debug_type($id)));
        }

        return [
            'id' => $id,
            'left' => $int($row['lft'] ?? 0),
            'right' => $int($row['rgt'] ?? 0),
            'level' => $int($row['lvl'] ?? 0),
            'root' => $row['root'] ?? null,
            'path' => \is_string($row['path'] ?? null) ? $row['path'] : '',
            'parent' => \is_int($row['parent'] ?? null) || \is_string($row['parent'] ?? null) ? $row['parent'] : null,
        ];
    }

    private function minLevel(TreeConfig $config): int
    {
        $value = $this->em->createQueryBuilder()
            ->select(\sprintf('MIN(%s.%s)', self::ALIAS, $config->level))
            ->from($config->class, self::ALIAS)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array{root: mixed} $anchor
     */
    private function sameRoot(QueryBuilder $qb, TreeConfig $config, array $anchor): void
    {
        if ($config->root !== null) {
            $qb->andWhere(\sprintf('%s = :tt_root', $this->rootExpression($config, self::ALIAS)))
                ->setParameter('tt_root', $anchor['root']);
        }
    }

    private function rootExpression(TreeConfig $config, string $alias): string
    {
        return $config->rootIsAssociation
            ? \sprintf('IDENTITY(%s.%s)', $alias, $config->root)
            : \sprintf('%s.%s', $alias, $config->root);
    }

    private static function findListener(EntityManagerInterface $em): TreeListener
    {
        foreach ($em->getEventManager()->getAllListeners() as $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof TreeListener) {
                    return $listener;
                }
            }
        }

        throw new LogicException('No Gedmo TreeListener is registered with this EntityManager; pass it to GedmoTree explicitly.');
    }
}
