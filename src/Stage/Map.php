<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\GeneratorCursor;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamStage;

/**
 * Transforms each node's payload: fn (mixed $node, int $depth): mixed.
 *
 * Put it after filters so it only runs for nodes that are kept. The structure is unchanged; children are attached
 * by the output, so the callback returns the node without children.
 */
final readonly class Map implements Stage, StreamStage
{
    /**
     * @param \Closure $transform
     */
    public function __construct(private \Closure $transform) {}

    public function apply(Cursor $input): Cursor
    {
        return new GeneratorCursor($this->stream(Stream::of($input)));
    }

    /**
     * @internal
     *
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    public function stream(\Generator $input): \Generator
    {
        return self::map($input, $this->transform);
    }

    /**
     * @param \Generator<int, mixed, bool|null, mixed> $input
     * @param \Closure(mixed, int): mixed              $transform
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function map(\Generator $input, \Closure $transform): \Generator
    {
        while ($input->valid()) {
            $depth = $input->key();
            if ((yield $depth => $transform($input->current(), $depth)) === true) {
                $input->send(true);
            } else {
                $input->next();
            }
        }
    }
}
