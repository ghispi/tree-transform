<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Integration\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[Gedmo\Tree(type: 'materializedPath')]
class PathCategory implements TestNode
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[Gedmo\TreePathSource, ORM\Column]
    private string $title;

    #[ORM\Column]
    private bool $visible;

    #[Gedmo\TreePath(separator: ','), ORM\Column(length: 3000, nullable: true)]
    private ?string $path = null;

    #[Gedmo\TreeLevel, ORM\Column(nullable: true)]
    private ?int $level = null;

    #[Gedmo\TreeParent, ORM\ManyToOne(targetEntity: self::class), ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?self $parent = null;

    public function __construct(string $title, bool $visible = true, ?self $parent = null)
    {
        $this->title = $title;
        $this->visible = $visible;
        $this->parent = $parent;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }
}
