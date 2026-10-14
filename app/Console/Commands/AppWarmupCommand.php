<?php

namespace App\Console\Commands;

use App\Services\ViewWarmup;
use Illuminate\Console\Command;

class AppWarmupCommand extends Command
{
    protected $signature = 'app:warmup {--deployment : Cache config, routes and events (production only)}
        {--php : Compile generated PHP without executing it; requires active CLI OPcache}';

    protected $description = 'Prepare runtime Blade/Volt view paths without rendering protected pages';

    public function handle(ViewWarmup $warmup): int
    {
        if ($this->option('php') && (! function_exists('opcache_get_status') || opcache_get_status(false) === false)) {
            $this->error('--php requires an active CLI OPcache. Run php -d opcache.enable_cli=1 artisan app:warmup --php.');

            return self::FAILURE;
        }
        if ($this->option('deployment')) {
            if (! app()->environment('production')) {
                $this->error('--deployment is reserved for production; use app:warmup locally.');

                return self::FAILURE;
            }
            foreach (['config:cache', 'route:cache', 'event:cache'] as $command) {
                if ($this->call($command) !== self::SUCCESS) {
                    return self::FAILURE;
                }
            }
        }
        $start = hrtime(true);
        $views = $warmup->prepare();
        if ($this->option('php')) {
            foreach ($views as $view) {
                if (! opcache_compile_file($view['file'])) {
                    $this->error('PHP compilation failed: '.$view['name']);

                    return self::FAILURE;
                }
            }
        }
        $this->info(sprintf('%d runtime view paths prepared in %.0f ms.', count($views), (hrtime(true) - $start) / 1e6));
        $this->line('No routes rendered, no authentication, no business data or persistent sessions created.');
        $this->line('CLI preparation does not populate the web worker OPcache.');

        return self::SUCCESS;
    }
}
