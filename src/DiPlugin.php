<?php

declare(strict_types=1);

namespace Kaly\Mago;

use Kaly\Di\Definitions;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallExpression;

final class DiPlugin implements Plugin, MethodCallAnalysisHook
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('kaly/di', 'Kaly DI', 'Checks explicit constructor bindings');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerMethodCallAnalysisHook($this);
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(Definitions::class, 'bind')];
    }

    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::ArgumentTypes,
            FileAnalysisRequirement::TargetSubtree,
            FileAnalysisRequirement::SourceText,
        ];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $arguments = BindArguments::fromCall(CallExpression::fromNode($context->source, $context->node));
        $abstract = BindArguments::className($context, $arguments['abstract'] ?? null);
        $concrete = BindArguments::className($context, $arguments['concrete'] ?? null);
        if ($abstract === null || $concrete === null) {
            return;
        }

        [$abstraction, $implementation] = $context->codebase->getMultipleClassLikes([$abstract, $concrete]);
        if (
            $abstraction === null
            || $implementation === null
            || $abstraction->hasIncompleteHierarchy()
            || $implementation->hasIncompleteHierarchy()
            || !in_array($abstraction->kind, [ClassLikeKind::Class_, ClassLikeKind::Interface], true)
            || $implementation->kind !== ClassLikeKind::Class_
        ) {
            return;
        }

        if (!$context->types->isContainedBy(Type::namedObject($concrete), Type::namedObject($abstract))) {
            $concreteArgument = $arguments['concrete'];
            $context->report(
                Level::Error,
                'incompatible-binding',
                Issue::new("Class `{$concrete}` is incompatible with binding `{$abstract}`", $concreteArgument->value->span),
            );
            return;
        }

        $array = $arguments['parameters']->value ?? null;
        if ($array === null) {
            return;
        }

        $entries = BindArguments::literalEntries($context, $array);
        if ($entries !== []) {
            ConstructorParameters::check($context, $concrete, $entries);
        }
    }
}
