<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures\Model;

/**
 * Payload implementing ArrayAccess.
 *
 * @implements \ArrayAccess<string, mixed>
 */
final class ArrayAccessNode implements \ArrayAccess
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data) {}

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Read-only payload.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Read-only payload.');
    }
}
