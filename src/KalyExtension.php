<?php

declare(strict_types=1);

namespace Kaly\Mago;

use Mago\Sdk\Extension;

final class KalyExtension
{
    public static function create(): Extension
    {
        return new Extension(identifier: 'lekoala/kaly-mago', name: 'Kaly', version: '0.1.0', analyzerPlugins: [new DiPlugin()]);
    }
}
