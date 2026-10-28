<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit;

use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\Prune;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §10: streaming strategies over pre-ordered sources use memory independent of n. ~1.1M generated nodes
 * (complete tree, branching 10, depth 6) go through a strategy and iterate() under a fixed budget.
 */
#[CoversNothing]
#[Group('memory')]
final class StreamingMemoryTest extends TestCase
{
    private const int BRANCHING = 10;
    private const int DEPTH = 6;
    private const int NODES = 1_111_111;
    private const int BUDGET = 2 * 1024 * 1024;

    /**
     * Nested set rows of a complete tree, generated on the fly in pre-order.
     *
     * @return \Generator<int, array{id: int, lft: int, rgt: int}>
     */
    private static function nestedSetRows(): \Generator
    {
        $size = [];
        for ($level = self::DEPTH; $level >= 0; --$level) {
            $size[$level] = $level === self::DEPTH ? 1 : 1 + self::BRANCHING * $size[$level + 1];
        }
        $id = 0;
        $stack = [[1, 0]];
        while ($stack !== []) {
            [$left, $level] = array_pop($stack);
            yield ['id' => $id++, 'lft' => $left, 'rgt' => $left + 2 * $size[$level] - 1];
            if ($level < self::DEPTH) {
                for ($k = self::BRANCHING - 1; $k >= 0; --$k) {
                    $stack[] = [$left + 1 + $k * 2 * $size[$level + 1], $level + 1];
                }
            }
        }
    }

    /**
     * Materialized path rows of the same shape in pre-order (each parent before its subtree; ids in visit order).
     *
     * @return \Generator<int, array{id: int, path: string}>
     */
    private static function pathRows(): \Generator
    {
        $id = 0;
        $stack = [['', 0]];
        while ($stack !== []) {
            [$prefix, $level] = array_pop($stack);
            $path = $prefix . $id . ',';
            yield ['id' => $id++, 'path' => $path];
            if ($level < self::DEPTH) {
                for ($k = self::BRANCHING - 1; $k >= 0; --$k) {
                    $stack[] = [$path, $level + 1];
                }
            }
        }
    }

    /**
     * Peak memory growth while streaming the built tree through iterate(); $count receives the number of nodes.
     *
     * @param \Closure(): Tree $build
     */
    private static function measure(\Closure $build, int &$count): int
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $count = 0;
        foreach ($build()->iterate() as $node) {
            ++$count;
        }

        return memory_get_peak_usage() - $before;
    }

    public function testPruneOverAGeneratedNestedSetStreams(): void
    {
        $count = 0;
        $growth = self::measure(
            static fn(): Tree => Tree::fromNestedSet(self::nestedSetRows())->filter(static fn(mixed $n): bool => Field::int($n, 'id') % 97 !== 5, new Prune()),
            $count,
        );

        self::assertGreaterThan(1_000_000, $count);
        self::assertLessThan(self::BUDGET, $growth, \sprintf('peak memory grew by %d bytes', $growth));
    }

    public function testKeepAncestorsOverGeneratedMaterializedPathsStreams(): void
    {
        $count = 0;
        $growth = self::measure(
            static fn(): Tree => Tree::fromMaterializedPath(self::pathRows())->filter(static fn(mixed $n): bool => Field::int($n, 'id') % 3 === 0, new KeepAncestors()),
            $count,
        );

        self::assertGreaterThan(self::NODES / 3, $count);
        self::assertLessThan(self::BUDGET, $growth, \sprintf('peak memory grew by %d bytes', $growth));
    }
}
