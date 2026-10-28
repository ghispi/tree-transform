<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Sink;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamSink;

/**
 * Builds a flat list in pre-order with each node's parent id set to its parent in the output — which differs from
 * the input after Promote or DepthWindow.
 *
 * Array payloads get the parent field set; for objects pass a $nodeFactory:
 * fn (mixed $node, int|string|null $parentId, int $depth): mixed.
 *
 * @implements Sink<list<mixed>>
 * @implements StreamSink<list<mixed>>
 */
final readonly class AdjacencyListSink implements Sink, StreamSink
{
    /** @var \Closure(mixed): mixed */
    private \Closure $readId;

    /**
     * @param mixed                                                   $rootParent parent value written for roots
     * @param \Closure|null     $nodeFactory
     */
    public function __construct(
        string $idField = 'id',
        private string $parentField = 'parent_id',
        private mixed $rootParent = null,
        private ?\Closure $nodeFactory = null,
        Accessor $accessor = new AutoAccessor(),
    ) {
        $this->readId = $accessor->reader($idField);
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
        $out = [];
        /** @var array<int, mixed> $ids */
        $ids = [];
        foreach ($input as $depth => $node) {
            $parent = $depth > 0 ? $ids[$depth - 1] : $this->rootParent;
            $ids[$depth] = ($this->readId)($node);

            if ($this->nodeFactory !== null) {
                /** @var int|string|null $parent */
                $out[] = ($this->nodeFactory)($node, $parent, $depth);
            } elseif (\is_array($node)) {
                $node[$this->parentField] = $parent;
                $out[] = $node;
            } else {
                throw new InvalidArgumentException(\sprintf(
                    'AdjacencyListSink cannot set the parent of %s payloads; pass a nodeFactory or map() nodes to arrays.',
                    get_debug_type($node),
                ));
            }
        }

        return $out;
    }
}
