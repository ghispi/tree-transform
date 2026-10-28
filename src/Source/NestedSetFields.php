<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

/**
 * Field names of a nested set node.
 */
final readonly class NestedSetFields
{
    /**
     * @param string      $left  left value field
     * @param string      $right right value field
     * @param string|null $root  field identifying the tree a row belongs to, when several trees share a table
     *                           and each is numbered from 1 (Gedmo "TreeRoot"); null for a single numbering
     */
    public function __construct(
        public string $left = 'lft',
        public string $right = 'rgt',
        public ?string $root = null,
    ) {}

    /**
     * Field names used in the gedmo/doctrine-extensions documentation (lft, rgt, root).
     */
    public static function gedmo(): self
    {
        return new self('lft', 'rgt', 'root');
    }
}
