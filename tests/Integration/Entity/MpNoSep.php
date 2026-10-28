<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Integration\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[Gedmo\Tree(type: 'materializedPath')]
class MpNoSep
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Gedmo\TreePathSource]
    public ?int $id = null;
    #[ORM\Column]
    public string $title;
    #[Gedmo\TreePath(separator: '/', appendId: false, startsWithSeparator: true, endsWithSeparator: false), ORM\Column(length: 3000, nullable: true)]
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
