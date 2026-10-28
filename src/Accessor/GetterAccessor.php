<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Accessor;

/**
 * Calls getters: field "lft" is read with getLft(), "isActive" style getters can be named directly ("isActive").
 */
final readonly class GetterAccessor implements Accessor
{
    public function reader(string $field): \Closure
    {
        $method = preg_match('/^(get|is|has)[A-Z]/', $field) === 1 ? $field : 'get' . ucfirst($field);

        return static fn(mixed $node): mixed => $node->{$method}();
    }
}
