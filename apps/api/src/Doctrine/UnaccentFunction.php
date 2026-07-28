<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * PostgreSQL unaccent(text) — requires CREATE EXTENSION unaccent.
 *
 * "UNACCENT" "(" StringPrimary ")"
 */
final class UnaccentFunction extends FunctionNode
{
    public Node $stringPrimary;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'unaccent(%s)',
            $sqlWalker->walkSimpleArithmeticExpression($this->stringPrimary),
        );
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->stringPrimary = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
