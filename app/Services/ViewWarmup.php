<?php

namespace App\Services;

use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory;
use Symfony\Component\Finder\Finder;

class ViewWarmup
{
    public function __construct(private Factory $views, private BladeCompiler $compiler) {}

    /** Compile using the exact paths returned at runtime, without rendering views. */
    public function prepare(): array
    {
        $finder = $this->views->getFinder();
        $groups = ['' => $finder->getPaths(), ...$finder->getHints()];
        $compiled = [];
        foreach ($groups as $namespace => $directories) {
            foreach ($directories as $directory) {
                if (! is_dir($directory)) {
                    continue;
                }
                foreach ((new Finder)->files()->in($directory)->name('*.blade.php')->sortByName() as $file) {
                    $relative = substr($file->getRelativePathname(), 0, -strlen('.blade.php'));
                    $name = ($namespace === '' ? '' : $namespace.'::').str_replace(['/', '\\'], '.', $relative);
                    $source = $finder->find($name);
                    $target = $this->compiler->getCompiledPath($source);
                    if (isset($compiled[$target])) {
                        continue;
                    }
                    if ($this->compiler->isExpired($source)) {
                        $this->compiler->compile($source);
                    }
                    // Read generated PHP to warm file access; no include/eval/render.
                    $contents = file_get_contents($target);
                    if ($contents === false) {
                        throw new \RuntimeException('Unable to read compiled view: '.$name);
                    }
                    $compiled[$target] = ['name' => $name, 'source' => $source, 'file' => $target];
                }
            }
        }

        return array_values($compiled);
    }
}
