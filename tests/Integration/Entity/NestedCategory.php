<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Integration\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[Gedmo\Tree(type: 'nested')]
class NestedCategory implements TestNode
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private string $title;

    #[ORM\Column]
    private bool $visible;

    #[Gedmo\TreeLeft, ORM\Column]
    private int $lft = 0;

    #[Gedmo\TreeRight, ORM\Column]
    private int $rgt = 0;

    #[Gedmo\TreeLevel, ORM\Column]
    private int $lvl = 0;

    #[Gedmo\TreeRoot, ORM\ManyToOne(targetEntity: self::class), ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?self $root = null;

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

    public function getParent(): ?self
    {
        return $this->parent;
    }
}
