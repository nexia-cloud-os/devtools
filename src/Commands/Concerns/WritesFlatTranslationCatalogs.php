<?php

declare(strict_types=1);

namespace Nexia\Devtools\Commands\Concerns;

use JsonException;
use RuntimeException;
use Throwable;

trait WritesFlatTranslationCatalogs
{
    /**
     * @param  array<string, string>  $localeStubs
     * @param  array<string, string>  $replacements
     * @param  array<string, array<string, string>>  $localeReplacements
     */
    private function mergeTranslationCatalogStubs(
        string $catalogDirectory,
        array $localeStubs,
        array $replacements,
        string $label,
        bool $dryRun,
        bool $force = false,
        array $localeReplacements = [],
    ): bool {
        $planned = [];

        foreach ($localeStubs as $locale => $stub) {
            if (! is_file($stub)) {
                $this->error("  Stub not found: {$stub}");

                return false;
            }

            try {
                $incoming = $this->decodeFlatTranslationCatalog(
                    $this->renderTranslationCatalogStub(
                        $stub,
                        array_replace($replacements, $localeReplacements[$locale] ?? []),
                    ),
                    $stub,
                );
                $target = "{$catalogDirectory}/{$locale}.json";
                $current = is_file($target)
                    ? $this->decodeFlatTranslationCatalog((string) file_get_contents($target), $target)
                    : [];
            } catch (JsonException|RuntimeException $exception) {
                $this->error("  Invalid translation catalog: {$exception->getMessage()}");

                return false;
            }

            $conflicts = [];
            foreach ($incoming as $key => $value) {
                if (array_key_exists($key, $current) && $current[$key] !== $value) {
                    $conflicts[] = $key;
                }
            }

            if ($conflicts !== [] && ! $force) {
                sort($conflicts, SORT_STRING);
                $this->error(sprintf(
                    '  CONFLICT %s (duplicate exact translation key(s): %s)',
                    $this->translationCatalogRelativePath($target),
                    implode(', ', $conflicts),
                ));
                $this->line('  hint: reconcile the existing values or re-run with --force to replace only those exact keys.');

                return false;
            }

            $merged = array_replace($current, $incoming);
            ksort($merged, SORT_STRING);
            $planned[$locale] = [
                'target' => $target,
                'catalog' => $merged,
                'changed' => $merged !== $current,
                'existed' => is_file($target),
                'conflicts' => $conflicts,
            ];
        }

        foreach ($planned as $locale => $plan) {
            $target = $plan['target'];
            $relativePath = $this->translationCatalogRelativePath($target);

            if (! $plan['changed']) {
                $this->warn("  SKIP    {$relativePath} ({$label} already present)");

                continue;
            }

            if ($dryRun) {
                $verb = $plan['existed'] ? 'WOULD UPDATE' : 'WOULD';
                $this->line(sprintf('  %-12s %s (%s, %s)', $verb, $relativePath, $label, $locale));

                continue;
            }

            try {
                $this->writeFlatTranslationCatalogAtomically($target, $plan['catalog']);
            } catch (JsonException|RuntimeException $exception) {
                $this->error("  Could not write translation catalog: {$exception->getMessage()}");

                return false;
            }

            foreach ($plan['conflicts'] as $key) {
                $this->warn("  REPLACE duplicate exact translation key {$key} ({$relativePath})");
            }

            $verb = $plan['existed'] ? 'UPDATE' : 'CREATE';
            $this->line(sprintf('  %-7s %s (%s, %s)', $verb, $relativePath, $label, $locale));
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function decodeFlatTranslationCatalog(string $json, string $source): array
    {
        preg_match_all('/(?:\{|,)\s*"((?:\\\\.|[^"\\\\])*)"\s*:/s', $json, $matches);
        $seenKeys = [];
        foreach ($matches[1] as $encodedKey) {
            $key = json_decode('"'.$encodedKey.'"', true, flags: JSON_THROW_ON_ERROR);
            if (! is_string($key)) {
                throw new RuntimeException("{$source} contains an invalid JSON object key.");
            }

            if (isset($seenKeys[$key])) {
                throw new RuntimeException("{$source} contains duplicate exact translation key '{$key}'.");
            }

            $seenKeys[$key] = true;
        }

        $catalog = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($catalog) || ($catalog !== [] && array_is_list($catalog))) {
            throw new RuntimeException("{$source} must contain one flat JSON object.");
        }

        foreach ($catalog as $key => $value) {
            if (! is_string($key)
                || ! str_contains($key, '.')
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $key) !== 1
                || str_ends_with($key, '.')
                || str_contains($key, '..')) {
                throw new RuntimeException("{$source} contains invalid exact dotted key '{$key}'.");
            }

            if (! str_starts_with($key, 'marketing.')) {
                foreach (explode('.', $key) as $segment) {
                    if (preg_match('/^[A-Z][A-Z0-9_]*$/', $segment) === 1) {
                        continue;
                    }

                    if (preg_match('/[A-Z]/', $segment) === 1) {
                        throw new RuntimeException(
                            "{$source} key '{$key}' must use snake_case owned-path segments.",
                        );
                    }
                }
            }

            if (! is_string($value)) {
                throw new RuntimeException("{$source} value for '{$key}' must be a string.");
            }
        }

        return $catalog;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function renderTranslationCatalogStub(string $stub, array $replacements): string
    {
        $content = (string) file_get_contents($stub);

        foreach ($replacements as $placeholder => $value) {
            $content = str_replace($placeholder, $value, $content);
        }

        return $content;
    }

    /**
     * @param  array<string, string>  $catalog
     */
    private function writeFlatTranslationCatalogAtomically(string $target, array $catalog): void
    {
        $directory = dirname($target);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}.");
        }

        $temporary = tempnam($directory, '.translations-');
        if ($temporary === false) {
            throw new RuntimeException("Could not create a temporary file in {$directory}.");
        }

        try {
            $json = json_encode(
                $catalog,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ).PHP_EOL;

            if (file_put_contents($temporary, $json, LOCK_EX) === false || ! rename($temporary, $target)) {
                throw new RuntimeException("Could not atomically replace {$target}.");
            }

            chmod($target, 0644);
        } catch (Throwable $exception) {
            if ($exception instanceof JsonException || $exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException($exception->getMessage(), previous: $exception);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function translationCatalogRelativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
