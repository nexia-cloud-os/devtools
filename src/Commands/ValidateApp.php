<?php

declare(strict_types=1);

namespace Nexia\Devtools\Commands;

use Illuminate\Console\Command;
use Nexia\AppRuntime\AppPackageMetadataReader;
use Nexia\Devtools\Commands\Concerns\WritesFlatTranslationCatalogs;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/** Local source checks only: never boot an App or execute its Composer scripts. */
final class ValidateApp extends Command
{
    use WritesFlatTranslationCatalogs;

    protected $signature = 'validate {directory=. : App source directory}';

    protected $description = 'Validate App metadata and PHP syntax without executing App code';

    public function handle(AppPackageMetadataReader $reader): int
    {
        try {
            $root = realpath((string) $this->argument('directory'));
            if ($root === false || ! is_file($root.'/composer.json') || is_link($root.'/composer.json')) {
                throw new RuntimeException('App composer.json is unavailable.');
            }
            $definition = $reader->read($root);
            $composer = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
            if (! is_string($composer['name'] ?? null)
                || ! preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/D', $composer['name'])) {
                throw new RuntimeException('Composer package name must use vendor/package.');
            }
            $namespaces = $composer['autoload']['psr-4'] ?? null;
            if (! is_array($namespaces) || $namespaces === []) {
                throw new RuntimeException('Declare the App PSR-4 source namespace.');
            }
            $paths = [];
            $manifestFound = false;
            foreach ($namespaces as $namespace => $directories) {
                if (! is_string($namespace) || ! str_ends_with($namespace, '\\')) {
                    throw new RuntimeException('PSR-4 namespaces must end with a backslash.');
                }
                foreach ((array) $directories as $directory) {
                    $path = is_string($directory) ? realpath($root.'/'.$directory) : false;
                    if ($path === false || ! is_dir($path) || ! str_starts_with($path, $root.'/')) {
                        throw new RuntimeException('PSR-4 source directories must exist inside this App.');
                    }
                    $paths[] = $path;
                    if (str_starts_with($definition->manifestClass, $namespace)) {
                        $relative = str_replace('\\', '/', substr($definition->manifestClass, strlen($namespace))).'.php';
                        $manifestFound = $manifestFound || is_file($path.'/'.$relative);
                    }
                }
            }
            if (! $manifestFound) {
                throw new RuntimeException('Manifest file does not match the declared PSR-4 mapping.');
            }
            foreach (['routes', 'database/migrations', 'tests'] as $directory) {
                if (is_dir($root.'/'.$directory)) {
                    $path = realpath($root.'/'.$directory);
                    if (is_link($root.'/'.$directory) || $path === false || ! str_starts_with($path, $root.'/')) {
                        throw new RuntimeException('Source directories must remain inside this App: '.$directory);
                    }
                    $paths[] = $path;
                }
            }
            $checked = [];
            foreach (array_unique($paths) as $path) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
                    if ($file->isLink()) {
                        throw new RuntimeException('Source checks reject symlinks: '.substr($file->getPathname(), strlen($root) + 1));
                    }
                    if (! $file->isFile() || $file->getExtension() !== 'php' || isset($checked[$file->getPathname()])) {
                        continue;
                    }
                    try {
                        token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
                    } catch (\ParseError) {
                        throw new RuntimeException('Invalid PHP syntax: '.substr($file->getPathname(), strlen($root) + 1));
                    }
                    $checked[$file->getPathname()] = true;
                }
            }
            $catalogs = glob($root.'/resources/lang/*.json') ?: [];
            foreach ($catalogs as $file) {
                $resolved = realpath($file);
                if (is_link($file) || $resolved === false || ! str_starts_with($resolved, $root.'/')) {
                    throw new RuntimeException('Translation catalogs must remain inside this App.');
                }
                $this->decodeFlatTranslationCatalog(file_get_contents($file), $file);
            }
            $this->info("{$definition->appKey}: metadata and ".count($checked).' PHP source files are valid.');
            $this->line(count($catalogs).' flat translation catalogs are valid.');
            $this->line('Local source checks only. Dependencies, permissions, migrations and remote runtime behavior require platform validation.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
