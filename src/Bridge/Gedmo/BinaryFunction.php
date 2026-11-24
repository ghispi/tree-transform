<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Bridge\Gedmo;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL function TREE_TRANSFORM_BINARY(x): x as a binary string, so that comparing two of them is byte-exact whatever the collation
 * and character set of the column (MySQL/MariaDB: CAST(CONVERT(x USING utf8mb4) AS BINARY); SQLite: COLLATE BINARY;
 * PostgreSQL: COLLATE "C"; other platforms: x unchanged). GedmoTree registers it for the materialized-path subtree check.
 *
 * @internal
 */
final class BinaryFunction extends FunctionNode
{
    public const NAME = 'TREE_TRANSFORM_BINARY';

    public Node $operand;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->operand = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $operand = $sqlWalker->walkSimpleArithmeticExpression($this->operand);
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();

        return match (true) {
            // CONVERT first: bytes only compare equal in one character set (a latin1 column, a utf8mb4 connection).
            $platform instanceof AbstractMySQLPlatform => \sprintf('CAST(CONVERT(%s USING utf8mb4) AS BINARY)', $operand),
            // An explicit COLLATE wins over the column's (NOCASE) collation.
            self::isSqlite($platform) => \sprintf('(%s COLLATE BINARY)', $operand),
            $platform instanceof PostgreSQLPlatform => \sprintf('(CAST(%s AS TEXT) COLLATE "C")', $operand),
            default => $operand,
        };
    }

    /**
     * By class name: DBAL 3 calls the class SqlitePlatform, DBAL 4 SQLitePlatform, and PSR-4 autoloading is
     * case-sensitive.
     */
    private static function isSqlite(object $platform): bool
    {
        return preg_match('/\\\\sqliteplatform$/i', '\\\\' . $platform::class) === 1;
    }
}
