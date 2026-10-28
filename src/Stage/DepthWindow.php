<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Internal\GeneratorCursor;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamStage;

/**
 * Keeps only depths $min..$max (inclusive). Nodes at $min become roots; everything below $max is cut, and the
 * source skips it without reading further.
 *
 * new DepthWindow(1, 2) on "Shop ─ Clothing ─ Men ─ Shirts" gives "Clothing ─ Men": a menu without the site root.
 */
final readonly class DepthWindow implements Stage, StreamStage
{
    public function __construct(private int $min = 0, private int $max = \PHP_INT_MAX)
    {
        if ($min < 0 || $max < $min) {
            throw new InvalidArgumentException(\sprintf('Invalid depth window %d..%d.', $min, $max));
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
        return self::window($input, $this->min, $this->max);
    }

    /**
     * @internal shared with MaxDepth
     *
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    public static function window(\Generator $input, int $min, int $max): \Generator
    {
        while ($input->valid()) {
            $depth = $input->key();
            if ($depth < $min) {
                $input->next();
                continue;
            }
            if ((yield $depth - $min => $input->current()) === true || $depth >= $max) {
                $input->send(true);
            } else {
                $input->next();
            }
        }
    }
}
