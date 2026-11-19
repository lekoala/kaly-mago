<?php

declare(strict_types=1);

namespace Kaly\Mago;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\StringType;
use Mago\Sdk\Syntax\CallArgument;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Decodes statically known arguments and their source spans for a binding.
 *
 * @internal
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class BindArguments
{
    private const ARGUMENTS = ['abstract', 'concrete', 'parameters'];

    /** @return array<string, CallArgument> */
    public static function fromCall(CallExpression $call): array
    {
        $result = [];
        foreach ($call->arguments as $argument) {
            $name = $argument->name ?? self::ARGUMENTS[$argument->index] ?? null;
            if ($argument->unpacked || !in_array($name, self::ARGUMENTS, true) || array_key_exists($name, $result)) {
                return [];
            }
            $result[$name] = $argument;
        }
        return $result;
    }

    public static function className(NodeAnalysisContext $context, ?CallArgument $argument): ?string
    {
        if ($argument === null || self::unwrap($context->source, $argument->value)->kind !== NodeKind::ClassConstantAccess) {
            return null;
        }

        $atoms = $context->argumentTypes[$argument->index]->atomicTypes ?? [];
        $atom = $atoms[0] ?? null;
        if (count($atoms) !== 1 || !$atom instanceof ScalarType || !$atom->refinement instanceof ClassLikeStringType) {
            return null;
        }
        return $atom->refinement->literal;
    }

    /** @return array<array-key, array{key: Node, value: Node}> */
    public static function literalEntries(NodeAnalysisContext $context, Node $node): array
    {
        $source = $context->source;
        $array = self::unwrap($source, $node);
        if ($array->kind !== NodeKind::Array && $array->kind !== NodeKind::LegacyArray) {
            return [];
        }

        $pairs = [];
        foreach ($source->getChildren($array) as $element) {
            $pair = $source->getChildren($element)[0] ?? null;
            if ($pair?->kind !== NodeKind::KeyValueArrayElement) {
                return [];
            }

            $children = $source->getChildren($pair);
            if (count($children) !== 2) {
                return [];
            }
            [$key, $value] = $children;
            if (self::unwrap($source, $key)->kind !== NodeKind::LiteralString) {
                return [];
            }
            $pairs[] = ['key' => $key, 'value' => $value];
        }
        if ($pairs === []) {
            return [];
        }

        $keyTypes = $context->analysis->getMultipleExpressionTypes(array_column($pairs, 'key'));
        $entries = [];
        foreach ($pairs as $index => $pair) {
            $atoms = $keyTypes[$index]->atomicTypes ?? [];
            $atom = $atoms[0] ?? null;
            if (count($atoms) !== 1 || !$atom instanceof ScalarType || !$atom->refinement instanceof StringType) {
                return [];
            }
            $literal = $atom->refinement->literalValue;
            if ($literal === null) {
                return [];
            }
            // PHP uses the last occurrence of a duplicate array key.
            $entries[$literal] = $pair;
        }
        return $entries;
    }

    private static function unwrap(SourceFile $source, Node $node): Node
    {
        while (in_array($node->kind, [NodeKind::Expression, NodeKind::Access, NodeKind::Parenthesized, NodeKind::Literal], true)) {
            $child = $source->getChildren($node)[0] ?? null;
            if ($child === null) {
                break;
            }
            $node = $child;
        }
        return $node;
    }
}
