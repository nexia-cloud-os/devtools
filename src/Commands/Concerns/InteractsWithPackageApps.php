<?php

declare(strict_types=1);

namespace Nexia\Devtools\Commands\Concerns;

use JsonException;
use Nexia\AppRuntime\AppDefinition;
use Nexia\AppRuntime\AppPackageMetadataReader;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

trait InteractsWithPackageApps
{
    /** Plan the menu edit before writing any generated file; keep existing custom placement. */
    protected function plannedNavigation(string $directory, array $entry, bool $without): ?string
    {
        $reader = new AppPackageMetadataReader;
        $declaration = $reader->declaration($directory);
        if (! array_key_exists('navigation', $declaration)) {
            return null;
        }
        $existing = array_column($declaration['navigation'], null, 'screen');
        if (isset($existing[$entry['screen']]) && ! $without) {
            return null;
        }
        if ($without) {
            unset($existing[$entry['screen']]);
        } else {
            $existing[$entry['screen']] = $entry;
        }
        $declaration['navigation'] = array_values($existing);
        \Nexia\Navigation\AppNavigation::validate($declaration['navigation'], $declaration['app']['app_key']);

        return json_encode($declaration, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    protected function resolvePackageApp(string $input, AppPackageMetadataReader $reader): ?array
    {
        $directory = realpath($input);
        if ($directory === false || ! is_file($directory.'/composer.json')) {
            return null;
        }

        // Check every generator-owned source tree before any read or write.
        // Installed dependencies and Git internals are not generator targets.
        foreach (['composer.json', 'src', 'routes', 'resources', 'database', 'tests', 'docs'] as $relative) {
            $path = $directory.'/'.$relative;
            if (is_link($path)) {
                throw new RuntimeException('App generation rejects symbolic links: '.$relative);
            }
            if (! is_dir($path)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('App generation rejects symbolic links: '.substr($file->getPathname(), strlen($directory) + 1));
                }
            }
        }

        return ['directory' => $directory, 'definition' => $reader->read($directory)];
    }

    protected function packageLookupDirectory(string $input): string
    {
        return $input;
    }

    protected function manifestNamespace(AppDefinition $definition): string
    {
        $separator = strrpos($definition->manifestClass, '\\');

        if ($separator === false) {
            throw new RuntimeException(
                "App manifest [{$definition->manifestClass}] must declare a namespace.",
            );
        }

        return substr($definition->manifestClass, 0, $separator);
    }

    protected function manifestClassName(AppDefinition $definition): string
    {
        $separator = strrpos($definition->manifestClass, '\\');

        return $separator === false
            ? $definition->manifestClass
            : substr($definition->manifestClass, $separator + 1);
    }

    protected function resolvePackageComposerName(string $packageDir): ?string
    {
        $composer = $this->readComposerJson("{$packageDir}/composer.json");
        $name = $composer['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    protected function resolvePackageNamespace(string $packageDir): ?string
    {
        $composer = $this->readComposerJson("{$packageDir}/composer.json");
        $psr4 = $composer['autoload']['psr-4'] ?? null;

        if (! is_array($psr4) || $psr4 === []) {
            return null;
        }

        $namespace = array_key_first($psr4);

        return is_string($namespace) ? rtrim($namespace, '\\') : null;
    }

    protected function readComposerJson(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
