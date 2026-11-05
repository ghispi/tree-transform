<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Sink;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Internal\Rows;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamSink;
use Ghispi\TreeTransform\Source\NestedSetFields;

/**
 * Builds a flat list in pre-order with recomputed nested set values — e.g. to store a filtered or restructured
 * tree, or to hand it to code that expects nested sets.
 *
 * Array payloads get the left/right/level fields set; for objects pass a $nodeFactory:
 * fn (mixed $node, int $left, int $right, int $depth, mixed $rootId): mixed.
 *
 * With a root field (NestedSetFields::$root, Gedmo "TreeRoot"), every root's tree is numbered from 1 and each node
 * gets the id of its root in the output (read from $idField) — which differs from the input after Promote or
 * DepthWindow. Every root needs an int or string id; otherwise InvalidArgumentException. Without a root field, the
 * whole forest is numbered as one sequence. A grouping column that holds several roots (e.g. a menu id) is not a
 * root field for the sink: leave it out and keep the column's values with map().

 *
 * @implements Sink<list<mixed>>
 * @implements StreamSink<list<mixed>>
 */
final readonly class NestedSetSink implements Sink, StreamSink
{
    private string $leftField;

    private string $rightField;

    private ?string $levelField;

    private ?string $rootField;

    /** @var (\Closure(mixed): mixed)|null */
    private ?\Closure $readId;

    /**
     * @param NestedSetFields $fields      fields to write; the level is left out when $fields->level is null
     * @param int             $levelBase   level of roots (Gedmo uses 0; some schemas 1)
     * @param \Closure|null   $nodeFactory
     * @param string          $idField     id read (through $accessor) from each root for the root field; unused
     *                                     without one
     */
    public function __construct(
        NestedSetFields $fields = new NestedSetFields(level: 'lvl'),
        private int $levelBase = 0,
        private ?\Closure $nodeFactory = null,
        string $idField = 'id',
        Accessor $accessor = new AutoAccessor(),
    ) {
        $this->leftField = $fields->left;
        $this->rightField = $fields->right;
        $this->levelField = $fields->level;
        $this->rootField = $fields->root;
        $this->readId = $fields->root !== null ? $accessor->reader($idField) : null;
    }

    /**
     * @return list<mixed>
     */
    public function consume(Cursor $cursor): array
    {
        return $this->drain(Stream::of($cursor));
    }

    /**
     * @internal
     *
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return list<mixed>
     */
    public function drain(\Generator $input): array
    {
        /** @var list<mixed> $nodes */
        $nodes = [];
        /** @var list<int> $lefts */
        $lefts = [];
        /** @var list<int> $rights */
        $rights = [];
        /** @var list<int> $depths */
        $depths = [];
        /** @var list<mixed> $rootIds */
        $rootIds = [];
        $rootId = null;
        $lastRootKey = null;
        /** @var array<int, int> $open output positions of open ancestors, indexed by depth */
        $open = [];
        $top = -1;
        $counter = 0;

        foreach ($input as $depth => $node) {
            while ($top >= $depth) {
                $rights[$open[$top--]] = ++$counter;
            }
            if ($depth === 0 && $this->readId !== null) {
                $counter = 0;
                $rootId = ($this->readId)($node);
                $rootKey = $this->rootKey($rootId, $lastRootKey);
                $lastRootKey = $rootKey;
            }
            $position = \count($nodes);
            $nodes[] = $node;
            $lefts[] = ++$counter;
            $rights[] = 0;
            $depths[] = $depth;
            $rootIds[] = $rootId;
            $open[++$top] = $position;
        }
        while ($top >= 0) {
            $rights[$open[$top--]] = ++$counter;
        }

        $out = [];
        foreach ($nodes as $i => $node) {
            $out[] = $this->build($node, $lefts[$i], $rights[$i], $depths[$i], $rootIds[$i]);
        }

        return $out;
    }

    /**
     * Each root's tree is numbered from 1, so a reader tells trees apart by the root value: it must be a usable id
     * and differ from the previous root's.
     */
    private function rootKey(mixed $rootId, int|string|null $previous): int|string
    {
        $key = \is_int($rootId) || \is_string($rootId) || $rootId instanceof \Stringable ? Rows::key($rootId) : null;
        if ($key === null) {
            throw new InvalidArgumentException(\sprintf(
                'NestedSetSink needs an int, string or Stringable id for every root to write the root field "%s"; got %s. Set idField to the id field of your nodes.',
                $this->rootField,
                get_debug_type($rootId),
            ));
        }
        if ($key === $previous) {
            throw new InvalidArgumentException(\sprintf(
                'NestedSetSink: two consecutive roots have the root id %s; each root needs its own id to be numbered from 1.',
                var_export($key, true),
            ));
        }

        return $key;
    }

    private function build(mixed $node, int $left, int $right, int $depth, mixed $rootId): mixed
    {
        if ($this->nodeFactory !== null) {
            return ($this->nodeFactory)($node, $left, $right, $depth, $rootId);
        }
        if (!\is_array($node)) {
            throw new InvalidArgumentException(\sprintf(
                'NestedSetSink cannot set nested set values on %s payloads; pass a nodeFactory or map() nodes to arrays.',
                get_debug_type($node),
            ));
        }
        $node[$this->leftField] = $left;
        $node[$this->rightField] = $right;
        if ($this->levelField !== null) {
            $node[$this->levelField] = $depth + $this->levelBase;
        }
        if ($this->rootField !== null) {
            $node[$this->rootField] = $rootId;
        }

        return $node;
    }
}
