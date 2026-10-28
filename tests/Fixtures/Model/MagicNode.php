<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures\Model;

/**
 * Payload with magic __get/__isset (dynamic properties).
 */
final class MagicNode
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data) {}

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }
}
