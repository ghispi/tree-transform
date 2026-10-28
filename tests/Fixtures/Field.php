<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures;

use Ghispi\TreeTransform\Sink\TreeNode;
use Ghispi\TreeTransform\Strategy\Annotated;

/**
 * Typed field reads for test payloads (arrays, Annotated, TreeNode, objects with public props).
 */
final class Field
{
    public static function get(mixed $node, string $field): mixed
    {
        if ($node instanceof Annotated || $node instanceof TreeNode) {
            return self::get($node->node, $field);
        }
        if (\is_array($node)) {
            return $node[$field] ?? null;
        }
        if (\is_object($node) && property_exists($node, $field)) {
            return (new \ReflectionProperty($node, $field))->getValue($node);
        }

        return null;
    }

    public static function id(mixed $node): int|string
    {
        $id = self::get($node, 'id');
        if (!\is_int($id) && !\is_string($id)) {
            throw new \UnexpectedValueException('Test payload without int|string id: ' . get_debug_type($node));
        }

        return $id;
    }

    public static function int(mixed $node, string $field): int
    {
        $value = self::get($node, $field);
        if (!\is_int($value)) {
            throw new \UnexpectedValueException(\sprintf('Test payload field "%s" is not an int.', $field));
        }

        return $value;
    }

    public static function bool(mixed $node, string $field): bool
    {
        return self::get($node, $field) === true;
    }
}
