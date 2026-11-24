<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Bridge\Gedmo;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL function TT_BINARY(x): MySQL's CAST(CONVERT(x USING utf8mb4) AS BINARY): a comparison that is byte-exact whatever the
 * collation and character set of the column. GedmoTree registers it on MySQL platforms only, for the materialized-path subtree check.
 *
 * @internal
 */
final class BinaryFunction extends FunctionNode
{
    public const NAME = 'TT_BINARY';

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
        // CONVERT first: bytes only compare equal in one character set (a latin1 column against a utf8mb4 connection).
        return \sprintf('CAST(CONVERT(%s USING utf8mb4) AS BINARY)', $sqlWalker->walkSimpleArithmeticExpression($this->operand));
    }
}
