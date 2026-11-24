<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Integration;

use Doctrine\ORM\QueryBuilder;
use Ghispi\TreeTransform\Bridge\Gedmo\GedmoTree;
use Ghispi\TreeTransform\Bridge\Gedmo\Hydration;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Tests\Integration\Entity\ClosureCategory;
use Ghispi\TreeTransform\Tests\Integration\Entity\LatinCategory;
use Ghispi\TreeTransform\Tests\Integration\Entity\MpNoSep;
use Ghispi\TreeTransform\Tests\Integration\Entity\MpRoot;
use Ghispi\TreeTransform\Tests\Integration\Entity\NestedCategory;
use Ghispi\TreeTransform\Tests\Integration\Entity\PathCategory;
use Ghispi\TreeTransform\Tests\Integration\Entity\SlugCategory;
use Ghispi\TreeTransform\Tests\Integration\Entity\TestNode;

/**
 * Regressions for SQL edge cases in the bridge.
 */
final class GedmoEdgeCasesTest extends GedmoTestCase
{
    public function testClosureSubtreeWithModifyKeepsTheSubtreeRoot(): void
    {
        $nodes = $this->persistExample(static fn(string $t, bool $v, ?TestNode $p): TestNode => new ClosureCategory($t, $v, $p instanceof ClosureCategory ? $p : null));

        $tree = (new GedmoTree($this->em))->tree(
            ClosureCategory::class,
            root: $nodes['B']->getId(),
            modify: static fn(QueryBuilder $qb, string $a) => $qb->andWhere("{$a}.title != 'D'"),
        );

        // D is removed in SQL; its child E loses its parent and is dropped, B stays the root.
        self::assertSame('B .C', self::outline($tree->iterate()));
    }

    public function testMaterializedPathSubtreeWithLikeWildcardsInThePath(): void
    {
        $underscore = new PathCategory('a_b');
        $percent = new PathCategory('a%b');
        $this->em->persist($underscore);
        $this->em->persist($percent);
        $this->em->flush();
        $this->em->persist(new PathCategory('kid', true, $underscore));
        $this->em->persist(new PathCategory('axb', true));
        $this->em->flush();
        $this->em->clear();
        $gedmo = new GedmoTree($this->em);

        self::assertSame('a_b .kid', self::outline($gedmo->tree(PathCategory::class, root: $underscore->getId())->iterate()));
        self::assertSame('a%b', self::outline($gedmo->tree(PathCategory::class, root: $percent->getId())->iterate()));
    }

    public function testMaterializedPathWithoutTrailingSeparatorDoesNotMatchIdPrefixes(): void
    {
        $roots = [];
        for ($i = 1; $i <= 10; ++$i) {
            $roots[$i] = new MpNoSep("n{$i}");
            $this->em->persist($roots[$i]);
        }
        $this->em->flush();
        $this->em->persist(new MpNoSep('child', $roots[1]));
        $this->em->flush();
        $this->em->clear();

        $tree = (new GedmoTree($this->em))->tree(MpNoSep::class, root: $roots[1]->id);

        self::assertSame('n1 .child', self::outline($tree->iterate()));
    }

    public function testMaterializedPathRootAssociationIsExposedAsId(): void
    {
        $root = new MpRoot('r');
        $this->em->persist($root);
        $this->em->flush();
        $this->em->persist(new MpRoot('k', $root));
        $this->em->flush();
        $this->em->clear();

        $rows = array_column((new GedmoTree($this->em))->tree(MpRoot::class)->toFlatList(), 'node');

        self::assertSame([$root->id, $root->id], array_map(static fn(mixed $row): mixed => \is_array($row) ? $row['root'] : null, $rows));
    }

    public function testMaxDepthMeansTheSameWithAndWithoutModify(): void
    {
        $this->persistExample(static fn(string $t, bool $v, ?TestNode $p): TestNode => new NestedCategory($t, $v, $p instanceof NestedCategory ? $p : null));
        $tree = (new GedmoTree($this->em))->tree(
            NestedCategory::class,
            maxDepth: 1,
            modify: static fn(QueryBuilder $qb, string $a) => $qb->andWhere("{$a}.title != 'B'"),
        );

        // B removed in SQL: C and D move up to depth 1, so they are within maxDepth 1.
        self::assertSame('A .C .D .F H .I', self::outline($tree->iterate()));
    }

    public function testBrokenNestedSetErrorsNameTheEntityId(): void
    {
        $nodes = $this->persistExample(static fn(string $t, bool $v, ?TestNode $p): TestNode => new NestedCategory($t, $v, $p instanceof NestedCategory ? $p : null));
        // C's interval now reaches past its parent B's.
        $this->em->createQuery(\sprintf('UPDATE %s n SET n.rgt = 100 WHERE n.title = \'C\'', NestedCategory::class))->execute();

        try {
            (new GedmoTree($this->em))->tree(NestedCategory::class)->count();
            self::fail('Expected an InvalidTreeException.');
        } catch (InvalidTreeException $e) {
            self::assertEquals($nodes['C']->getId(), $e->nodeId());
        }
    }

    public function testMaterializedPathSubtreeIsCaseSensitive(): void
    {
        $upper = new SlugCategory('Alpha');
        $lower = new SlugCategory('alpha');
        $this->em->persist($upper);
        $this->em->persist($lower);
        $this->em->flush();
        $this->em->persist(new SlugCategory('wanted', $upper));
        $this->em->persist(new SlugCategory('unrelated', $lower));
        $this->em->flush();
        $this->em->clear();

        $tree = (new GedmoTree($this->em))->tree(SlugCategory::class, root: $upper->id);

        self::assertSame('Alpha .wanted', self::outline($tree->iterate()));
    }

    public function testMaterializedPathSubtreeDoesNotDependOnTheMbstringEncoding(): void
    {
        $root = new SlugCategory('Żółć');
        $this->em->persist($root);
        $this->em->flush();
        $this->em->persist(new SlugCategory('wanted', $root));
        $this->em->flush();
        $this->em->clear();

        $previous = mb_internal_encoding();
        mb_internal_encoding('ISO-8859-1');
        try {
            $tree = (new GedmoTree($this->em))->tree(SlugCategory::class, root: $root->id);

            self::assertSame('Żółć .wanted', self::outline($tree->iterate()));
        } finally {
            mb_internal_encoding($previous);
        }
    }

    public function testNestedSetSubtreeFollowsWritesBetweenReads(): void
    {
        $root = new NestedCategory('A');
        $this->em->persist($root);
        $this->em->flush();
        $gedmo = new GedmoTree($this->em);
        $tree = $gedmo->tree(NestedCategory::class, root: $root->getId());
        self::assertSame(1, $tree->count());

        $this->em->persist(new NestedCategory('B', true, $root));
        $this->em->flush();

        self::assertSame(2, $tree->count());
        self::assertSame(2, $gedmo->tree(NestedCategory::class, root: $root->getId())->count());
    }

    public function testActiveTrailFollowsWritesBetweenReads(): void
    {
        $root = new NestedCategory('A');
        $this->em->persist($root);
        $this->em->flush();
        $gedmo = new GedmoTree($this->em);
        $menu = $gedmo->activeTrail(NestedCategory::class, $root);
        self::assertSame(1, $menu->count());

        $this->em->persist(new NestedCategory('B', true, $root));
        $this->em->flush();

        self::assertSame(2, $menu->count());
    }

    public function testModifyRunsOnceNotOnEveryRead(): void
    {
        $this->persistExample(static fn(string $t, bool $v, ?TestNode $p): TestNode => new NestedCategory($t, $v, $p instanceof NestedCategory ? $p : null));
        $title = 'A';
        $tree = (new GedmoTree($this->em))->tree(
            NestedCategory::class,
            modify: static function (QueryBuilder $qb, string $a) use (&$title): void {
                $qb->andWhere("{$a}.title = :t")->setParameter('t', $title);
            },
        );
        $first = $tree->count();
        self::assertGreaterThan(0, $first);

        $title = 'nothing';

        self::assertSame($first, $tree->count());
    }

    public function testEntitiesOfAnotherCaseVariantBranchAreNotHydrated(): void
    {
        $upper = new SlugCategory('Alpha');
        $lower = new SlugCategory('alpha');
        $this->em->persist($upper);
        $this->em->persist($lower);
        $this->em->flush();
        $this->em->persist(new SlugCategory('wanted', $upper));
        $this->em->persist(new SlugCategory('unrelated', $lower));
        $this->em->flush();
        $this->em->clear();

        $tree = (new GedmoTree($this->em))->tree(SlugCategory::class, root: $upper->id, hydration: Hydration::Entities);

        self::assertCount(2, $tree->toFlatList());
        self::assertSame(2, $this->em->getUnitOfWork()->size());
    }

    public function testPathScopeHonoursModifierOrderingAliasesParametersAndLimits(): void
    {
        $upper = new SlugCategory('Alpha');
        $lower = new SlugCategory('alpha');
        $this->em->persist($upper);
        $this->em->persist($lower);
        $this->em->flush();
        $this->em->persist(new SlugCategory('unrelated', $lower));
        $this->em->persist(new SlugCategory('wanted', $upper));
        $this->em->flush();
        $this->em->clear();
        $gedmo = new GedmoTree($this->em);

        $ordered = $gedmo->tree(
            SlugCategory::class,
            root: $upper->id,
            hydration: Hydration::Entities,
            modify: static fn(QueryBuilder $qb, string $a) => $qb
                ->addSelect("CASE WHEN {$a}.title = :preferred THEN 0 ELSE 1 END AS HIDDEN priority")
                ->setParameter('preferred', 'wanted')
                ->addOrderBy('priority'),
        );
        self::assertCount(2, $ordered->toFlatList());

        $this->em->clear();
        $limited = $gedmo->tree(
            SlugCategory::class,
            root: $upper->id,
            hydration: Hydration::Entities,
            modify: static fn(QueryBuilder $qb, string $a) => $qb->orderBy("{$a}.id")->setMaxResults(2),
        );
        self::assertSame(['Alpha', 'wanted'], array_map(static fn(array $entry): mixed => $entry['node'] instanceof SlugCategory ? $entry['node']->title : null, $limited->toFlatList()));
        self::assertSame(2, $this->em->getUnitOfWork()->size());

        $this->em->clear();
        $arrays = $gedmo->tree(
            SlugCategory::class,
            root: $upper->id,
            modify: static fn(QueryBuilder $qb, string $a) => $qb->orderBy("{$a}.id")->setMaxResults(2),
        );
        self::assertSame('Alpha .wanted', self::outline($arrays->iterate()));
    }

    public function testMaterializedPathSubtreeOnAColumnWithAnotherCharacterSetThanTheConnection(): void
    {
        $root = new LatinCategory('Café');
        $this->em->persist($root);
        $this->em->flush();
        $this->em->persist(new LatinCategory('wanted', $root));
        $this->em->flush();
        $this->em->clear();

        $tree = (new GedmoTree($this->em))->tree(LatinCategory::class, root: $root->id);

        self::assertSame('Café .wanted', self::outline($tree->iterate()));
    }

    public function testAConflictingDqlFunctionRegistrationIsReported(): void
    {
        $this->em->getConfiguration()->addCustomStringFunction('TREE_TRANSFORM_BINARY', \Doctrine\ORM\Query\AST\Functions\LowerFunction::class);
        $root = new SlugCategory('Alpha');
        $this->em->persist($root);
        $this->em->flush();

        $this->expectException(\Ghispi\TreeTransform\Exception\LogicException::class);
        (new GedmoTree($this->em))->tree(SlugCategory::class, root: $root->id)->count();
    }
}
