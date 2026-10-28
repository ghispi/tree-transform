<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Internal\GeneratorCursor;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamStage;

/**
 * Stops after $count nodes (in pre-order, so the result is still a valid tree). Stops reading the input too —
 * with a database iterator, the remaining rows are never fetched.
 */
final readonly class Limit implements Stage, StreamStage
{
    public function __construct(private int $count)
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Limit cannot be negative.');
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
        return self::limit($input, $this->count);
    }

    /**
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function limit(\Generator $input, int $count): \Generator
    {
        // The input is not advanced past the last node, so nothing more is read.
        for ($left = $count; $left > 0 && $input->valid(); --$left) {
            $skip = (yield $input->key() => $input->current()) === true;
            if ($left === 1) {
                break;
            }
            if ($skip) {
                $input->send(true);
            } else {
                $input->next();
            }
        }
    }
}
