<?php

declare(strict_types=1);

/*
 * Checks that every benchmark pair computes the same thing before its numbers are compared:
 * prototypes vs library cursors, generator pipelines vs cursor pipelines, naive baselines vs library output,
 * streaming fixtures vs materialized ones.
 *
 *     docker compose run --rm php php benchmarks/verify.php
 */

use Ghispi\TreeTransform\Accessor\ArrayKeyAccessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Benchmarks\Prototype\FastKeepAncestorsCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\FastNestedArraySink;
use Ghispi\TreeTransform\Benchmarks\Prototype\GeneratorPipeline;
use Ghispi\TreeTransform\Benchmarks\Prototype\IndexedNestedArrayCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\InlineIntNestedSetCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\InlineKeyNestedSetCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\ListNestedSetCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\PruneClosureCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\PruneNoAncestorsCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\SkipFlag;
use Ghispi\TreeTransform\Benchmarks\Support\Fixture;
use Ghispi\TreeTransform\Benchmarks\Support\LazyTree;
use Ghispi\TreeTransform\Benchmarks\Support\Naive;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Source\NestedSetSource;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/bootstrap.php';

ini_set('memory_limit', '3G');

/**
 * @return list<array{0: int, 1: mixed}>
 */
function sequence(Cursor $cursor): array
{
    $out = [];
    while ($cursor->next()) {
        $node = $cursor->node();
        $out[] = [$cursor->depth(), is_array($node) ? $node['id'] : $node];
    }

    return $out;
}

/**
 * @param \Generator<int, mixed> $generator
 *
 * @return list<array{0: int, 1: mixed}>
 */
function generatorSequence(Generator $generator): array
{
    $out = [];
    foreach ($generator as $depth => $node) {
        $out[] = [$depth, is_array($node) ? $node['id'] : $node];
    }

    return $out;
}

function check(bool $ok, string $what): void
{
    echo ($ok ? 'ok    ' : 'FAIL  '), $what, "\n";
    if (!$ok) {
        exit(1);
    }
}

$visible = static fn(array $n): bool => $n['visible'];
$always = static fn(mixed $n): bool => true;
$hit = static fn(array $n): bool => $n['hit'];

foreach (array_keys(Fixture::SHAPES) as $shape) {
    $rows = Fixture::rows($shape);
    $source = static fn(): Cursor => (new NestedSetSource($rows, new NestedSetFields(), new ArrayKeyAccessor()))->open();

    // Sources read the same tree.
    $reference = sequence($source());
    check(count($reference) === Fixture::size($shape), "$shape: size");
    $fromTree = static fn(Tree $tree): array => generatorSequence($tree->iterate());
    check($fromTree(Tree::fromAdjacencyList($rows)) === $reference, "$shape: adjacency list = nested set");
    check($fromTree(Tree::fromPreorderedAdjacencyList($rows)) === $reference, "$shape: pre-ordered adjacency = nested set");
    if ($shape !== 'deep-10k') {
        check($fromTree(Tree::fromMaterializedPath($rows)) === $reference, "$shape: materialized path = nested set");
    }
    check($fromTree(Tree::fromNestedArray(Fixture::nested($shape))) === $reference, "$shape: nested array = nested set");
    check(sequence(new IndexedNestedArrayCursor(Fixture::nested($shape), (new AutoAccessor())->reader('children'), 'children')) === $reference, "$shape: IndexedNestedArrayCursor");
    check($fromTree(Tree::from(DepthListSource::pairs(Fixture::depthPairs($shape)))) === $reference, "$shape: depth list = nested set");

    // Phase 0 (a): prototype sources.
    check(sequence(new InlineKeyNestedSetCursor(new ArrayIterator($rows), 'lft', 'rgt', null)) === $reference, "$shape: InlineKey cursor");
    check(sequence(new InlineIntNestedSetCursor(new ArrayIterator($rows), 'lft', 'rgt', null)) === $reference, "$shape: InlineInt cursor");
    check(sequence(new ListNestedSetCursor($rows, 'lft', 'rgt', null)) === $reference, "$shape: List cursor");

    // Phase 0 (b)/(c): Prune variants.
    $pruned = sequence(Tree::fromNestedSet($rows)->filter($visible)->cursor());
    check(sequence(new PruneNoAncestorsCursor($source(), Callback::of($visible))) === $pruned, "$shape: Prune without ancestors");
    check(sequence(new PruneClosureCursor($source(), $visible)) === $pruned, "$shape: Prune direct closure");
    check(sequence(new PruneClosureCursor(new ListNestedSetCursor($rows, 'lft', 'rgt', null), $visible)) === $pruned, "$shape: Prune over List cursor");
    check(generatorSequence(GeneratorPipeline::pruneSend(GeneratorPipeline::nestedSetSend($rows), $visible)) === $pruned, "$shape: generator send() Prune");
    check(generatorSequence(GeneratorPipeline::pruneSend(GeneratorPipeline::pruneSend(GeneratorPipeline::nestedSetSend($rows), $visible), $always)) === $pruned, "$shape: generator send() Prune x2");
    $flag = new SkipFlag();
    check(generatorSequence(GeneratorPipeline::pruneFlag(GeneratorPipeline::nestedSetFlag($rows, $flag), $flag, $visible)) === $pruned, "$shape: generator flag Prune");
    $flag = new SkipFlag();
    check(generatorSequence(GeneratorPipeline::pruneFlag(GeneratorPipeline::pruneFlag(GeneratorPipeline::nestedSetFlag($rows, $flag), $flag, $visible), $flag, $always)) === $pruned, "$shape: generator flag Prune x2");

    // Naive baselines produce the library's nested arrays.
    check(Naive::adjacencyKeepAncestors($rows, $hit) === Tree::fromAdjacencyList($rows)->filter($hit, new KeepAncestors())->toNestedArray(), "$shape: naive adjacency KeepAncestors");
    check(Naive::nestedSetKeepAncestors($rows, $hit) === Tree::fromNestedSet($rows)->filter($hit, new KeepAncestors())->toNestedArray(), "$shape: naive nested set KeepAncestors");
    check(Naive::adjacencyPrune($rows, $visible) === Tree::fromAdjacencyList($rows)->filter($visible)->toNestedArray(), "$shape: naive adjacency Prune");

    // Pipeline prototypes produce the library's nested arrays.
    $kept = Tree::fromNestedSet($rows)->filter($hit, new KeepAncestors())->toNestedArray();
    check(Naive::nestedSetPrune($rows, $visible) === Tree::fromNestedSet($rows)->filter($visible)->toNestedArray(), "$shape: naive nested set Prune");
    check((new FastNestedArraySink())->consume(Tree::fromNestedSet($rows)->filter($hit, new KeepAncestors())->cursor()) === $kept, "$shape: FastNestedArraySink");
    check((new FastNestedArraySink())->consume(new FastKeepAncestorsCursor(new ListNestedSetCursor($rows, 'lft', 'rgt', null), $hit)) === $kept, "$shape: FastKeepAncestorsCursor");
    check(GeneratorPipeline::toNestedArray(GeneratorPipeline::keepAncestors(GeneratorPipeline::nestedSetSend($rows), $hit)) === $kept, "$shape: generator KeepAncestors");
    check(
        GeneratorPipeline::toNestedArray(GeneratorPipeline::pruneSend(GeneratorPipeline::nestedSetSend($rows), $visible))
            === Tree::fromNestedSet($rows)->filter($visible)->toNestedArray(),
        "$shape: generator Prune → nested array",
    );
    foreach ([OnMatch::KeepDescendants, OnMatch::DropDescendants] as $onMatch) {
        check(
            sequence(new FastKeepAncestorsCursor($source(), $hit, $onMatch))
                === sequence(Tree::fromNestedSet($rows)->filter($hit, new KeepAncestors($onMatch))->cursor()),
            "$shape: FastKeepAncestorsCursor {$onMatch->name}",
        );
    }
}

// IndexedNestedArrayCursor handles non-list children (generators, assoc arrays) and skips like the library.
$mixed = [['id' => 1, 'children' => (static function (): Generator {
    yield ['id' => 2, 'children' => ['a' => ['id' => 3], 'b' => ['id' => 4, 'children' => [['id' => 5]]]]];
    yield ['id' => 6];
})()]];
$expected = [[0, 1], [1, 2], [2, 3], [2, 4], [3, 5], [1, 6]];
check(sequence(new IndexedNestedArrayCursor($mixed, (new AutoAccessor())->reader('children'), 'children')) === $expected, 'IndexedNestedArrayCursor: mixed iterables');
$cursor = new IndexedNestedArrayCursor(Fixture::nested('category-1k'), (new AutoAccessor())->reader('children'), 'children');
$library = (new Ghispi\TreeTransform\Source\NestedArraySource(Fixture::nested('category-1k')))->open();
$skipping = static function (Cursor $c): array {
    $out = [];
    while ($c->next()) {
        $out[] = [$c->depth(), $c->node()['id']];
        if ($c->node()['hit']) {
            $c->skipSubtree();
        }
    }

    return $out;
};
check($skipping($cursor) === $skipping($library), 'IndexedNestedArrayCursor: skipSubtree');

// Streaming fixture = materialized fixture, and is a valid nested set at 1.1M.
check(iterator_to_array(LazyTree::balanced(8, 5), false) === Fixture::rows('balanced-37k'), 'LazyTree::balanced(8, 5) = balanced-37k');
check(Tree::fromNestedSet(LazyTree::balanced(10, 6))->count() === 1_111_111, 'LazyTree::balanced(10, 6): 1,111,111 valid nested set rows');
check(Tree::fromMaterializedPath(LazyTree::balanced(10, 4))->count() === 11_111, 'LazyTree paths are valid');
check(Tree::fromPreorderedAdjacencyList(LazyTree::balanced(10, 4))->count() === 11_111, 'LazyTree parents are valid');

echo "all checks passed\n";
