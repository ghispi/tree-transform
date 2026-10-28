<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Bridge\Gedmo;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Gedmo\Tree\TreeListener;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;

/**
 * Tree configuration of one entity class, read from the Gedmo TreeListener and Doctrine metadata.
 */
final readonly class TreeConfig
{
    public const string NESTED = 'nested';
    public const string CLOSURE = 'closure';
    public const string MATERIALIZED_PATH = 'materializedPath';

    /**
     * @param class-string      $class
     * @param class-string|null $closureClass
     */
    public function __construct(
        public string $class,
        public string $strategy,
        public string $id,
        public ?string $parent,
        public ?string $left = null,
        public ?string $right = null,
        public ?string $root = null,
        public bool $rootIsAssociation = false,
        public ?string $level = null,
        public ?string $path = null,
        public string $pathSeparator = ',',
        public bool $pathEndsWithSeparator = true,
        public ?string $closureClass = null,
    ) {}

    /**
     * @param class-string $class
     */
    public static function read(EntityManagerInterface $em, TreeListener $listener, string $class): self
    {
        $meta = $em->getClassMetadata($class);
        $config = $listener->getConfiguration($em, $meta->getName());
        $strategy = $config['strategy'] ?? null;
        if (!\is_string($strategy)) {
            throw new InvalidArgumentException(\sprintf('%s has no Gedmo tree mapping.', $class));
        }
        $string = static fn(string $key): ?string => isset($config[$key]) && \is_string($config[$key]) ? $config[$key] : null;
        $root = $string('root');
        /** @var class-string|null $closure */
        $closure = $string('closure');

        return new self(
            class: $meta->getName(),
            strategy: $strategy,
            id: self::identifier($meta),
            parent: $string('parent'),
            left: $string('left'),
            right: $string('right'),
            root: $root,
            rootIsAssociation: $root !== null && $meta->hasAssociation($root),
            level: $string('level'),
            path: $string('path'),
            pathSeparator: $string('path_separator') ?? ',',
            pathEndsWithSeparator: ($config['path_ends_with_separator'] ?? true) !== false,
            closureClass: $closure,
        );
    }

    /**
     * @param ClassMetadata<object> $meta
     */
    private static function identifier(ClassMetadata $meta): string
    {
        $ids = $meta->getIdentifierFieldNames();
        if (\count($ids) !== 1) {
            throw new InvalidArgumentException(\sprintf('%s must have a single-column identifier.', $meta->getName()));
        }

        return $ids[0];
    }
}
