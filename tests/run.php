<?php

declare(strict_types=1);

$package = dirname(__DIR__);
$consumer = in_array('--consumer', $argv, true);
$di = realpath(getenv('KALY_DI_PATH') ?: dirname($package) . '/kaly-di/src');

if ($di === false || !is_file($di . '/Definitions.php')) {
    throw new RuntimeException('Set KALY_DI_PATH to a kaly-di src directory that supports bind parameters');
}
if (!$consumer && !is_file($package . '/vendor/bin/mago')) {
    throw new RuntimeException('Run composer install before running the binding corpus');
}

$fixture = __DIR__ . '/fixtures/Bindings.php';
$workspace = sys_get_temp_dir() . '/kaly-mago-' . bin2hex(random_bytes(8));
mkdir($workspace, 0700);
$config = $workspace . '/mago.toml';
$mago = $workspace . '/vendor/bin/mago';

try {
    if ($consumer) {
        $repositories = [];
        foreach (['lekoala/kaly-mago' => $package, 'lekoala/kaly-di' => dirname($di)] as $name => $path) {
            $repositories[] = [
                'type' => 'path',
                'url' => $path,
                'options' => ['symlink' => true, 'versions' => [$name => 'dev-fixture']],
            ];
        }

        file_put_contents($workspace . '/composer.json', json_encode([
            'name' => 'kaly-mago-fixture/consumer',
            'require' => ['lekoala/kaly-di' => 'dev-fixture'],
            'require-dev' => ['lekoala/kaly-mago' => 'dev-fixture'],
            'repositories' => $repositories,
        ], JSON_THROW_ON_ERROR));

        $install = proc_open(
            ['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist'],
            [1 => STDOUT, 2 => STDERR],
            $pipes,
            $workspace,
        );
        if ($install === false || proc_close($install) !== 0) {
            throw new RuntimeException('Composer could not install kaly-mago in the consumer project');
        }
        $di = $workspace . '/vendor/lekoala/kaly-di/src';
    } else {
        symlink($package . '/vendor', $workspace . '/vendor');
    }

    copy($fixture, $workspace . '/Bindings.php');
    mkdir($workspace . '/.mago');
    copy(__DIR__ . '/fixtures/worker.php', $workspace . '/.mago/worker.php');
    file_put_contents(
        $config,
        "[source]\npaths = [\"Bindings.php\"]\n"
        . 'includes = '
        . json_encode([$di], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        . "\n"
        . "[extension-hosts.kaly]\n"
        . 'command = '
        . json_encode([PHP_BINARY, '.mago/worker.php'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        . "\n",
    );

    $process = proc_open(
        [PHP_BINARY, $mago, '--workspace', $workspace, 'analyze', '--reporting-format', 'json'],
        [1 => ['pipe', 'w'], 2 => ['file', $workspace . '/mago.log', 'w']],
        $pipes,
    );
    if ($process === false) {
        throw new RuntimeException('Unable to start Mago');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);

    $report = json_decode((string) $output, true);
    $source = file_get_contents($fixture);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        if (!str_starts_with($issue['code'], 'kaly/di/')) {
            continue;
        }
        $span = $issue['annotations'][0]['span'];
        $start = $span['start']['offset'];
        $anchor = substr($source, $start, $span['end']['offset'] - $start);
        $actual[] = [$issue['code'], $anchor, $issue['level']];
    }

    $expected = [
        ['kaly/di/incompatible-binding',   'Incompatible::class', 'Error'],
        ['kaly/di/unknown-parameter',      '"\x75lr"',            'Error'],
        ['kaly/di/incompatible-parameter', '321',                 'Error'],
        ['kaly/di/unknown-parameter',      "'extra'",             'Error'],
        ['kaly/di/incompatible-parameter', '654',                 'Error'],
    ];
    sort($expected);
    sort($actual);
    if ($status !== 1 || $actual !== $expected) {
        fwrite(STDERR, file_get_contents($workspace . '/mago.log') . "\n" . $output);
        throw new RuntimeException('The binding diagnostics or their locations differ from the expected result');
    }

    echo
        $consumer
            ? "Consumer installation reports the expected binding diagnostics at the expected locations\n"
            : "Binding diagnostics and source locations match the fixture\n";
} finally {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $path = $entry->getPathname();
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($workspace);
}
