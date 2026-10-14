<?php

use App\Http\Middleware\ScopeRbacMemo;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// CLI-only local benchmark. Authentication exists only in this process.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$start = hrtime(true);
putenv('DEBUGBAR_ENABLED=true');
$_ENV['DEBUGBAR_ENABLED'] = $_SERVER['DEBUGBAR_ENABLED'] = 'true';
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->instance('request', Request::create('http://mini-erp.test', 'GET'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$memoEnabled = ($argv[3] ?? 'memo') === 'memo';
if (! $memoEnabled) {
    // Reproduce uncached RBAC reads without changing any authorization middleware.
    $kernel->setGlobalMiddleware(array_values(array_filter(
        $kernel->getGlobalMiddleware(),
        fn ($middleware) => $middleware !== ScopeRbacMemo::class,
    )));
}
if (! $app->environment('local')) {
    throw new RuntimeException('Local environment required');
}
$uri = $argv[1] ?? '/finance/cash';
if (! in_array($uri, ['/finance/cash', '/reports', '/sales/orders'], true)) {
    exit(1);
}
config(['session.driver' => 'array', 'debugbar.enabled' => true]);
app('debugbar')->enable();
$user = User::findOrFail((int) ($argv[2] ?? 1));
Auth::guard('web')->setUser($user);
$queries = [];
DB::listen(function ($query) use (&$queries) {
    $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'ms' => $query->time];
});
$request = Request::create(rtrim(config('app.url'), '/').$uri, 'GET');
$request->headers->set('Accept', 'text/html');
$handleStart = hrtime(true);
$response = $kernel->handle($request);
$total = (hrtime(true) - $start) / 1e6;
$handle = (hrtime(true) - $handleStart) / 1e6;
$duplicates = [];
foreach ($queries as $query) {
    $key = json_encode([$query['sql'], $query['bindings']]);
    $duplicates[$key] = ($duplicates[$key] ?? 0) + 1;
}
echo json_encode([
    'route' => $uri, 'status' => $response->getStatusCode(), 'php' => PHP_VERSION,
    'sapi' => PHP_SAPI, 'debugbar' => app('debugbar')->isEnabled(),
    'rbac_memo' => $memoEnabled,
    'query_count' => count($queries),
    'duplicated_queries' => array_sum(array_map(fn ($n) => max(0, $n - 1), $duplicates)),
    'super_admin_queries' => count(array_filter($queries, fn ($q) => str_contains($q['sql'], 'exists') && in_array('super-admin', $q['bindings'], true))),
    'sql_ms' => array_sum(array_column($queries, 'ms')), 'kernel_ms' => $handle, 'total_ms' => $total,
], JSON_PRETTY_PRINT).PHP_EOL;
