<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Internal\GeneratorCursor;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamStage;

/**
 * Cuts everything deeper than $max (roots are depth 0, so MaxDepth(0) keeps only roots). The source skips the cut
 * subtrees without reading them further.
 */
final readonly class MaxDepth implements Stage, StreamStage
{
    public function __construct(private int $max)
    {
        if ($max < 0) {
            throw new InvalidArgumentException('Maximum depth cannot be negative.');
        }
    }

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
        return DepthWindow::window($input, 0, $this->max);
    }
}
