<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Exception;

/**
 * The input does not describe a valid tree (bad ordering, overlapping intervals, missing parents, …).
 */
class InvalidTreeException extends \UnexpectedValueException implements TreeTransformException
{
    private mixed $nodeId = null;

    /**
     * For custom sources: an error about the node with the given id (null when it is not known).
     */
    public static function at(mixed $id, string $problem): self
    {
        $exception = new self(\sprintf('Invalid tree at node %s: %s', self::describe($id), $problem));
        $exception->nodeId = $id;

        return $exception;
    }

    /**
     * Id of the offending node as read from the input, or null when the error is not about one node.
     */
    public function nodeId(): mixed
    {
        return $this->nodeId;
    }

    protected static function describe(mixed $id): string
    {
        return match (true) {
            \is_int($id), \is_string($id) => var_export($id, true),
            $id === null => '(unknown id)',
            default => get_debug_type($id),
        };
    }
}
