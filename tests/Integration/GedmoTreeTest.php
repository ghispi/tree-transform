<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Integration;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use Ghispi\TreeTransform\Bridge\Gedmo\GedmoTree;
use Ghispi\TreeTransform\Bridge\Gedmo\Hydration;
use Ghispi\TreeTransform\Bridge\Gedmo\TreeConfig;
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Source\NestedSetSource;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Tests\Integration\Entity\ClosureCategory;
use Ghispi\TreeTransform\Tests\Integration\Entity\NestedCategory;
use Ghispi\TreeTransform\Tests\Integration\Entity\PathCategory;
use Ghispi\TreeTransform\Tests\Integration\Entity\TestNode;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\DataProvider;

final class GedmoTreeTest extends GedmoTestCase
{
    private const string FULL = 'A .B ..C ..D ...E .F ..G H .I';

    /**
     * @return iterable<string, array{class-string, \Closure(string, bool, ?TestNode): TestNode, string}>
     */
    public static function strategies(): iterable
    {
        yield 'nested set' => [NestedCategory::class, static fn(string $t, bool $v, ?TestNode $p): TestNode => new NestedCategory($t, $v, $p instanceof NestedCategory ? $p : null), TreeConfig::NESTED];
        yield 'materialized path' => [PathCategory::class, static fn(string $t, bool $v, ?TestNode $p): TestNode => new PathCategory($t, $v, $p instanceof PathCategory ? $p : null), TreeConfig::MATERIALIZED_PATH];
        yield 'closure' => [ClosureCategory::class, static fn(string $t, bool $v, ?TestNode $p): TestNode => new ClosureCategory($t, $v, $p instanceof ClosureCategory ? $p : null), TreeConfig::CLOSURE];
    }

    /**
     * @param class-string                                  $class
     * @param \Closure(string, bool, ?TestNode): TestNode       $make
     */
    #[DataProvider('strategies')]
    public function testReadsConfiguration(string $class, \Closure $make, string $strategy): void
    {
        $config = (new GedmoTree($this->em))->config($class);

        self::assertSame($strategy, $config->strategy);
        self::assertSame('id', $config->id);
        self::assertSame('parent', $config->parent);
    }

    /**
     * @param class-string                            $class
     * @param \Closure(string, bool, ?TestNode): TestNode $make
     */
    #[DataProvider('strategies')]
    public function testLoadsWholeTreeAsArrays(string $class, \Closure $make, string $strategy): void
    {
        $nodes = $this->persistExample($make);
        $tree = (new GedmoTree($this->em))->tree($class);

        self::assertSame(self::FULL, self::outline($tree->iterate()));
        $first = $tree->toFlatList()[1]['node'];
        self::assertIsArray($first);
        self::assertSame('B', $first['title']);
        self::assertSame($nodes['A']->getId(), $first['parent'], 'parent association is exposed as id');
    }

    /**
     * @param class-string                            $class
     * @param \Closure(string, bool, ?TestNode): TestNode $make
     */
    #[DataProvider('strategies')]
    public function testLoadsEntities(string $class, \Closure $make, string $strategy): void
    {
        $this->persistExample($make);
        $tree = (new GedmoTree($this->em))->tree($class, hydration: Hydration::Entities);

        self::assertSame(self::FULL, self::outline($tree->iterate()));
        foreach ($tree->iterate() as $node) {
            self::assertInstanceOf($class, $node);
        }
    }

    /**
     * @param class-string                            $class
     * @param \Closure(string, bool, ?TestNode): TestNode $make
     */
    #[DataProvider('strategies')]
    public function testFiltersWithStrategies(string $class, \Closure $make, string $strategy): void
    {
        $this->persistExample($make);
        $tree = (new GedmoTree($this->em))->tree($class);
        $visible = static fn(array $node): bool => $node['visible'] === true;

        self::assertSame('A', self::outline($tree->filter($visible)->iterate()));
        self::assertSame('A .B ..C ..D ...E H .I', self::outline($tree->filter($visible, new KeepAncestors())->iterate()));
        self::assertSame('A .C .E I', self::outline($tree->filter($visible, new Promote())->iterate()));
    }

    /**
     * @param class-string                            $class
     * @param \Closure(string, bool, ?TestNode): TestNode $make
     */
    #[DataProvider('strategies')]
    public function testLoadsSubtreeWithMaxDepth(string $class, \Closure $make, string $strategy): void
    {
        $nodes = $this->persistExample($make);
        $gedmo = new GedmoTree($this->em);

        self::assertSame('B .C .D ..E', self::outline($gedmo->tree($class, $nodes['B']->getId())->iterate()));
        self::assertSame('B .C .D', self::outline($gedmo->tree($class, $nodes['B']->getId(), maxDepth: 1)->iterate()));
        self::assertSame('A .B .F H .I', self::outline($gedmo->tree($class, maxDepth: 1)->iterate()));
    }

    /**
     * @param class-string                            $class
     * @param \Closure(string, bool, ?TestNode): TestNode $make
     */
    #[DataProvider('strategies')]
    public function testActiveTrail(string $class, \Closure $make, string $strategy): void
    {
        $nodes = $this->persistExample($make);
        $gedmo = new GedmoTree($this->em);
        $e = $nodes['E']->getId();
        \assert($e !== null);

        self::assertSame('A .B ..C ..D ...E .F H', self::outline($gedmo->activeTrail($class, $e)->iterate()));
        self::assertSame('A .B ..D ...E', self::outline($gedmo->activeTrail($class, $e, siblings: false)->iterate()));
        $b = $nodes['B']->getId();
        \assert($b !== null);
        self::assertSame('A .B ..C ..D ...E .F H', self::outline($gedmo->activeTrail($class, $b, activeChildrenDepth: 2)->iterate()));
        self::assertSame('A .B ..C ..D .F H', self::outline($gedmo->activeTrail($class, $b, hydration: Hydration::Entities)->iterate()));
    }

    public function testActiveTrailLoadsOnlyNeededNestedSetRows(): void
    {
        $nodes = $this->persistExample(static fn(string $t, bool $v, ?TestNode $p): TestNode => new NestedCategory($t, $v, $p instanceof NestedCategory ? $p : null));
        $e = $nodes['E']->getId();
        \assert($e !== null);
        // Without the strategy (raw rows): G and I are never loaded.
        $loaded = [];
        foreach ((new GedmoTree($this->em))->activeTrail(NestedCategory::class, $e)->iterate() as $node) {
            \assert(\is_array($node));
            $loaded[] = $node['title'];
        }
        self::assertNotContains('G', $loaded);
        self::assertNotContains('I', $loaded);
    }

    public function testModifyQuery(): void
    {
        $this->persistExample(static fn(string $t, bool $v, ?TestNode $p): TestNode => new NestedCategory($t, $v, $p instanceof NestedCategory ? $p : null));
        $tree = (new GedmoTree($this->em))->tree(
            NestedCategory::class,
            modify: static fn(QueryBuilder $qb, string $alias) => $qb->andWhere("{$alias}.title != 'B'"),
        );

        // Nested set rows removed in SQL: their children move up.
        self::assertSame('A .C .D ..E .F ..G H .I', self::outline($tree->iterate()));
    }

    public function testManualQueryFromDocs(): void
    {
        $this->persistExample(static fn(string $t, bool $v, ?TestNode $p): TestNode => new NestedCategory($t, $v, $p instanceof NestedCategory ? $p : null));
        $query = $this->em->createQuery(
            'SELECT c.id, c.title, c.lft, c.rgt, IDENTITY(c.root) AS root
             FROM ' . NestedCategory::class . ' c ORDER BY root, c.lft',
        );
        $tree = Tree::lazy(static fn() => new NestedSetSource($query->toIterable([], AbstractQuery::HYDRATE_ARRAY), NestedSetFields::gedmo()));

        self::assertSame(self::FULL, self::outline($tree->iterate()));
        self::assertSame(9, $tree->count(), 'a lazy tree can be read twice');
    }
}
