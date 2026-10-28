<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Exception;

/**
 * The input does not describe a valid tree (bad ordering, overlapping intervals, missing parents, …).
 */
class InvalidTreeException extends \UnexpectedValueException implements TreeTransformException
{
    public static function at(mixed $id, string $problem): self
    {
        return new self(\sprintf('Invalid tree at node %s: %s', self::describe($id), $problem));
    }

    protected static function describe(mixed $id): string
    {
        return match (true) {
            \is_int($id), \is_string($id) => var_export($id, true),
            $id === null => '(unknown id)',
            \is_object($id) => $id::class,
            default => get_debug_type($id),
        };
    }
}
