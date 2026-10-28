<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks;

use Ghispi\TreeTransform\Benchmarks\Support\Fixture;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Tree;

/**
 * Shared parameter sets and helpers.
 *
 * Parameter sets carry:
 * - shape: Fixture shape name;
 * - nodes: input nodes processed per revolution (shape size x reps), used for nodes/s in benchmarks/report.php;
 * - reps:  passes per revolution — small (1k) shapes are run 100x per revolution so that timings stay well above
 *          timer and process noise. Their set name says so ("wide-1k x100"); divide the time by reps.
 *
 * Memory: setUp*() methods build the fixture and then call memory_reset_peak_usage(), so mem_peak is
 * "fixture + peak working memory of the subject"; benchmarks/report.php also prints mem_peak - mem_final, the
 * subject's own peak (the subject's result is freed before mem_final is taken).
 */
abstract class BenchCase
{
    /** @var list<array<string, mixed>> */
    protected array $rows = [];

    protected int $reps = 1;

    /**
     * @return \Generator<string, array{shape: string, nodes: int, reps: int}>
     */
    public function allShapes(): \Generator
    {
        yield from self::sets(array_keys(Fixture::SHAPES));
    }

    /**
     * Shapes that have a materialized path (all but the deep chain).
     *
     * @return \Generator<string, array{shape: string, nodes: int, reps: int}>
     */
    public function pathShapes(): \Generator
    {
        yield from self::sets(array_values(array_diff(array_keys(Fixture::SHAPES), ['deep-10k'])));
    }

    /**
     * The large shapes only, for the Phase 0 comparisons.
     *
     * @return \Generator<string, array{shape: string, nodes: int, reps: int}>
     */
    public function phase0Shapes(): \Generator
    {
        yield from self::sets(['balanced-300k', 'category-100k', 'wide-100k']);
    }

    /**
     * @param array{shape: string, nodes: int, reps: int} $params
     */
    public function setUpRows(array $params): void
    {
        $this->rows = Fixture::rows($params['shape']);
        $this->reps = $params['reps'];
        memory_reset_peak_usage();
    }

    /**
     * Pulls every node through a cursor, the way a sink does.
     */
    protected static function drain(Cursor $cursor): int
    {
        $count = 0;
        while ($cursor->next()) {
            $cursor->depth();
            $cursor->node();
            ++$count;
        }

        return $count;
    }

    /**
     * Pulls every node through Tree::iterate(), $reps times.
     */
    protected function iterate(Tree $tree): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            foreach ($tree->iterate() as $node) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<string> $shapes
     *
     * @return \Generator<string, array{shape: string, nodes: int, reps: int}>
     */
    private static function sets(array $shapes): \Generator
    {
        foreach ($shapes as $shape) {
            $reps = Fixture::size($shape) <= 1_001 ? 100 : 1;
            yield ($reps > 1 ? $shape . ' x' . $reps : $shape) => [
                'shape' => $shape,
                'nodes' => Fixture::size($shape) * $reps,
                'reps' => $reps,
            ];
        }
    }
}
