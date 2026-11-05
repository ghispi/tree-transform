<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Integration;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gedmo\Mapping\Driver\AttributeReader;
use Gedmo\Tree\TreeListener;
use Ghispi\TreeTransform\Tests\Integration\Entity\TestNode;
use PHPUnit\Framework\TestCase;

abstract class GedmoTestCase extends TestCase
{
    protected EntityManager $em;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $events = new EventManager();
        $listener = new TreeListener();
        $listener->setAnnotationReader(new AttributeReader());
        $events->addEventSubscriber($listener);
        $connection = self::connection($config);
        $this->em = new EntityManager($connection, $config, $events);
        $schema = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata); // a shared database (MySQL, PostgreSQL) keeps tables between tests
        $schema->createSchema($metadata);
    }

    /**
     * SQLite in memory by default; TT_DATABASE_URL (e.g. pdo-mysql://app:app@mysql/tree_transform) selects another
     * database, see `make test-db`.
     */
    private static function connection(Configuration $config): Connection
    {
        $url = getenv('TT_DATABASE_URL');
        if ($url === false || $url === '') {
            return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        }
        $parser = new DsnParser(['pdo-mysql' => 'pdo_mysql', 'pdo-pgsql' => 'pdo_pgsql', 'pdo-sqlite' => 'pdo_sqlite']);

        return DriverManager::getConnection($parser->parse($url), $config);
    }

    /**
     * Persists the spec's running example: A(B(C, D(E)), F(G)), H(I). C, E and I are "visible" matches; so is A.
     *
     * @param \Closure(string, bool, ?TestNode): TestNode $make
     *
     * @return array<string, TestNode>
     */
    protected function persistExample(\Closure $make): array
    {
        $nodes = [];
        $add = function (string $title, bool $visible, ?string $parent) use ($make, &$nodes): void {
            $node = $make($title, $visible, $parent === null ? null : $nodes[$parent]);
            $this->em->persist($node);
            $this->em->flush();
            $nodes[$title] = $node;
        };
        $add('A', true, null);
        $add('B', false, 'A');
        $add('C', true, 'B');
        $add('D', false, 'B');
        $add('E', true, 'D');
        $add('F', false, 'A');
        $add('G', false, 'F');
        $add('H', false, null);
        $add('I', true, 'H');
        $this->em->clear();

        return $nodes;
    }

    /**
     * Renders a tree as "A .B ..C" using each node's title.
     *
     * @param iterable<int, mixed> $nodes depth => node
     */
    protected static function outline(iterable $nodes): string
    {
        $out = [];
        foreach ($nodes as $depth => $node) {
            $title = \is_array($node) ? $node['title'] : ($node instanceof TestNode ? $node->getTitle() : '?');
            \assert(\is_string($title));
            $out[] = str_repeat('.', $depth) . $title;
        }

        return implode(' ', $out);
    }
}
