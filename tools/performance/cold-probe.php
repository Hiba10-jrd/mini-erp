<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\Compilers\Compiler;
use Illuminate\View\Compilers\CompilerInterface;
use Illuminate\View\Engines\CompilerEngine;
use Livewire\Volt\ComponentFactory;
use Livewire\Volt\MountedDirectories;

// Diagnostic only: normal authorization, existing local user, in-memory session.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$start = hrtime(true);
putenv('DEBUGBAR_ENABLED=false');
$_ENV['DEBUGBAR_ENABLED'] = $_SERVER['DEBUGBAR_ENABLED'] = 'false';
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->instance('request', Request::create('http://mini-erp.test'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('local')) {
    throw new RuntimeException('Local only');
}
$route = $argv[1] ?? '/reports';
if (! in_array($route, ['/reports', '/sales/orders', '/finance/cash', '/administration/suppliers'], true)) {
    exit(1);
}
config(['session.driver' => 'array', 'debugbar.enabled' => false]);
app('debugbar')->disable();
$cache = config('view.compiled');
if (($argv[2] ?? '') === 'trace') {
    $identifier = $argv[4] ?? 'cold-trace';
    if (! preg_match('/^[a-zA-Z0-9_-]+$/', $identifier)) {
        exit(1);
    }
    $cache = storage_path('framework/performance-views/'.$identifier);
    if (! is_dir($cache)) {
        mkdir($cache, 0777, true);
    }
    config(['view.compiled' => $cache]);
    $compiler = app('blade.compiler');
    (new ReflectionProperty(Compiler::class, 'cachePath'))->setValue($compiler, $cache);
}
class ColdTrace
{
    public static array $starts = [];

    public static array $loads = [];

    public static array $compiles = [];

    public static array $volt = [];

    public static function entered(string $path): void
    {
        $path = str_replace('\\', '/', $path);
        if (isset(self::$starts[$path])) {
            self::$loads[] = ['file' => $path, 'ms' => (hrtime(true) - self::$starts[$path]) / 1e6];
        }
    }
}
$app->extend(ComponentFactory::class, function ($factory, $app) {
    return new class($app->make(MountedDirectories::class)) extends ComponentFactory
    {
        public function make(string $componentName, string $path): string
        {
            $start = hrtime(true);
            $class = parent::make($componentName, $path);
            ColdTrace::$volt[] = ['name' => $componentName, 'source' => $path, 'ms' => (hrtime(true) - $start) / 1e6];

            return $class;
        }
    };
});
$engine = app('view.engine.resolver')->resolve('blade');
$proxy = new class($engine->getCompiler(), ($argv[2] ?? '') === 'trace') implements CompilerInterface
{
    public function __construct(private object $inner, private bool $trace) {}

    public function getCompiledPath($path)
    {
        $compiled = $this->inner->getCompiledPath($path);
        ColdTrace::$starts[str_replace('\\', '/', $compiled)] = hrtime(true);

        return $compiled;
    }

    public function isExpired($path)
    {
        return $this->inner->isExpired($path);
    }

    public function compile($path)
    {
        $start = hrtime(true);
        $this->inner->compile($path);
        ColdTrace::$compiles[] = ['source' => $path, 'ms' => (hrtime(true) - $start) / 1e6];
        if ($this->trace) {
            $compiled = $this->inner->getCompiledPath($path);
            file_put_contents($compiled, '<?php ColdTrace::entered(__FILE__); ?>'.file_get_contents($compiled));
        }
    }

    public function __call($method, $arguments)
    {
        return $this->inner->$method(...$arguments);
    }
};
(new ReflectionProperty(CompilerEngine::class, 'compiler'))->setValue($engine, $proxy);
$before = array_map('realpath', glob($cache.'/*') ?: []);
$user = User::findOrFail((int) ($argv[3] ?? 1));
Auth::guard('web')->setUser($user);
$queries = [];
DB::listen(function ($query) use (&$queries) {
    $queries[] = $query->time;
});
$request = Request::create(rtrim(config('app.url'), '/').$route);
$request->headers->set('Accept', 'text/html');
$handleStart = hrtime(true);
$response = $kernel->handle($request);
$kernelMs = (hrtime(true) - $handleStart) / 1e6;
$totalMs = (hrtime(true) - $start) / 1e6;
$after = array_map('realpath', glob($cache.'/*') ?: []);
$included = array_values(array_filter(get_included_files(), fn ($file) => str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $cache).'/')));
$files = [];
foreach (array_unique([...array_diff($after, $before), ...$included]) as $file) {
    $contents = file_get_contents($file);
    preg_match('~/\*\*PATH (.*?) ENDPATH\*\*/~s', $contents, $matches);
    $files[] = ['file' => $file, 'source' => $matches[1] ?? null, 'bytes' => filesize($file),
        'mtime' => filemtime($file), 'created' => ! in_array($file, $before, true),
        'included' => in_array($file, $included, true)];
}
echo json_encode(['route' => $route, 'status' => $response->getStatusCode(), 'sapi' => PHP_SAPI,
    'debugbar' => app('debugbar')->isEnabled(), 'cache' => $cache, 'kernel_ms' => $kernelMs,
    'total_ms' => $totalMs, 'sql_ms' => array_sum($queries), 'queries' => count($queries),
    'created_count' => count(array_diff($after, $before)), 'compile_ms' => array_sum(array_column(ColdTrace::$compiles, 'ms')),
    'compiles' => ColdTrace::$compiles, 'loads' => ColdTrace::$loads, 'volt' => ColdTrace::$volt, 'files' => $files,
    'caches' => ['config' => $app->configurationIsCached(), 'routes' => $app->routesAreCached(), 'events' => $app->eventsAreCached()],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
