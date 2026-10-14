<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\Compilers\Compiler;
use Tests\TestCase;

class AppWarmupTest extends TestCase
{
    public function test_warmup_prepares_runtime_paths_without_authentication_or_sql_and_is_idempotent(): void
    {
        $directory = storage_path('framework/testing-warmup-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        config(['view.compiled' => $directory]);
        $compiler = app('blade.compiler');
        (new \ReflectionProperty(Compiler::class, 'cachePath'))->setValue($compiler, $directory);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        try {
            $this->assertGuest();
            $this->artisan('app:warmup')->assertSuccessful();
            foreach (['admin.reports.index', 'admin.sales-orders.index', 'admin.cash.index', 'admin.suppliers.index',
                'layouts.app', 'components.sidebar', 'components.page-context', 'components.report-period',
                'volt-livewire::admin.reports-manager', 'volt-livewire::admin.sales-orders-manager',
                'volt-livewire::admin.cash-manager', 'volt-livewire::admin.suppliers-manager',
                'volt-livewire::layout.navigation', 'volt-livewire::notification-bell', 'livewire::tailwind'] as $name) {
                $source = app('view')->getFinder()->find($name);
                $target = $compiler->getCompiledPath($source);
                $this->assertFileExists($target, $name);
                $this->assertFalse($compiler->isExpired($source), $name);
            }
            $before = [];
            foreach (glob($directory.'/*.php') as $file) {
                $before[$file] = [filemtime($file), hash_file('sha256', $file)];
            }
            $this->artisan('app:warmup')->assertSuccessful();
            foreach ($before as $file => $state) {
                $this->assertSame($state, [filemtime($file), hash_file('sha256', $file)]);
            }
            $this->assertSame([], $queries);
            $this->assertNull(Auth::id());
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function test_deployment_caches_are_not_enabled_in_development(): void
    {
        $this->artisan('app:warmup', ['--deployment' => true])->assertFailed();
    }

    public function test_php_compilation_refuses_an_inactive_cli_opcache(): void
    {
        if (function_exists('opcache_get_status') && opcache_get_status(false) !== false) {
            $this->markTestSkipped('This check requires disabled CLI OPcache.');
        }
        $this->artisan('app:warmup', ['--php' => true])->assertFailed();
    }
}
