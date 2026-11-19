# Kaly Mago

Optional development tooling for kaly-di. The extension targets Mago **1.60.2**
and the kaly-di API with a constructor-argument array in the third position of
`Definitions::bind()`.

Install the package as a development dependency.

```sh
composer require --dev lekoala/kaly-mago:^0.1
```

Add a worker at `.mago/worker.php`.

```php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

(new Mago\Sdk\Worker(Kaly\Mago\KalyExtension::create()))->run();
```

Register that worker in your Mago configuration and run `vendor/bin/mago analyze`.

```toml
[extension-hosts.kaly]
command = ["php", ".mago/worker.php"]
```

## Checks

`kaly/di` checks explicit `Definitions::bind()` declarations with statically
resolved class constants. It reports a known incompatible implementation,
unknown constructor parameter names in a literal array, and incompatible values
when Mago can rule out any overlap between the inferred and declared scalar
types. The type check covers `bool`, `int`, `float`, `string`, `null` and their
unions. The constructor's native declaration supplies the expected type.

Each inline declaration is checked on its own. For example, a later
`parameters()` call can overwrite a value at runtime, while the original
`bind()` still reports the invalid inline value. Mixed and overlapping types,
closures, dynamically assembled arrays and incomplete class hierarchies are
left to normal analysis and runtime validation.

## Analyze kaly-tpl views with Mago

Mago can read `.phtml` files using its native PHP analyzer. Include the
directories that hold your templates and add `phtml` to the source extensions.

```toml
[source]
paths = ["src", "modules", "templates"]
extensions = ["php", "phtml"]
```

In a development environment, enable `ViewEngine::debug()` and
`ViewEngine::autoDocblock()`, then render the views you want to analyze. kaly-tpl
writes PHPDoc for the observed variables, including the view helpers, globals
and shared capabilities. You can also supply the `@var` annotations by hand.
Run `vendor/bin/mago analyze`.

Mago 1.60.2 can flag a direct echo of a `Kaly\Tpl\Html` object, even though
PHP renders it via `__toString()`. An explicit cast keeps the original HTML
and gives the analyzer a string.

```php
<?= (string) $v->inc('partials/summary', ['order' => $order]) ?>
```

## Development

Run `composer install`, `composer test` and `composer test:consumer`. Both tests
run the binding corpus against a kaly-di checkout that supports the third
argument to `bind()`. The default source directory is `../kaly-di/src`. Set
`KALY_DI_PATH` to use a different checkout.

The consumer test installs kaly-di and kaly-mago into a temporary Composer
project and loads the worker above through that project's own autoloader.
