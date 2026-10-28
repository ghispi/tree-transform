<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;
use Ghispi\TreeTransform\Sink\AdjacencyListSink;
use Ghispi\TreeTransform\Sink\CountSink;
use Ghispi\TreeTransform\Sink\FlatListSink;
use Ghispi\TreeTransform\Sink\NestedArraySink;
use Ghispi\TreeTransform\Sink\NestedSetSink;
use Ghispi\TreeTransform\Sink\Sink;
use Ghispi\TreeTransform\Source\AdjacencyListFields;
use Ghispi\TreeTransform\Source\AdjacencyListSource;
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Source\MaterializedPathFields;
use Ghispi\TreeTransform\Source\MaterializedPathSource;
use Ghispi\TreeTransform\Source\NestedArraySource;
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Source\NestedSetSource;
use Ghispi\TreeTransform\Source\OrphanPolicy;
use Ghispi\TreeTransform\Source\PreorderedAdjacencySource;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Stage\DepthWindow;
use Ghispi\TreeTransform\Stage\Filter;
use Ghispi\TreeTransform\Stage\Limit;
use Ghispi\TreeTransform\Stage\Map;
use Ghispi\TreeTransform\Stage\MaxDepth;
use Ghispi\TreeTransform\Stage\SortSiblings;
use Ghispi\TreeTransform\Stage\Stage;
use Ghispi\TreeTransform\Strategy\ActiveTrail;
use Ghispi\TreeTransform\Strategy\Annotate;
use Ghispi\TreeTransform\Strategy\FilterStrategy;
use Ghispi\TreeTransform\Strategy\MatchState;

/**
 * Fluent, immutable entry point: pick a source, add stages, finish with an output.
 *
 *     $menu = Tree::fromNestedSet($rows)
 *         ->filter(fn (array $n) => $n['visible'])
 *         ->maxDepth(2)
 *         ->toNestedArray();
 *
 * Nothing runs until an output method (to…(), iterate(), count()) is called. Every method returns a new instance,
 * so a configured Tree can be reused as a template.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final readonly class Tree implements \IteratorAggregate
{
    /**
     * @param list<Stage> $stages
     */
    private function __construct(
        private Source $source,
        private array $stages = [],
    ) {}

    // ---------------------------------------------------------------- sources

    /**
     * Uses any Source implementation.
     */
    public static function from(Source $source): self
    {
        return new self($source);
    }

    /**
     * Nested set rows ordered by left value (by root, then left value, when several trees share the rows).
     *
     * @param iterable<mixed> $rows
     */
    public static function fromNestedSet(
        iterable $rows,
        NestedSetFields $fields = new NestedSetFields(),
        Accessor $accessor = new AutoAccessor(),
    ): self {
        return new self(new NestedSetSource($rows, $fields, $accessor));
    }

    /**
     * Id/parent rows in any order. Reads all rows first (O(n) memory); see fromPreorderedAdjacencyList().
     *
     * @param iterable<mixed> $rows
     * @param mixed           $rootParent parent value that marks a root besides null, e.g. 0
     * @param string|null     $siblingOrder field to sort siblings by
     */
    public static function fromAdjacencyList(
        iterable $rows,
        AdjacencyListFields $fields = new AdjacencyListFields(),
        Accessor $accessor = new AutoAccessor(),
        OrphanPolicy $orphans = OrphanPolicy::Throw,
        mixed $rootParent = null,
        ?string $siblingOrder = null,
    ): self {
        return new self(new AdjacencyListSource($rows, $fields, $accessor, $orphans, $rootParent, $siblingOrder));
    }

    /**
     * Id/parent rows already in pre-order (parents directly followed by their subtrees). Streams: O(depth) memory.
     *
     * @param iterable<mixed> $rows
     */
    public static function fromPreorderedAdjacencyList(
        iterable $rows,
        AdjacencyListFields $fields = new AdjacencyListFields(),
        Accessor $accessor = new AutoAccessor(),
        mixed $rootParent = null,
    ): self {
        return new self(new PreorderedAdjacencySource($rows, $fields, $accessor, $rootParent));
    }

    /**
     * Materialized path rows ordered by path (binary collation).
     *
     * @param iterable<mixed> $rows
     */
    public static function fromMaterializedPath(
        iterable $rows,
        MaterializedPathFields $fields = new MaterializedPathFields(),
        Accessor $accessor = new AutoAccessor(),
    ): self {
        return new self(new MaterializedPathSource($rows, $fields, $accessor));
    }

    /**
     * Nested arrays or objects with a children field/getter.
     *
     * @param iterable<mixed> $roots
     */
    public static function fromNestedArray(
        iterable $roots,
        string $childrenField = 'children',
        Accessor $accessor = new AutoAccessor(),
    ): self {
        return new self(new NestedArraySource($roots, $childrenField, $accessor));
    }

    /**
     * Pre-order depth => node entries, e.g. the output of iterate().
     *
     * @param iterable<int, mixed> $entries
     */
    public static function fromDepthList(iterable $entries): self
    {
        return new self(DepthListSource::keyed($entries));
    }

    // ---------------------------------------------------------------- stages

    /**
     * Filters with a strategy (default: Prune — a non-matching node is removed with its subtree).
     *
     * @param Predicate|\Closure(mixed, int, Ancestors): bool $predicate fn ($node, int $depth, Ancestors $ancestors)
     */
    public function filter(Predicate|\Closure $predicate, ?FilterStrategy $strategy = null): self
    {
        return $this->pipe($strategy === null ? new Filter($predicate) : new Filter($predicate, $strategy));
    }

    /**
     * Navigation menu expanded down to the active node(s), see ActiveTrail.
     *
     * @param Predicate|\Closure(mixed, int, Ancestors): bool $isActive
     */
    public function activeTrail(
        Predicate|\Closure $isActive,
        bool $siblings = true,
        int $activeChildrenDepth = 1,
        bool $multiple = true,
    ): self {
        return $this->filter($isActive, new ActiveTrail($siblings, $activeChildrenDepth, $multiple));
    }

    /**
     * Keeps every node and attaches its MatchState, see Annotate.
     *
     * @param Predicate|\Closure(mixed, int, Ancestors): bool $predicate
     * @param (\Closure(mixed, MatchState): mixed)|null        $combine   default wraps nodes in Annotated
     */
    public function annotate(Predicate|\Closure $predicate, ?\Closure $combine = null): self
    {
        return $this->filter($predicate, new Annotate($combine));
    }

    /**
     * Transforms each kept node: fn ($node, int $depth): mixed.
     *
     * @param \Closure(mixed, int): mixed $transform
     */
    public function map(\Closure $transform): self
    {
        return $this->pipe(new Map($transform));
    }

    /**
     * Cuts everything deeper than $max (0 = roots only).
     */
    public function maxDepth(int $max): self
    {
        return $this->pipe(new MaxDepth($max));
    }

    /**
     * Keeps depths $min..$max; nodes at $min become roots.
     */
    public function depthWindow(int $min, int $max = \PHP_INT_MAX): self
    {
        return $this->pipe(new DepthWindow($min, $max));
    }

    /**
     * Stops after $count nodes in pre-order.
     */
    public function limit(int $count): self
    {
        return $this->pipe(new Limit($count));
    }

    /**
     * Sorts siblings with a comparator (reads the whole tree: O(n) memory).
     *
     * @param \Closure(mixed, mixed): int $compare
     */
    public function sortSiblings(\Closure $compare): self
    {
        return $this->pipe(new SortSiblings($compare));
    }

    /**
     * Adds any Stage.
     */
    public function pipe(Stage $stage): self
    {
        return new self($this->source, [...$this->stages, $stage]);
    }

    // ---------------------------------------------------------------- outputs

    /**
     * Opens the source and applies all stages.
     */
    public function cursor(): Cursor
    {
        $cursor = $this->source->open();
        foreach ($this->stages as $stage) {
            $cursor = $stage->apply($cursor);
        }

        return $cursor;
    }

    /**
     * Streams nodes in pre-order as depth => node (keys repeat). Memory: O(depth).
     *
     * @return \Generator<int, mixed>
     */
    public function iterate(): \Generator
    {
        $cursor = $this->cursor();
        while ($cursor->next()) {
            yield $cursor->depth() => $cursor->node();
        }
    }

    /**
     * Same as iterate().
     *
     * @return \Generator<int, mixed>
     */
    public function getIterator(): \Generator
    {
        return $this->iterate();
    }

    /**
     * Nested arrays: array payloads get a children key, objects are wrapped in TreeNode.
     *
     * @param (\Closure(mixed, list<mixed>, int): mixed)|null $nodeFactory fn ($node, array $children, int $depth)
     *
     * @return list<mixed>
     */
    public function toNestedArray(
        string $childrenKey = 'children',
        bool $omitEmptyChildren = false,
        ?\Closure $nodeFactory = null,
    ): array {
        return $this->into(new NestedArraySink($childrenKey, $omitEmptyChildren, $nodeFactory));
    }

    /**
     * Pre-order list of ['depth' => int, 'node' => mixed].
     *
     * @return list<array{depth: int, node: mixed}>
     */
    public function toFlatList(): array
    {
        return $this->into(new FlatListSink());
    }

    /**
     * Pre-order list with parent ids pointing at each node's parent in the output.
     *
     * @param (\Closure(mixed, int|string|null, int): mixed)|null $nodeFactory
     *
     * @return list<mixed>
     */
    public function toAdjacencyList(
        string $idField = 'id',
        string $parentField = 'parent_id',
        mixed $rootParent = null,
        ?\Closure $nodeFactory = null,
    ): array {
        return $this->into(new AdjacencyListSink($idField, $parentField, $rootParent, $nodeFactory));
    }

    /**
     * Pre-order list with recomputed nested set values.
     *
     * @param (\Closure(mixed, int, int, int): mixed)|null $nodeFactory
     *
     * @return list<mixed>
     */
    public function toNestedSet(
        string $leftField = 'lft',
        string $rightField = 'rgt',
        ?string $levelField = 'lvl',
        int $levelBase = 0,
        bool $restartPerRoot = false,
        ?\Closure $nodeFactory = null,
    ): array {
        return $this->into(new NestedSetSink($leftField, $rightField, $levelField, $levelBase, $restartPerRoot, $nodeFactory));
    }

    /**
     * Number of nodes in the result.
     */
    public function count(): int
    {
        return $this->into(new CountSink());
    }

    /**
     * Consumes the result with any Sink.
     *
     * @template TResult
     *
     * @param Sink<TResult> $sink
     *
     * @return TResult
     */
    public function into(Sink $sink): mixed
    {
        return $sink->consume($this->cursor());
    }
}
