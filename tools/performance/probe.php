<?php

// Local, CLI-only diagnostic. Does not register instrumentation in the application.
if (PHP_SAPI !== 'cli') { exit(1); }
$started = hrtime(true);
$debug = ($argv[2] ?? 'false') === 'true';
$debugEnv = $debug ? 'true' : 'false';
putenv('DEBUGBAR_ENABLED='.$debugEnv);
$_ENV['DEBUGBAR_ENABLED'] = $_SERVER['DEBUGBAR_ENABLED'] = $debugEnv;
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('http://mini-erp.test', 'GET'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
if (! $app->environment('local')) { throw new RuntimeException('Local environment required.'); }
$uri = $argv[1] ?? '/reports';
if (! in_array($uri, ['/reports', '/sales/orders', '/administration/suppliers'], true)) { throw new RuntimeException('Unsupported route.'); }
config(['debugbar.enabled' => $debug, 'session.driver' => 'array']);
$debugbar = app('debugbar');
$debug ? $debugbar->enable() : $debugbar->disable();

class PerfProbe
{
    public static array $spans = [];
    public static array $queries = [];
    public static array $tables = [];
    public static array $components = [];
    public static array $gates = [];
    public static array $includeStarts = [];
    public static function firstInstruction(string $path): void
    {
        if (isset(self::$includeStarts[basename($path)])) {
            self::record('PHP load compiled '.basename($path), self::$includeStarts[basename($path)]);
        }
    }
    public static function measure(string $name, callable $callback): mixed
    {
        $start = hrtime(true);
        try { return $callback(); }
        finally { self::record($name, $start); }
    }
    public static function record(string $name, int $start): void
    {
        self::$spans[] = ['name' => $name, 'ms' => (hrtime(true) - $start) / 1e6];
    }
}

class MeasuredReportService extends App\Services\ReportService
{
    public function currency(): string { return PerfProbe::measure('REPORT currency()', fn () => parent::currency()); }
    public function period(string $from, string $to): array { return PerfProbe::measure('REPORT period()', fn () => parent::period($from, $to)); }
    public function salesSummary(string $from, string $to): array { return PerfProbe::measure('REPORT summary()', fn () => parent::salesSummary($from, $to)); }
    public function monthlySummary(string $from, string $to): array { return PerfProbe::measure('REPORT monthlySummary()', fn () => parent::monthlySummary($from, $to)); }
    public function table(string $section, string $from, string $to, string $pageName = 'page')
    {
        $paginator = PerfProbe::measure('REPORT table query '.$section, fn () => parent::table($section, $from, $to, $pageName));
        $measured = new MeasuredPaginator($paginator->getCollection(), $paginator->total(), $paginator->perPage(), $paginator->currentPage(), $paginator->getOptions());
        $measured->kind = $section;
        return PerfProbe::$tables[$section] = $measured;
    }
}
class MeasuredPaginator extends Illuminate\Pagination\LengthAwarePaginator
{
    public string $kind;
    public function getIterator(): Traversable
    {
        $start = hrtime(true);
        try { foreach (parent::getIterator() as $key => $row) { yield $key => $row; } }
        finally { PerfProbe::record('REPORT table formatting '.$this->kind, $start); }
    }
}
$app->bind(App\Services\ReportService::class, MeasuredReportService::class);

// Preserve the configured Blade compiler and Livewire engine; time their actual calls.
$engine = app('view.engine.resolver')->resolve('blade');
$compiler = $engine->getCompiler();
$cacheMode = $argv[4] ?? 'existing';
if ($cacheMode !== 'existing') {
    if (! preg_match('/^[a-zA-Z0-9_-]+$/', $cacheMode)) { throw new RuntimeException('Invalid cache identifier.'); }
    $cache = storage_path('framework/performance-views/'.$cacheMode);
    if (! is_dir($cache)) { mkdir($cache, 0777, true); }
    config(['view.compiled' => $cache]);
    (new ReflectionProperty(Illuminate\View\Compilers\Compiler::class, 'cachePath'))->setValue($compiler, $cache);
}
$traceLoading = $cacheMode !== 'existing' && ($argv[5] ?? '') === 'loadtrace';
$proxy = new class($compiler, $traceLoading) implements Illuminate\View\Compilers\CompilerInterface {
    public function __construct(private object $inner, private bool $traceLoading) {}
    public function getCompiledPath($path) {
        $compiled = PerfProbe::measure('BLADE cache path '.basename($path), fn () => $this->inner->getCompiledPath($path));
        PerfProbe::$includeStarts[basename($compiled)] = hrtime(true);
        return $compiled;
    }
    public function isExpired($path) { return PerfProbe::measure('BLADE expiry check '.basename($path), fn () => $this->inner->isExpired($path)); }
    public function compile($path) {
        PerfProbe::measure('BLADE compile '.basename($path ?? $this->inner->getPath()), fn () => $this->inner->compile($path));
        if ($this->traceLoading) {
            $compiled = $this->inner->getCompiledPath($path);
            $contents = file_get_contents($compiled);
            if (! str_starts_with($contents, '<?php PerfProbe::firstInstruction')) {
                file_put_contents($compiled, '<?php PerfProbe::firstInstruction(__FILE__); ?>'.$contents);
            }
        }
    }
    public function __call($method, $arguments) { return $this->inner->$method(...$arguments); }
};
(new ReflectionProperty(Illuminate\View\Engines\CompilerEngine::class, 'compiler'))->setValue($engine, $proxy);
app('view.engine.resolver')->register('blade', fn () => new class($engine) implements Illuminate\Contracts\View\Engine {
    public function __construct(private object $inner) {}
    public function get($path, array $data = []) { return PerfProbe::measure('BLADE render '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $path), fn () => $this->inner->get($path, $data)); }
    public function __call($method, $arguments) { return $this->inner->$method(...$arguments); }
});
Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
    PerfProbe::$gates[] = [$ability, hrtime(true)];
    return null;
});
Illuminate\Support\Facades\Gate::after(function ($user, $ability) {
    [$name, $start] = array_pop(PerfProbe::$gates);
    PerfProbe::record('AUTH '.$name, $start);
});
Illuminate\Support\Facades\DB::listen(function ($query) {
    PerfProbe::$queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'ms' => $query->time];
});
Livewire\on('mount', function ($component) { PerfProbe::$components[$component->getId()] = $component; });
Livewire\on('profile', function ($phase, $id, $times) {
    $component = PerfProbe::$components[$id] ?? null;
    PerfProbe::$spans[] = ['name' => 'LIVEWIRE '.$phase.' '.($component?->getName() ?? $id), 'ms' => ($times[1] - $times[0]) * 1000];
});

// Impersonation exists only in this CLI process. No login/session is persisted.
$user = App\Models\User::findOrFail((int) ($argv[3] ?? 1));
Illuminate\Support\Facades\Auth::guard('web')->setUser($user);
$bootMs = (hrtime(true) - $started) / 1e6;
$request = Illuminate\Http\Request::create(rtrim(config('app.url'), '/').$uri, 'GET');
$request->headers->set('Accept', 'text/html');
$response = PerfProbe::measure('HTTP kernel handle', fn () => $kernel->handle($request));
$totalMs = (hrtime(true) - $started) / 1e6;
$requestSpans = PerfProbe::$spans;
$requestQueries = PerfProbe::$queries;

// A separate formatting replay, outside the request, using the exact paginator/model casts.
foreach (PerfProbe::$components as $component) {
    if ($component->getName() !== 'admin.reports-manager') { continue; }
    $method = new ReflectionMethod($component, 'displayTable');
    foreach (PerfProbe::$tables as $kind => $table) {
        PerfProbe::measure('REPLAY formatting '.$kind, fn () => $method->invoke($component, $table, $kind, 'MAD'));
    }
}
$duplicates = [];
foreach ($requestQueries as $query) {
    $key = json_encode([$query['sql'], $query['bindings']]);
    $duplicates[$key] = ($duplicates[$key] ?? 0) + 1;
}
$result = [
    'uri' => $uri, 'debugbar' => $debug, 'debugbar_env' => $debugEnv, 'view_cache' => $cacheMode, 'status' => $response->getStatusCode(),
    'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'opcache_cli' => ini_get('opcache.enable_cli'),
    'bootstrap_and_setup_ms' => $bootMs, 'total_ms' => $totalMs,
    'query_count' => count($requestQueries), 'sql_ms' => array_sum(array_column($requestQueries, 'ms')),
    'duplicate_excess' => array_sum(array_map(fn ($n) => max(0, $n - 1), $duplicates)),
    'super_admin_queries' => count(array_filter($requestQueries, fn ($q) => str_contains($q['sql'], 'exists') && in_array('super-admin', $q['bindings'], true))),
    'spans' => $requestSpans, 'formatting_replay' => array_slice(PerfProbe::$spans, count($requestSpans)),
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
