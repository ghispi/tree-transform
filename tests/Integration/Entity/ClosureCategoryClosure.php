<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Integration\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Tree\Entity\MappedSuperclass\AbstractClosure;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'closure_unique', columns: ['ancestor', 'descendant'])]
class ClosureCategoryClosure extends AbstractClosure {}
