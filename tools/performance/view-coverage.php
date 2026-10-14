<?php

use Illuminate\Contracts\Console\Kernel;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$names = ['admin.reports.index', 'admin.sales-orders.index', 'admin.cash.index', 'admin.suppliers.index',
    'layouts.app', 'components.sidebar', 'components.page-context', 'components.report-period',
    'volt-livewire::admin.reports-manager', 'volt-livewire::admin.sales-orders-manager',
    'volt-livewire::admin.cash-manager', 'volt-livewire::admin.suppliers-manager',
    'volt-livewire::layout.navigation', 'volt-livewire::notification-bell', 'livewire::tailwind'];
$rows = [];
foreach ($names as $name) {
    try {
        $source = app('view')->getFinder()->find($name);
    } catch (InvalidArgumentException $e) {
        $rows[] = ['name' => $name, 'error' => $e->getMessage()];

        continue;
    }
    $compiler = app('blade.compiler');
    $runtime = $compiler->getCompiledPath($source);
    $canonical = $compiler->getCompiledPath(realpath($source));
    $rows[] = ['name' => $name, 'source' => $source, 'canonical_source' => realpath($source),
        'runtime_file' => $runtime, 'view_cache_file' => $canonical, 'same_hash' => $runtime === $canonical,
        'runtime_exists' => is_file($runtime), 'view_cache_exists' => is_file($canonical)];
}
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
