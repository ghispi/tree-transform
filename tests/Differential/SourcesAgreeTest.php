<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Differential;

use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\GeneratedTree;
use Ghispi\TreeTransform\Tests\Fixtures\Matrix;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §11 (1): every source rendering of one logical tree yields the same (depth, id) sequence.
 */
final class SourcesAgreeTest extends TestCase
{
    /**
     * @return iterable<string, array{0: GeneratedTree}>
     */
    public static function trees(): iterable
    {
        yield from Matrix::trees(80);
    }

    #[DataProvider('trees')]
    public function testEverySourceYieldsTheSameDepthIdSequence(GeneratedTree $tree): void
    {
        foreach (Matrix::sources($tree) as $name => $source) {
            self::assertSame(
                $tree->signature(),
                Trees::signature(Trees::pairs($source())),
                \sprintf('Source "%s" differs on %s', $name, $tree->describe()),
            );
        }
    }

    #[DataProvider('trees')]
    public function testReOpeningAnArraySourceYieldsTheSameSequenceAgain(GeneratedTree $tree): void
    {
        foreach (Matrix::sources($tree) as $name => $factory) {
            $source = $factory();
            if (str_contains($name, 'generator') || $name === 'adjacency pre-order' || $name === 'materialized path /a/b') {
                continue; // generator-backed: one-shot by design
            }
            $first = Trees::signature(Trees::pairs($source));
            self::assertSame($first, Trees::signature(Trees::pairs($source)), \sprintf('Source "%s" on %s', $name, $tree->describe()));
        }
    }

    #[DataProvider('trees')]
    public function testSkippingEveryNodeYieldsOnlyTheRoots(GeneratedTree $tree): void
    {
        $roots = array_values(array_filter($tree->signature(), static fn(string $s): bool => str_starts_with($s, '0:')));
        foreach (Matrix::sources($tree) as $name => $source) {
            $cursor = $source()->open();
            $seen = [];
            while ($cursor->next()) {
                $seen[] = $cursor->depth() . ':' . Field::id($cursor->node());
                $cursor->skipSubtree();
                $cursor->skipSubtree(); // idempotent
            }
            self::assertSame($roots, $seen, \sprintf('Source "%s" on %s', $name, $tree->describe()));
        }
    }
}
