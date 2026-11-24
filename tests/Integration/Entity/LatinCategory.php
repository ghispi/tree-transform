<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Integration\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Like SlugCategory, with latin1 text columns (MySQL only; other databases ignore the option): the column's
 * character set differs from the connection's.
 */
#[ORM\Entity]
#[Gedmo\Tree(type: 'materializedPath')]
class LatinCategory
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[Gedmo\TreePathSource, ORM\Column(options: ['charset' => 'latin1'])]
    public string $title;
    #[Gedmo\TreePath(separator: '/', appendId: false), ORM\Column(length: 3000, nullable: true, options: ['charset' => 'latin1'])]
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
