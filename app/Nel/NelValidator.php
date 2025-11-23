<?php

namespace App\Nel;

use App\Nel\Ast\BinaryOpNode;
use App\Nel\Ast\ExpressionNode;
use App\Nel\Ast\FunctionCallNode;
use App\Nel\Ast\IdentifierNode;
use App\Nel\Ast\LiteralNode;
use App\Nel\Ast\UnaryOpNode;
use App\Nel\Exception\NelSyntaxException;

final class NelValidator
{
    public function __construct(private readonly NelParser $parser) {}

    /**
     * Parse the expression and ensure all identifier paths are whitelisted.
     *
     * @param  array<int, string>  $allowedPaths
     *
     * @throws NelSyntaxException
     */
    public function assertAllowedIdentifiers(string $expression, array $allowedPaths): void
    {
        $ast = $this->parser->parse($expression);
        $identifiers = $this->collectIdentifiers($ast);

        foreach ($identifiers as $identifier) {
            if (! in_array($identifier, $allowedPaths, true)) {
                throw new NelSyntaxException("Unknown variable referenced: {$identifier}");
            }
        }
    }

    /**
     * @param  array<int, string>  $allowedFunctions
     *
     * @throws NelSyntaxException
     */
    public function assertAllowedFunctions(string $expression, array $allowedFunctions): void
    {
        $ast = $this->parser->parse($expression);
        $functions = $this->collectFunctions($ast);

        foreach ($functions as $function) {
            if (! in_array($function, $allowedFunctions, true)) {
                throw new NelSyntaxException("Unknown helper referenced: {$function}");
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function collectIdentifiers(ExpressionNode $node): array
    {
        if ($node instanceof IdentifierNode) {
            return [implode('.', $node->segments)];
        }

        if ($node instanceof LiteralNode) {
            return [];
        }

        if ($node instanceof UnaryOpNode) {
            return $this->collectIdentifiers($node->operand);
        }

        if ($node instanceof BinaryOpNode) {
            return [
                ...$this->collectIdentifiers($node->left),
                ...$this->collectIdentifiers($node->right),
            ];
        }

        if ($node instanceof FunctionCallNode) {
            $collected = [];

            foreach ($node->arguments as $argument) {
                $collected = [
                    ...$collected,
                    ...$this->collectIdentifiers($argument),
                ];
            }

            return $collected;
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function collectFunctions(ExpressionNode $node): array
    {
        if ($node instanceof FunctionCallNode) {
            $collected = [$node->name];

            foreach ($node->arguments as $argument) {
                $collected = [
                    ...$collected,
                    ...$this->collectFunctions($argument),
                ];
            }

            return $collected;
        }

        if ($node instanceof UnaryOpNode) {
            return $this->collectFunctions($node->operand);
        }

        if ($node instanceof BinaryOpNode) {
            return [
                ...$this->collectFunctions($node->left),
                ...$this->collectFunctions($node->right),
            ];
        }

        return [];
    }
}
