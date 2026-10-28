<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Integration;

use Doctrine\ORM\QueryBuilder;
use Ghispi\TreeTransform\Bridge\Gedmo\GedmoTree;
use Ghispi\TreeTransform\Tests\Integration\Entity\ClosureCategory;
use Ghispi\TreeTransform\Tests\Integration\Entity\MpNoSep;
use Ghispi\TreeTransform\Tests\Integration\Entity\MpRoot;
use Ghispi\TreeTransform\Tests\Integration\Entity\NestedCategory;
use Ghispi\TreeTransform\Tests\Integration\Entity\PathCategory;
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
}
