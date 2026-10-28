<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Differential;

use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Stage\Filter;
use Ghispi\TreeTransform\Strategy\ActiveTrail;
use Ghispi\TreeTransform\Strategy\Annotate;
use Ghispi\TreeTransform\Strategy\FilterStrategy;
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
 * SPEC §11 (2): every strategy, on every source, equals the recursive reference oracle.
 *
 * @phpstan-import-type OraclePredicate from Oracle
 */
final class StrategyOracleTest extends TestCase
{
    /**
     * @return iterable<string, array{0: GeneratedTree}>
     */
    public static function trees(): iterable
    {
        yield from Matrix::trees();
    }

    /**
     * @return array<string, array{0: \Closure(): FilterStrategy, 1: \Closure(list<Node>, OraclePredicate): list<Node>}>
     */
    public static function strategies(): array
    {
        $out = [];
        foreach (OnMatch::cases() as $onMatch) {
            $out['Prune ' . $onMatch->name] = [
                static fn(): FilterStrategy => new Prune($onMatch),
                static fn(array $f, \Closure $p): array => Oracle::prune($f, $p, $onMatch),
            ];
            $out['KeepAncestors ' . $onMatch->name] = [
                static fn(): FilterStrategy => new KeepAncestors($onMatch),
                static fn(array $f, \Closure $p): array => Oracle::keepAncestors($f, $p, $onMatch),
            ];
            $out['Promote ' . $onMatch->name] = [
                static fn(): FilterStrategy => new Promote($onMatch),
                static fn(array $f, \Closure $p): array => Oracle::promote($f, $p, $onMatch),
            ];
            $out['Placeholder ' . $onMatch->name] = [
                static fn(): FilterStrategy => new Placeholder(Matrix::placeholderFactory(), $onMatch),
                static fn(array $f, \Closure $p): array => Oracle::placeholder($f, $p, Matrix::placeholderFactory(), $onMatch),
            ];
        }
        foreach ([1, 2, 3, 7] as $min) {
            $out["MinMatches({$min})"] = [
                static fn(): FilterStrategy => new MinMatches($min),
                static fn(array $f, \Closure $p): array => Oracle::minMatches($f, $p, $min),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array{bool, int, bool}> siblings, activeChildrenDepth, multiple
     */
    public static function activeTrailOptions(): array
    {
        return [
            'defaults' => [true, 1, true],
            'no siblings' => [false, 1, true],
            'children depth 0' => [true, 0, true],
            'children depth 2' => [true, 2, true],
            'whole active subtree' => [true, \PHP_INT_MAX, true],
            'single active' => [true, 1, false],
            'breadcrumb, single, depth 0' => [false, 0, false],
            'breadcrumb, whole subtree' => [false, \PHP_INT_MAX, true],
        ];
    }

    #[DataProvider('trees')]
    public function testFilterStrategiesMatchTheOracle(GeneratedTree $tree): void
    {
        $forest = $tree->oracleForest();
        $sources = Matrix::sources($tree);
        foreach (self::strategies() as $strategyName => [$strategy, $oracle]) {
            foreach (Matrix::predicates() as $predicateName => $predicate) {
                $expected = Trees::signature(Node::flatten($oracle($forest, $predicate)));
                foreach ($sources as $sourceName => $source) {
                    $cursor = new Filter(Matrix::library($predicate), $strategy())->apply($source()->open());
                    self::assertSame(
                        $expected,
                        Trees::signature(Trees::pairs($cursor)),
                        "{$strategyName} with predicate \"{$predicateName}\" on source \"{$sourceName}\", {$tree->describe()}",
                    );
                }
            }
        }
    }

    #[DataProvider('trees')]
    public function testActiveTrailMatchesTheOracle(GeneratedTree $tree): void
    {
        $forest = $tree->oracleForest();
        $sources = Matrix::sources($tree);
        $predicates = [
            'one active id' => Matrix::activeIds($tree, 1),
            'three active ids' => Matrix::activeIds($tree, 3),
            'sparse' => Matrix::predicates()['sparse'],
            'dense' => Matrix::predicates()['dense'],
            'none' => Matrix::predicates()['none'],
        ];
        foreach (self::activeTrailOptions() as $optionsName => [$siblings, $childrenDepth, $multiple]) {
            foreach ($predicates as $predicateName => $predicate) {
                $expected = Trees::signature(Node::flatten(
                    Oracle::activeTrail($forest, $predicate, $siblings, $childrenDepth, $multiple),
                ));
                foreach ($sources as $sourceName => $source) {
                    $strategy = new ActiveTrail($siblings, $childrenDepth, $multiple);
                    $cursor = $strategy->apply($source()->open(), new Callback(Matrix::library($predicate)));
                    self::assertSame(
                        $expected,
                        Trees::signature(Trees::pairs($cursor)),
                        "ActiveTrail {$optionsName} with predicate \"{$predicateName}\" on source \"{$sourceName}\", {$tree->describe()}",
                    );
                }
            }
        }
    }

    #[DataProvider('trees')]
    public function testAnnotateMatchesTheOracle(GeneratedTree $tree): void
    {
        $forest = $tree->oracleForest();
        $combine = static fn(mixed $node, MatchState $state): string => Field::id($node) . '/' . $state->value;
        $label = static fn(mixed $node): string => \is_string($node) ? $node : 'not a string';
        foreach (Matrix::predicates() as $predicateName => $predicate) {
            $expected = Trees::signature(Node::flatten(Oracle::annotate($forest, $predicate, $combine)), $label);
            foreach (Matrix::sources($tree) as $sourceName => $source) {
                $cursor = new Filter(Matrix::library($predicate), new Annotate($combine))->apply($source()->open());
                self::assertSame(
                    $expected,
                    Trees::signature(Trees::pairs($cursor), $label),
                    "Annotate with predicate \"{$predicateName}\" on source \"{$sourceName}\", {$tree->describe()}",
                );
            }
        }
    }

    /**
     * SPEC §2 / §5: each predicate is evaluated at most once per node, and not at all where the outcome is already
     * known (inside pruned subtrees, below KeepDescendants / DropDescendants matches): exactly the nodes the oracle
     * tests.
     */
    #[DataProvider('trees')]
    public function testPredicateIsEvaluatedOnExactlyTheNodesTheOracleTests(GeneratedTree $tree): void
    {
        $base = Matrix::predicates()['dense'];
        foreach (self::strategies() as $name => [$strategy, $oracle]) {
            $expected = [];
            $oracle($tree->oracleForest(), static function (mixed $n, int $d, array $a) use (&$expected, $base): bool {
                $expected[] = Field::id($n);

                return $base($n, $d, $a);
            });
            $actual = [];
            $predicate = static function (mixed $n, int $d, Ancestors $a) use (&$actual, $base): bool {
                $actual[] = Field::id($n);

                return $base($n, $d, $a->toArray());
            };
            Trees::pairs(new Filter($predicate, $strategy())->apply(Matrix::sources($tree)['nested set']()->open()));
            self::assertSame($expected, $actual, "{$name}, {$tree->describe()}");
        }

        foreach (['ActiveTrail' => new ActiveTrail(), 'Annotate' => new Annotate()] as $name => $strategy) {
            $actual = [];
            $predicate = static function (mixed $n) use (&$actual, $base): bool {
                $actual[] = Field::id($n);

                return $base($n, 0, []);
            };
            Trees::pairs(new Filter($predicate, $strategy)->apply(Matrix::sources($tree)['nested set']()->open()));
            self::assertSame($tree->ids(), $actual, "{$name}, {$tree->describe()}");
        }
    }
}
