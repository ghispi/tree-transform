<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Bridge\Gedmo;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL function TT_BINARY(x): MySQL's CAST(x AS BINARY), which makes a comparison byte-exact whatever the collation of
 * the column. GedmoTree registers it on MySQL platforms only, for the materialized-path subtree check.
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
        return \sprintf('CAST(%s AS BINARY)', $sqlWalker->walkSimpleArithmeticExpression($this->operand));
    }
}
