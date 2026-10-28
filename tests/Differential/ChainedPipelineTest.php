<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Differential;

use Ghispi\TreeTransform\Stage\DepthWindow;
use Ghispi\TreeTransform\Stage\Filter;
use Ghispi\TreeTransform\Stage\Limit;
use Ghispi\TreeTransform\Stage\MaxDepth;
use Ghispi\TreeTransform\Stage\Stage;
use Ghispi\TreeTransform\Strategy\ActiveTrail;
use Ghispi\TreeTransform\Strategy\Annotate;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\MatchState;
use Ghispi\TreeTransform\Strategy\MinMatches;
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Strategy\Placeholder;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Strategy\Prune;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\GeneratedTree;
use Ghispi\TreeTransform\Tests\Fixtures\Matrix;
use Ghispi\TreeTransform\Tests\Fixtures\Oracle\Node;
use Ghispi\TreeTransform\Tests\Fixtures\Oracle\Oracle;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Chained filters and stages. Every downstream stage that cuts (Prune, MaxDepth, DepthWindow, …) calls
 * skipSubtree() on the upstream strategy, so this exercises skipSubtree propagation through every strategy —
 * including skips that hit a KeepAncestors ancestor while it is still being flushed.
 */
final class ChainedPipelineTest extends TestCase
{
    /**
     * @return iterable<string, array{0: GeneratedTree}>
     */
    public static function trees(): iterable
    {
        yield from Matrix::trees();
    }

    /**
     * First stage of each chain: a strategy with its oracle.
     *
     * @return array<string, array{0: \Closure(): Stage, 1: \Closure(list<Node>): list<Node>}>
     */
    public static function upstream(GeneratedTree $tree): array
    {
        $sparse = Matrix::predicates()['sparse'];
        $dense = Matrix::predicates()['dense'];
        $parent = Matrix::predicates()['parent'];
        $active = Matrix::activeIds($tree, 3);
        $annotateLabel = static fn(mixed $n, MatchState $s): array => ['id' => Field::id($n), 'w' => Field::int($n, 'w'), 'state' => $s->value];

        $out = [];
        foreach (OnMatch::cases() as $onMatch) {
            $out['KeepAncestors(sparse) ' . $onMatch->name] = [
                static fn(): Stage => new Filter(Matrix::library($sparse), new KeepAncestors($onMatch)),
                static fn(array $f): array => Oracle::keepAncestors($f, $sparse, $onMatch),
            ];
            $out['Promote(dense) ' . $onMatch->name] = [
                static fn(): Stage => new Filter(Matrix::library($dense), new Promote($onMatch)),
                static fn(array $f): array => Oracle::promote($f, $dense, $onMatch),
            ];
            $out['Prune(parent) ' . $onMatch->name] = [
                static fn(): Stage => new Filter(Matrix::library($parent), new Prune($onMatch)),
                static fn(array $f): array => Oracle::prune($f, $parent, $onMatch),
            ];
        }
        $out['MinMatches(2)'] = [
            static fn(): Stage => new Filter(Matrix::library($dense), new MinMatches(2)),
            static fn(array $f): array => Oracle::minMatches($f, $dense, 2),
        ];
        $out['MinMatches(4, parent)'] = [
            static fn(): Stage => new Filter(Matrix::library($parent), new MinMatches(4)),
            static fn(array $f): array => Oracle::minMatches($f, $parent, 4),
        ];
        foreach ([OnMatch::EvaluateDescendants, OnMatch::KeepDescendants] as $onMatch) {
            $out['Placeholder(dense) ' . $onMatch->name] = [
                static fn(): Stage => new Filter(Matrix::library($dense), new Placeholder(Matrix::placeholderFactory(), $onMatch)),
                static fn(array $f): array => Oracle::placeholder($f, $dense, Matrix::placeholderFactory(), $onMatch),
            ];
        }
        $out['ActiveTrail'] = [
            static fn(): Stage => new Filter(Matrix::library($active), new ActiveTrail()),
            static fn(array $f): array => Oracle::activeTrail($f, $active),
        ];
        $out['ActiveTrail breadcrumb whole subtree'] = [
            static fn(): Stage => new Filter(Matrix::library($active), new ActiveTrail(false, \PHP_INT_MAX)),
            static fn(array $f): array => Oracle::activeTrail($f, $active, false, \PHP_INT_MAX),
        ];
        $out['Annotate'] = [
            static fn(): Stage => new Filter(Matrix::library($dense), new Annotate($annotateLabel)),
            static fn(array $f): array => Oracle::annotate($f, $dense, $annotateLabel),
        ];

        return $out;
    }

    /**
     * Second stage of each chain.
     *
     * @return array<string, array{0: \Closure(): Stage, 1: \Closure(list<Node>): list<array{0: int, 1: mixed}>}>
     */
    public static function downstream(): array
    {
        $dense = Matrix::predicates()['dense'];
        $depth = Matrix::predicates()['depth'];
        $ancestorSum = Matrix::predicates()['ancestor sum'];

        return [
            'MaxDepth(0)' => [static fn(): Stage => new MaxDepth(0), static fn(array $f): array => Node::flatten(Oracle::maxDepth($f, 0))],
            'MaxDepth(1)' => [static fn(): Stage => new MaxDepth(1), static fn(array $f): array => Node::flatten(Oracle::maxDepth($f, 1))],
            'MaxDepth(3)' => [static fn(): Stage => new MaxDepth(3), static fn(array $f): array => Node::flatten(Oracle::maxDepth($f, 3))],
            'DepthWindow(1, 2)' => [static fn(): Stage => new DepthWindow(1, 2), static fn(array $f): array => Node::flatten(Oracle::depthWindow($f, 1, 2))],
            'Limit(5)' => [static fn(): Stage => new Limit(5), static fn(array $f): array => Oracle::limit($f, 5)],
            'Prune(dense)' => [
                static fn(): Stage => new Filter(Matrix::library($dense)),
                static fn(array $f): array => Node::flatten(Oracle::prune($f, $dense)),
            ],
            'Prune(depth) DropDescendants' => [
                static fn(): Stage => new Filter(Matrix::library($depth), new Prune(OnMatch::DropDescendants)),
                static fn(array $f): array => Node::flatten(Oracle::prune($f, $depth, OnMatch::DropDescendants)),
            ],
            'KeepAncestors(ancestor sum)' => [
                static fn(): Stage => new Filter(Matrix::library($ancestorSum), new KeepAncestors()),
                static fn(array $f): array => Node::flatten(Oracle::keepAncestors($f, $ancestorSum)),
            ],
            'Promote(depth) KeepDescendants' => [
                static fn(): Stage => new Filter(Matrix::library($depth), new Promote(OnMatch::KeepDescendants)),
                static fn(array $f): array => Node::flatten(Oracle::promote($f, $depth, OnMatch::KeepDescendants)),
            ],
        ];
    }

    #[DataProvider('trees')]
    public function testTwoStageChainsMatchTheComposedOracle(GeneratedTree $tree): void
    {
        $forest = $tree->oracleForest();
        $sources = Matrix::sources($tree);
        $label = static fn(mixed $n): string => Field::id($n) . (\is_array($n) && isset($n['state']) && \is_string($n['state']) ? '/' . $n['state'] : '');
        foreach (self::upstream($tree) as $upName => [$up, $upOracle]) {
            $intermediate = $upOracle($forest);
            foreach (self::downstream() as $downName => [$down, $downOracle]) {
                $expected = Trees::signature($downOracle($intermediate), $label);
                foreach (['nested set', 'materialized path', 'adjacency shuffled', 'nested array'] as $sourceName) {
                    $cursor = $down()->apply($up()->apply($sources[$sourceName]()->open()));
                    self::assertSame(
                        $expected,
                        Trees::signature(Trees::pairs($cursor), $label),
                        "{$upName} -> {$downName} on source \"{$sourceName}\", {$tree->describe()}",
                    );
                }
            }
        }
    }

    /**
     * A consumer that skips random subtrees sees exactly the pre-order of the tree with those subtrees removed.
     */
    #[DataProvider('trees')]
    public function testRandomConsumerSkipsMatchPrunedOracle(GeneratedTree $tree): void
    {
        $skip = static fn(mixed $n): bool => Field::int($n, 'w') % 4 === 1;
        $keepUnlessParentSkipped = static fn(mixed $n, int $d, array $ancestors): bool => $ancestors === [] || !$skip($ancestors[\count($ancestors) - 1]);
        $forest = $tree->oracleForest();
        $sources = Matrix::sources($tree);
        foreach (self::upstream($tree) as $upName => [$up, $upOracle]) {
            $expected = Trees::signature(Node::flatten(Oracle::prune($upOracle($forest), $keepUnlessParentSkipped)));
            foreach ($sources as $sourceName => $source) {
                $cursor = $up()->apply($source()->open());
                $seen = [];
                while ($cursor->next()) {
                    $seen[] = $cursor->depth() . ':' . Field::id($cursor->node());
                    if ($skip($cursor->node())) {
                        $cursor->skipSubtree();
                    }
                }
                self::assertSame($expected, $seen, "{$upName} with skipping consumer on source \"{$sourceName}\", {$tree->describe()}");
            }
        }
    }
}
