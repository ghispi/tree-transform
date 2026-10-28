<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Integration\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Tree\Entity\Repository\ClosureTreeRepository;

#[ORM\Entity(repositoryClass: ClosureTreeRepository::class)]
#[Gedmo\Tree(type: 'closure')]
#[Gedmo\TreeClosure(class: ClosureCategoryClosure::class)]
class ClosureCategory implements TestNode
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private string $title;

    #[ORM\Column]
    private bool $visible;

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

    public function setLevel(int $level): void
    {
        $this->level = $level;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function addClosure(ClosureCategoryClosure $closure): void {}
}
