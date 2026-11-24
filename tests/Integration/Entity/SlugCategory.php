<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Integration\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Materialized path built from titles alone (no ids), so two titles that differ only by case or encoding give paths
 * that differ only by case or encoding.
 */
#[ORM\Entity]
#[Gedmo\Tree(type: 'materializedPath')]
class SlugCategory
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[Gedmo\TreePathSource, ORM\Column]
    public string $title;
    #[Gedmo\TreePath(separator: '/', appendId: false), ORM\Column(length: 3000, nullable: true)]
    public ?string $path = null;
    #[Gedmo\TreeLevel, ORM\Column(nullable: true)]
    public ?int $level = null;
    #[Gedmo\TreeParent, ORM\ManyToOne(targetEntity: self::class)]
    public ?self $parent = null;

    public function __construct(string $title, ?self $parent = null)
    {
        $this->title = $title;
        $this->parent = $parent;
    }
}
