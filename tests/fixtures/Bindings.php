<?php

declare(strict_types=1);

namespace KalyMagoFixtures;

use Kaly\Di\Definitions;

interface Search {}

class ParentClient implements Search
{
    /** @param non-empty-string $url */
    public function __construct(
        public string $url,
        public float $timeout = 1.0,
    ) {}
}

final class SearchClient extends ParentClient {}

final class Incompatible {}

final class NoConstructor implements Search {}

final class PartiallyKnownClient extends MissingParent implements Search {}

function compatible(Definitions $di): void
{
    $di->bind(Search::class, SearchClient::class, ['url' => '', 'timeout' => 3]);
    $di->bind(abstract: Search::class, parameters: ['url' => 'ok'], concrete: SearchClient::class);
    $di->bind(Search::class, parameters: ['url' => 'ok'], concrete: SearchClient::class);
    $di->bind(Search::class, SearchClient::class, ['url' => 123, 'url' => 'last value wins']);
}

function incompatible(Definitions $di): void
{
    $di->bind(Search::class, Incompatible::class);
    $di->bind(Search::class, SearchClient::class, ["\x75lr" => 'typo']);
    $di->bind(parameters: ['url' => 321], concrete: SearchClient::class, abstract: Search::class);
    $di->bind(Search::class, NoConstructor::class, ['extra' => true]);
    $di->bind(Search::class, SearchClient::class, ['url' => 654]);
    $di->parameters(Search::class, url: 'overrides the inline value at runtime');
}

/**
 * @param class-string<Search> $class
 * @param array<string, mixed> $parameters
 * @param array{abstract: class-string, concrete: class-string, parameters: array<string, mixed>} $binding
 */
function dynamic(Definitions $di, string $class, array $parameters, array $binding, string $key, mixed $value, int|string $maybe): void
{
    $di->bind(Search::class, $class, ['ulr' => 'dynamic class']);
    $di->bind(Search::class, SearchClient::class, $parameters);
    $di->bind(...$binding);
    $di->bind(Search::class, SearchClient::class, ['url' => 3, ...$parameters]);
    $di->bind(Search::class, SearchClient::class, [$key => 3]);
    $di->bind(Search::class, SearchClient::class, ['url' => $value]);
    $di->bind(Search::class, SearchClient::class, ['url' => $maybe]);
    $di->bind(Search::class, SearchClient::class, ['url' => static fn(): string => 'lazy value']);
    $di->bind(Search::class, PartiallyKnownClient::class, ['ulr' => 'incomplete hierarchy']);
}
