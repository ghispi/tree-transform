<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Strategy;

use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Strategy\ActiveTrail;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ActiveTrail buffers the children along the current path only: once the path leaves a subtree, whatever was
 * buffered for it is released, not kept until the end of the stream.
 */
#[CoversClass(ActiveTrail::class)]
#[Group('memory')]
final class ActiveTrailMemoryTest extends TestCase
{
    private const int LEAVES = 50_000;

    /**
     * A[B[C[leaves...]], F], H: the leaves are buffered under C while it is unknown whether C is on the trail.
     *
     * @return \Generator<int, array{int, array{id: int|string}}>
     */
    private static function pairs(): \Generator
    {
        yield [0, ['id' => 'A']];
        yield [1, ['id' => 'B']];
        yield [2, ['id' => 'C']];
        for ($i = 0; $i < self::LEAVES; ++$i) {
            yield [3, ['id' => $i]];
        }
        yield [1, ['id' => 'F']];
        yield [0, ['id' => 'H']];
    }

    public function testBuffersOfClosedSubtreesAreReleased(): void
    {
        $tree = Tree::from(DepthListSource::pairs(self::pairs()))->activeTrail(static fn(): bool => false);

        gc_collect_cycles();
        $baseline = memory_get_usage();
        $growth = null;
        foreach ($tree->iterate() as $node) {
            if (\is_array($node) && $node['id'] === 'H') {
                gc_collect_cycles();
                $growth = memory_get_usage() - $baseline;
            }
        }

        self::assertNotNull($growth);
        self::assertLessThan(1024 * 1024, $growth);
    }
}
