<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Bridge\Gedmo;

/**
 * How the bridge loads nodes.
 */
enum Hydration
{
    /**
     * Plain arrays of the entity's fields, with the parent and root associations as ids (default).
     * Much faster and lighter than entities; ideal for menus, APIs and exports.
     */
    case Arrays;

    /**
     * Managed entities. Every loaded entity stays in the EntityManager's identity map until you clear it.
     */
    case Entities;
}
