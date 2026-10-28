<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Differential;

use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\GeneratedTree;
use Ghispi\TreeTransform\Tests\Fixtures\Matrix;
use Ghispi\TreeTransform\Tests\Fixtures\Oracle\Node;
use Ghispi\TreeTransform\Tests\Fixtures\Oracle\Oracle;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §11 (3): any source -> sink -> back in preserves the structure, and NestedSetSink output is a valid nested
 * set — also after Promote, which changes depths and parents.
 */
final class RoundTripTest extends TestCase
{
    /**
     * @return iterable<string, array{0: GeneratedTree}>
     */
    public static function trees(): iterable
    {
        yield from Matrix::trees();
    }

    #[DataProvider('trees')]
    public function testEverySinkRoundTripsEverySource(GeneratedTree $tree): void
    {
        $expected = $tree->signature();
        foreach (Matrix::sources($tree) as $name => $source) {
            $context = "source \"{$name}\", {$tree->describe()}";

            $nestedSet = Tree::from($source())->toNestedSet();
            self::assertSame([], Trees::nestedSetProblems($nestedSet), "toNestedSet valid, {$context}");
            self::assertSame($expected, Trees::signature(Trees::pairs(Tree::fromNestedSet($nestedSet))), "nested set, {$context}");

            $perRoot = Tree::from($source())->toNestedSet(restartPerRoot: true);
            self::assertSame([], Trees::nestedSetProblems($perRoot, perRoot: true), "toNestedSet per root valid, {$context}");
            self::assertSame(
                $expected,
                Trees::signature(Trees::pairs(Tree::fromNestedSet($perRoot, new NestedSetFields(root: 'root')))),
                "nested set per root, {$context}",
            );

            $adjacency = Tree::from($source())->toAdjacencyList();
            self::assertSame($expected, Trees::signature(Trees::pairs(Tree::fromAdjacencyList($adjacency))), "adjacency, {$context}");
            self::assertSame(
                $expected,
                Trees::signature(Trees::pairs(Tree::fromAdjacencyList(array_reverse($adjacency), siblingOrder: 'rank'))),
                "adjacency reversed, {$context}",
            );
            self::assertSame($expected, Trees::signature(Trees::pairs(Tree::fromPreorderedAdjacencyList($adjacency))), "pre-ordered adjacency, {$context}");

            $nested = Tree::from($source())->toNestedArray(childrenKey: 'kids');
            self::assertSame($expected, Trees::signature(Trees::pairs(Tree::fromNestedArray($nested, 'kids'))), "nested array, {$context}");

            $depthList = Tree::fromDepthList(Tree::from($source())->iterate());
            self::assertSame($expected, Trees::signature(Trees::pairs($depthList)), "iterate -> fromDepthList, {$context}");
        }
    }

    #[DataProvider('trees')]
    public function testPromotedTreeRoundTripsWithRewrittenParentsAndNestedSetValues(GeneratedTree $tree): void
    {
        $predicate = Matrix::predicates()['dense'];
        $expected = Trees::signature(Node::flatten(Oracle::promote($tree->oracleForest(), $predicate)));
        $promoted = Tree::from(Matrix::sources($tree)['materialized path']())->filter(Matrix::library($predicate), new Promote());

        $adjacency = $promoted->toAdjacencyList();
        self::assertSame($expected, Trees::signature(Trees::pairs(Tree::fromAdjacencyList(array_reverse($adjacency), siblingOrder: 'rank'))), $tree->describe());

        $nestedSet = $promoted->toNestedSet();
        self::assertSame([], Trees::nestedSetProblems($nestedSet), $tree->describe());
        self::assertSame($expected, Trees::signature(Trees::pairs(Tree::fromNestedSet($nestedSet))), $tree->describe());

        // Per-root numbering needs the new root of each node: tag it while streaming.
        $root = null;
        $perRoot = $promoted
            ->map(static function (mixed $n, int $depth) use (&$root): array {
                if ($depth === 0) {
                    $root = Field::id($n);
                }

                return ['id' => Field::id($n), 'w' => Field::int($n, 'w'), 'root' => $root];
            })
            ->toNestedSet(levelField: 'level', levelBase: 1, restartPerRoot: true);
        self::assertSame([], Trees::nestedSetProblems($perRoot, true, level: 'level', levelBase: 1), $tree->describe());
        self::assertSame($expected, Trees::signature(Trees::pairs(Tree::fromNestedSet($perRoot, NestedSetFields::gedmo()))), $tree->describe());
    }
}
