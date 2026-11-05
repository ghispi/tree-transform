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
     *                           and each is numbered from 1 (Gedmo "TreeRoot"); null for a single numbering.
     *                           NestedSetSink writes the id of each node's root into it (one tree per value)
     * @param string|null $level level field; only written by NestedSetSink (sources compute depths from the
     *                           intervals), null to leave it out
     * @param string      $id    id field; NestedSetSource reads it only to name the offending node in an
     *                           InvalidTreeException (nodeId() is null when the row has no such field)
     */
    public function __construct(
        public string $left = 'lft',
        public string $right = 'rgt',
        public ?string $root = null,
        public ?string $level = null,
        public string $id = 'id',
    ) {}

    /**
     * Field names used in the gedmo/doctrine-extensions documentation (lft, rgt, root, lvl).
     */
    public static function gedmo(): self
    {
        return new self('lft', 'rgt', 'root', 'lvl');
    }
}
