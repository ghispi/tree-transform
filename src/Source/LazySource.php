<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamSource;

/**
 * Creates the real source on every open(), e.g. to run a database query each time the tree is read:
 *
 *     new LazySource(fn () => new NestedSetSource($query->toIterable()))
 *
 * This makes one-shot inputs (generators, database iterators) re-readable, so a configured Tree can produce
 * several outputs.
 */
final readonly class LazySource implements Source, StreamSource
{
    /**
     * @param \Closure(): Source $factory
     */
    public function __construct(private \Closure $factory) {}

    public function open(): Cursor
    {
        return ($this->factory)()->open();
    }

    /**
     * @internal
     *
     * @return \Generator<int, mixed, bool|null, mixed>
     */
    public function stream(): \Generator
    {
        return Stream::open(($this->factory)());
    }
}
