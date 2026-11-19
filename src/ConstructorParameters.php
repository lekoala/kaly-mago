<?php

declare(strict_types=1);

namespace Kaly\Mago;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\TypeComparison;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;

/**
 * Compares configured names and scalar values with the declared constructor.
 *
 * @internal
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ConstructorParameters
{
    /** @param array<array-key, array{key: Node, value: Node}> $entries */
    public static function check(NodeAnalysisContext $context, string $class, array $entries): void
    {
        $constructor = $context->codebase->getDeclaringMethod($class, '__construct');
        $declaredTypes = [];
        foreach ($constructor?->parameters ?? [] as $parameter) {
            $declaredTypes[ltrim($parameter->name, '$')] = $parameter->declaredType?->type;
        }

        $checked = [];
        foreach ($entries as $name => $entry) {
            if (!array_key_exists($name, $declaredTypes)) {
                $context->report(
                    Level::Error,
                    'unknown-parameter',
                    Issue::new("Unknown constructor parameter `{$name}` for `{$class}`", $entry['key']->span),
                );
                continue;
            }
            $expected = $declaredTypes[$name];
            if ($expected !== null && self::isPlainScalarType($expected)) {
                $checked[] = ['name' => $name, 'value' => $entry['value'], 'expected' => $expected];
            }
        }

        if ($checked === []) {
            return;
        }
        $types = $context->analysis->getMultipleExpressionTypes(array_column($checked, 'value'));
        $comparisons = [];
        $mismatches = [];
        foreach ($checked as $index => $entry) {
            $provided = $types[$index] ?? null;
            if ($provided === null || !self::isPlainScalarType($provided)) {
                continue;
            }
            // A diagnostic requires both failed containment and disjoint types.
            $comparisons[] = TypeComparison::containedBy($provided, $entry['expected']);
            $comparisons[] = TypeComparison::canBeIdentical($provided, $entry['expected']);
            $mismatches[] = $entry + ['provided' => $provided];
        }
        if ($comparisons === []) {
            return;
        }
        $results = $context->types->compareMultiple($comparisons);
        foreach ($mismatches as $index => $entry) {
            if ($results[$index * 2] || $results[($index * 2) + 1]) {
                continue;
            }
            $context->report(
                Level::Error,
                'incompatible-parameter',
                Issue::new(
                    "Constructor parameter `{$entry['name']}` of `{$class}` expects {$entry['expected']}, got {$entry['provided']}",
                    $entry['value']->span,
                ),
            );
        }
    }

    private static function isPlainScalarType(Type $type): bool
    {
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Null) {
                continue;
            }
            if (
                !$atom instanceof ScalarType
                || !in_array(
                    $atom->kind,
                    [
                        ScalarTypeKind::Boolean,
                        ScalarTypeKind::Integer,
                        ScalarTypeKind::Float,
                        ScalarTypeKind::String,
                    ],
                    true,
                )
            ) {
                return false;
            }
        }
        return true;
    }
}
