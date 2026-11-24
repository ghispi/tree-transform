<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Integration;

use Doctrine\ORM\QueryBuilder;
use Ghispi\TreeTransform\Bridge\Gedmo\GedmoTree;
use Ghispi\TreeTransform\Bridge\Gedmo\Hydration;
use Ghispi\TreeTransform\Tests\Integration\Entity\SlugCategory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The materialized-path subtree is matched byte-exactly in SQL, whatever the collation of the path column: the other
 * branch (`alpha/`, which a case-insensitive comparison confuses with `Alpha/`) must neither take a slot of a
 * modifier's limit nor reach the identity map. Each case re-creates the path column with the given collation (raw
 * DDL, because entity attributes would apply to every database); cases for other databases are skipped.
 */
final class GedmoPathCollationTest extends GedmoTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function collations(): iterable
    {
        yield 'sqlite default' => ['sqlite', []];
        yield 'sqlite NOCASE' => ['sqlite', [
            'DROP TABLE SlugCategory',
            'CREATE TABLE SlugCategory (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, path VARCHAR(3000) DEFAULT NULL COLLATE NOCASE, level INTEGER DEFAULT NULL, parent_id INTEGER DEFAULT NULL)',
        ]];
        yield 'mysql default (case-insensitive)' => ['mysql', []];
        yield 'mysql latin1' => ['mysql', ['ALTER TABLE SlugCategory MODIFY path VARCHAR(3000) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL']];
        yield 'mysql utf8mb4_bin' => ['mysql', ['ALTER TABLE SlugCategory MODIFY path VARCHAR(3000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL']];
        yield 'postgresql default' => ['postgresql', []];
        yield 'postgresql nondeterministic ICU' => ['postgresql', [
            "CREATE COLLATION IF NOT EXISTS tt_ci (provider = icu, locale = 'und-u-ks-level2', deterministic = false)",
            'ALTER TABLE SlugCategory ALTER COLUMN path TYPE VARCHAR(3000) COLLATE tt_ci',
        ]];
    }

    /**
     * @param 'sqlite'|'mysql'|'postgresql' $platform
     * @param list<string> $ddl
     */
    #[DataProvider('collations')]
    public function testTheOtherCaseVariantBranchTakesNoSlotAndIsNotHydrated(string $platform, array $ddl): void
    {
        $connection = $this->em->getConnection();
        if (stripos($connection->getDatabasePlatform()::class, $platform) === false) {
            self::markTestSkipped('Case for another database.');
        }
        foreach ($ddl as $statement) {
            $connection->executeStatement($statement);
        }

        $upper = new SlugCategory('Alpha');
        $lower = new SlugCategory('alpha');
        $this->em->persist($upper);
        $this->em->persist($lower);
        $this->em->flush();
        $this->em->persist(new SlugCategory('unrelated', $lower));
        $this->em->persist(new SlugCategory('wanted', $upper));
        $this->em->flush();
        $upperId = $upper->id;
        $gedmo = new GedmoTree($this->em);
        $limited = static fn(QueryBuilder $qb, string $a): QueryBuilder => $qb->orderBy("{$a}.id")->setMaxResults(2);

        $this->em->clear();
        $arrays = $gedmo->tree(SlugCategory::class, root: $upperId, modify: $limited);
        self::assertSame('Alpha .wanted', self::outline($arrays->iterate()));

        $this->em->clear();
        $entities = $gedmo->tree(SlugCategory::class, root: $upperId, hydration: Hydration::Entities, modify: $limited);
        self::assertSame(
            ['Alpha', 'wanted'],
            array_map(static fn(array $entry): mixed => $entry['node'] instanceof SlugCategory ? $entry['node']->title : null, $entities->toFlatList()),
        );
        self::assertSame(2, $this->em->getUnitOfWork()->size());
    }
}
