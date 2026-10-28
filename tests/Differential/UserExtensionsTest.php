<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Differential;

use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Stage\DepthWindow;
use Ghispi\TreeTransform\Stage\Filter;
use Ghispi\TreeTransform\Stage\Limit;
use Ghispi\TreeTransform\Stage\MaxDepth;
use Ghispi\TreeTransform\Stage\Stage;
use Ghispi\TreeTransform\Strategy\FilterStrategy;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Strategy\Prune;
use Ghispi\TreeTransform\Tests\Fixtures\GeneratedTree;
use Ghispi\TreeTransform\Tests\Fixtures\Matrix;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tests\Fixtures\User\DelegatingPrune;
use Ghispi\TreeTransform\Tests\Fixtures\User\ListingSink;
use Ghispi\TreeTransform\Tests\Fixtures\User\PassThroughStage;
use Ghispi\TreeTransform\Tests\Fixtures\User\SpyingSource;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * User-written sources, stages, strategies and sinks (cursor based, not part of the internal generator pipeline)
 * mixed with built-ins give exactly the all-built-in result, and the skips of downstream stages still reach the
 * source: the source is read and skipped exactly as often as without the extensions.
 */
#[CoversNothing]
final class UserExtensionsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: GeneratedTree}>
     */
    public static function trees(): iterable
    {
        yield from Matrix::trees(15);
    }

    /**
     * Pipelines as stage factories; $user swaps in user-written equivalents (a pass-through stage between the
     * built-in ones, and a strategy implemented outside the library for Prune).
     *
     * @return array<string, \Closure(bool, \ArrayObject<int, PassThroughStage>): list<Stage>>
     */
    private static function pipelines(): array
    {
        $parent = Matrix::library(Matrix::predicates()['parent']);
        $sparse = Matrix::library(Matrix::predicates()['sparse']);
        $dense = Matrix::library(Matrix::predicates()['dense']);

        $strategy = static fn(bool $user): FilterStrategy => $user ? new DelegatingPrune() : new Prune();
        return [
            'Prune(parent) -> MaxDepth(2)' => static function (bool $user, \ArrayObject $passes) use ($parent, $strategy): array {
                return [new Filter($parent, $strategy($user)), ...self::between($user, $passes), new MaxDepth(2)];
            },
            'KeepAncestors(sparse) -> pass -> Limit(7) -> pass -> MaxDepth(1)' => static function (bool $user, \ArrayObject $passes) use ($sparse): array {
                return [new Filter($sparse, new KeepAncestors()), ...self::between($user, $passes), new Limit(7), ...self::between($user, $passes), new MaxDepth(1)];
            },
            'pass -> Promote(dense) -> DepthWindow(1, 3) -> pass -> Prune(parent)' => static function (bool $user, \ArrayObject $passes) use ($dense, $parent, $strategy): array {
                return [...self::between($user, $passes), new Filter($dense, new Promote()), new DepthWindow(1, 3), ...self::between($user, $passes), new Filter($parent, $strategy($user))];
            },
        ];
    }

    /**
     * @param \ArrayObject<int, PassThroughStage> $passes collects the user stages that were created
     *
     * @return list<PassThroughStage>
     */
    private static function between(bool $user, \ArrayObject $passes): array
    {
        if (!$user) {
            return [];
        }
        $pass = new PassThroughStage();
        $passes[] = $pass;

        return [$pass];
    }

    /**
     * @param list<Stage> $stages
     */
    private static function tree(Source $source, array $stages): Tree
    {
        $tree = Tree::from($source);
        foreach ($stages as $stage) {
            $tree = $tree->pipe($stage);
        }

        return $tree;
    }

    /**
     * @return list<string>
     */
    private static function signature(Tree $tree): array
    {
        return Trees::signature(Trees::pairs($tree));
    }

    #[DataProvider('trees')]
    public function testUserExtensionsGiveTheBuiltInResultAndTheSameSourceTraffic(GeneratedTree $tree): void
    {
        $sources = Matrix::sources($tree);
        foreach (self::pipelines() as $name => $pipeline) {
            foreach (['nested set', 'adjacency shuffled', 'materialized path', 'nested array', 'depth list'] as $sourceName) {
                $where = "{$name} on \"{$sourceName}\", {$tree->describe()}";

                $plain = new \ArrayObject();
                $builtInSource = new SpyingSource($sources[$sourceName]());
                $expected = self::signature(self::tree($builtInSource, $pipeline(false, $plain)));
                $expectedCount = self::tree(new SpyingSource($sources[$sourceName]()), $pipeline(false, $plain))->count();

                $passes = new \ArrayObject();
                $mixedSource = new SpyingSource($sources[$sourceName]());
                $actual = self::signature(self::tree($mixedSource, $pipeline(true, $passes)));

                self::assertSame($expected, $actual, "stages: {$where}");
                self::assertCount($expectedCount, $expected, "count: {$where}");
                self::assertNotNull($builtInSource->spy);
                self::assertNotNull($mixedSource->spy);
                self::assertSame($builtInSource->spy->visited, $mixedSource->spy->visited, "source reads: {$where}");
                self::assertSame($builtInSource->spy->skipped, $mixedSource->spy->skipped, "source skips: {$where}");

                // user sink, all-user pipeline
                $passes = new \ArrayObject();
                $userPairs = self::tree(new SpyingSource($sources[$sourceName]()), $pipeline(true, $passes))->into(new ListingSink());
                self::assertSame($expected, Trees::signature($userPairs), "user sink: {$where}");

                // iterate() over the mixed pipeline
                $passes = new \ArrayObject();
                $iterated = [];
                foreach (self::tree(new SpyingSource($sources[$sourceName]()), $pipeline(true, $passes))->iterate() as $depth => $node) {
                    $iterated[] = [$depth, $node];
                }
                self::assertSame($expected, Trees::signature($iterated), "iterate(): {$where}");

                foreach ($passes as $pass) {
                    self::assertNotNull($pass->spy, "user stage was applied: {$where}");
                }
            }
        }
    }

    #[DataProvider('trees')]
    public function testUserSinkSkippingSubtreesReachesTheSource(GeneratedTree $tree): void
    {
        $sources = Matrix::sources($tree);
        foreach (['nested set', 'adjacency shuffled', 'materialized path', 'nested array'] as $sourceName) {
            $where = "{$sourceName}, {$tree->describe()}";
            $viaMaxDepth = new SpyingSource($sources[$sourceName]());
            $builtIn = self::signature(Tree::from($viaMaxDepth)->maxDepth(1));

            $viaSink = new SpyingSource($sources[$sourceName]());
            $pairs = Tree::from($viaSink)->pipe(new PassThroughStage())->into(new ListingSink(skipBelow: 1));

            self::assertSame($builtIn, Trees::signature($pairs), "user sink skipping: {$where}");
            self::assertNotNull($viaMaxDepth->spy);
            self::assertNotNull($viaSink->spy);
            self::assertSame($viaMaxDepth->spy->visited, $viaSink->spy->visited, "reads: {$where}");
            self::assertSame($viaMaxDepth->spy->skipped, $viaSink->spy->skipped, "skips: {$where}");
        }
    }
}
