<?php

declare(strict_types=1);

namespace Nexia\Devtools\Commands\Concerns;

use Illuminate\Support\Str;
use Nexia\Contribution\ResourceRecordOwner;

/**
 * Shared scaffolding behaviour for the resource generators
 * (`nexia-resources:make-resource` and `nexia-apps:make-package-resource`).
 *
 * The trait covers the parts of the generators that were drifting
 * between commands before T4.2:
 *
 *   - `buildStandardReplacements` mints the canonical placeholder set
 *     so a single rule ("PascalCase placeholder for the studly form,
 *     case-suffixed placeholder for every other case shape") applies
 *     to every stub, no matter which generator emits it.
 *   - `resolveExistingMigrationOr` deduplicates the migration-path
 *     idempotency logic that used to live in each generator
 *     verbatim. Re-runs reuse an existing
 *     `*_create_{table}_table.php` instead of stamping a fresh
 *     timestamp.
 *   - `processGeneratedFiles` runs the unified write loop with
 *     consistent SKIP / WOULD / CREATE / REWRITE labels, so dry-run
 *     and real-run output across all three generators reads the same
 *     way.
 *
 * The trait is **internal scaffolding plumbing** — it does not
 * reach into Artisan input, output formatting beyond the labels
 * above, or any Laravel feature beyond `Illuminate\Support\Str`.
 *
 * @see docs/doctrine/04-BOUNDARIES.md#resources — Resource Shape Boundary
 */
trait ScaffoldsResource
{
    use InteractsWithScaffoldWizard;

    protected function localizedLabelsNeedInteractiveInput(): bool
    {
        $configured = config('nexia.resource_scaffolding.locale_labels.ko', []);
        $configured = is_array($configured) ? $configured : [];

        return trim((string) ($this->option('label-ko') ?: ($configured['singular'] ?? ''))) === '';
    }

    /**
     * Resolve the authored non-fallback labels used by resource locale stubs.
     *
     * English labels are derived deterministically from the resource name.
     * Korean labels are domain copy, so a singular label is required. Korean
     * collection labels default to the singular label because Korean has no
     * general English-style plural inflection. Chinese labels are optional;
     * when omitted, the English resource name is used as an explicit fallback
     * in the otherwise Chinese catalog.
     *
     * @return array{en: array{singular:string, plural:string}, ko: array{singular:string, plural:string}, zh: array{singular:string, plural:string}}|null
     */
    protected function resolveLocalizedResourceLabels(
        string $resourceName,
        string $resourcePlural,
    ): ?array {
        $labels = [
            'en' => [
                'singular' => Str::of($resourceName)->headline()->value(),
                'plural' => Str::of(Str::studly($resourcePlural))->headline()->value(),
            ],
        ];
        $koConfigured = config('nexia.resource_scaffolding.locale_labels.ko', []);
        $koConfigured = is_array($koConfigured) ? $koConfigured : [];
        $koSingular = trim((string) ($this->option('label-ko') ?: ($koConfigured['singular'] ?? '')));

        if ($koSingular === '') {
            if (! $this->input->isInteractive()) {
                $this->error('A Korean resource label is required. Re-run with --label-ko=<label>.');

                return null;
            }

            $this->beginScaffoldWizard();
            $koSingular = trim((string) $this->ask('Korean label'));
        }

        if ($koSingular === '') {
            $this->error('Korean label is required.');

            return null;
        }

        $koPlural = trim((string) ($this->option('label-ko-plural') ?: ($koConfigured['plural'] ?? '')));
        $koPlural = $this->scaffoldText('Korean list/menu label', $koPlural, $koSingular);
        if ($koPlural === '') {
            $koPlural = $koSingular;
        }

        $zhConfigured = config('nexia.resource_scaffolding.locale_labels.zh', []);
        $zhConfigured = is_array($zhConfigured) ? $zhConfigured : [];
        $zhSingular = trim((string) ($this->option('label-zh') ?: ($zhConfigured['singular'] ?? '')));
        $zhPlural = trim((string) ($this->option('label-zh-plural') ?: ($zhConfigured['plural'] ?? '')));

        if ($zhSingular === '' && $this->scaffoldWizardIsActive()
            && $this->confirm('Add Chinese labels now?', false)) {
            $zhSingular = trim((string) $this->ask('Chinese label'));
            $zhPlural = $this->scaffoldText('Chinese list/menu label', $zhPlural, $zhSingular);
        }

        if ($zhSingular === '') {
            $zhSingular = $labels['en']['singular'];
        }

        if ($zhPlural === '') {
            $zhPlural = $zhSingular === $labels['en']['singular']
                ? $labels['en']['plural']
                : $zhSingular;
        }

        $labels['ko'] = ['singular' => $koSingular, 'plural' => $koPlural];
        $labels['zh'] = ['singular' => $zhSingular, 'plural' => $zhPlural];

        $allowlist = config('nexia.translation_catalog.validation.identical_value_allowlist', []);
        $allowlist = is_array($allowlist)
            ? array_values(array_filter($allowlist, 'is_string'))
            : [];

        foreach (['ko'] as $locale) {
            foreach (['singular', 'plural'] as $form) {
                $value = $labels[$locale][$form];

                if ($value === $labels['en'][$form] && ! in_array($value, $allowlist, true)) {
                    $this->error(sprintf(
                        'Localized resource label --label-%s%s must not copy the English %s label [%s].',
                        $locale,
                        $form === 'plural' ? '-plural' : '',
                        $form,
                        $value,
                    ));

                    return null;
                }
            }
        }

        return $labels;
    }

    /**
     * Override legacy resource placeholders per locale without duplicating
     * complete stub suites solely for label casing.
     *
     * @param  array{en: array{singular:string, plural:string}, ko: array{singular:string, plural:string}, zh: array{singular:string, plural:string}}  $labels
     * @return array<string, array<string, string>>
     */
    protected function localizedResourceTranslationReplacements(array $labels): array
    {
        $replacements = [];

        foreach ($labels as $locale => $localeLabels) {
            $replacements[$locale] = [
                '{{ resourceLabel }}' => $localeLabels['singular'],
                '{{ resourcePlural }}' => $localeLabels['plural'],
                '{{ ResourceName }}' => $localeLabels['singular'],
                '{{ ResourcePluralStudly }}' => $localeLabels['plural'],
                '{{ resourceTitle }}' => $localeLabels['singular'],
                '{{ resourcePluralTitle }}' => $localeLabels['plural'],
            ];
        }

        return $replacements;
    }

    protected function resolveRecordOwner(mixed $value): ?ResourceRecordOwner
    {
        $owner = ResourceRecordOwner::tryFrom((string) $value);

        if ($owner === null) {
            $this->error('Unknown --record-owner value. Supported values: tenant, legal_entity.');
        }

        return $owner;
    }

    /**
     * Render a deterministic PostgreSQL-safe migration index declaration.
     *
     * PostgreSQL limits identifiers to 63 bytes. Resource table names are
     * derived from product names, so rely neither on Laravel's generated name
     * nor on a later database-side truncation that could collide.
     *
     * @param  list<string>  $columns
     */
    protected function migrationIndex(string $table, array $columns): string
    {
        $baseName = implode('_', [Str::snake($table), ...$columns, 'idx']);
        $indexName = strlen($baseName) <= 63
            ? $baseName
            : substr($baseName, 0, 54).'_'.substr(hash('sha256', $baseName), 0, 8);
        $columnList = implode(', ', array_map(
            static fn (string $column): string => "'{$column}'",
            $columns,
        ));

        return "            \$table->index([{$columnList}], '{$indexName}');";
    }

    /**
     * @param  list<list<string>>  $definitions
     */
    protected function migrationIndexes(string $table, array $definitions): string
    {
        return implode("\n", array_map(
            fn (array $columns): string => $this->migrationIndex($table, $columns),
            $definitions,
        ));
    }

    protected function recordOwnerChangeIsSafe(
        string $contributionPath,
        ResourceRecordOwner $requestedOwner,
    ): bool {
        if (! file_exists($contributionPath)) {
            return true;
        }

        $source = (string) file_get_contents($contributionPath);

        if (! preg_match('/ResourceRecordOwner::(Tenant|LegalEntity)/', $source, $matches)) {
            $this->error('Refusing to reinterpret an existing Resource that has no record-ownership declaration.');
            $this->line('Add the declaration and any required persistence/data migration explicitly before regenerating this Resource.');

            return false;
        }

        $existingOwner = match ($matches[1]) {
            'Tenant' => ResourceRecordOwner::Tenant->value,
            'LegalEntity' => ResourceRecordOwner::LegalEntity->value,
        };

        if ($existingOwner === $requestedOwner->value) {
            return true;
        }

        $this->error(sprintf(
            'Refusing to change record ownership from %s to %s through scaffold overwrite.',
            $existingOwner,
            $requestedOwner->value,
        ));
        $this->line('Create and apply an explicit persistence/data migration, then update the Resource contribution declaration and enforcement paths together.');

        return false;
    }

    /**
     * Mint the canonical placeholder set for resource stubs.
     *
     * Convention: PascalCase placeholders (e.g. `{{ ResourceName }}`,
     * `{{ ResourcePluralStudly }}`) carry the studly form; lowercase /
     * camelCase / kebab-case / snake_case variants are case-suffixed
     * after the lowercase root (e.g. `{{ resourceCamel }}`,
     * `{{ resourceKebab }}`, `{{ resourceLower }}`).
     *
     * Generators that need additional placeholders (package
     * namespace, app shorthand, permission prefix, morph alias)
     * pass them in via `$extra`. Existing keys in `$extra` win over
     * the standard set, but the standard keys are always present.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    protected function buildStandardReplacements(
        string $resourceName,
        ?string $resourcePlural = null,
        array $extra = [],
    ): array {
        $resourceName = Str::studly($resourceName);
        $resourceLower = Str::snake($resourceName);
        $resourceCamel = Str::camel($resourceName);
        $resourceKebab = Str::kebab($resourceName);
        $resourceLabel = Str::snake($resourceName, ' ');

        $plural = $resourcePlural ?? Str::plural($resourceLower);
        $resourcePluralCamel = Str::camel($plural);
        $resourcePluralKebab = str_replace('_', '-', $plural);
        $resourcePluralStudly = Str::studly($plural);

        $standard = [
            '{{ ResourceName }}' => $resourceName,
            '{{ resourceLower }}' => $resourceLower,
            '{{ resourceCamel }}' => $resourceCamel,
            '{{ resourceKebab }}' => $resourceKebab,
            '{{ resourceLabel }}' => $resourceLabel,
            '{{ resourcePlural }}' => $plural,
            '{{ resourcePluralCamel }}' => $resourcePluralCamel,
            '{{ resourcePluralKebab }}' => $resourcePluralKebab,
            '{{ ResourcePluralStudly }}' => $resourcePluralStudly,
            '{{ tableName }}' => $plural,
        ];

        return array_merge($standard, $extra);
    }

    /**
     * Resolve the migration target path for the given table.
     *
     * Migrations live with a timestamp prefix
     * (`{Y_m_d_His}_create_{table}_table.php`). Plain timestamp-based
     * generation would silently emit a fresh filename on every
     * invocation, making dry-run report `WOULD` even when an
     * equivalent migration already exists. The lookup glob picks the
     * lexicographically first match (earliest timestamp) when one
     * exists, so re-running the generator preserves the existing migration,
     * including with `--force`, rather than stamping a
     * second `Schema::create('{table}')` migration that would only
     * fail at migrate time.
     */
    protected function resolveExistingMigrationOr(
        string $directory,
        string $tableName,
        string $timestamp,
    ): string {
        $matches = glob("{$directory}/*_create_{$tableName}_table.php") ?: [];

        if ($matches !== []) {
            sort($matches);

            return $matches[0];
        }

        return "{$directory}/{$timestamp}_create_{$tableName}_table.php";
    }

    /**
     * Strip `base_path()` from an absolute path so output reads as a
     * repository-relative path.
     */
    protected function relativePath(string $absolute): string
    {
        return str_replace(base_path().'/', '', $absolute);
    }

    /**
     * Run the standard scaffolding write loop over a stub → target
     * map.
     *
     * For each pair the loop emits one of:
     *   - SKIP     — target exists and `$force` is false
     *   - WOULD    — `$dryRun` is true (with WOULD OVERWRITE for
     *                pre-existing files)
     *   - CREATE   — target written for the first time
     *   - REWRITE  — target overwritten because `$force` is true
     *
     * Returns `false` if any stub is missing on disk so the calling
     * command can return `FAILURE`.
     *
     * @param  array<string, string>  $files  stub absolute path → target absolute path
     * @param  array<string, string>  $replacements  placeholder → value
     */
    protected function processGeneratedFiles(
        array $files,
        array $replacements,
        bool $dryRun,
        bool $force,
    ): bool {
        foreach ($files as $stub => $target) {
            $relativePath = $this->relativePath($target);

            if (! file_exists($stub)) {
                $this->error("  Stub not found: {$stub}");

                return false;
            }

            $exists = file_exists($target);

            if ($exists && str_contains(str_replace('\\', '/', $target), '/database/migrations/')) {
                $this->warn("  SKIP    {$relativePath} (migration history is preserved; add a new migration for schema changes)");

                continue;
            }

            if ($exists && ! $force) {
                $this->warn("  SKIP    {$relativePath} (already exists)");

                continue;
            }

            if ($dryRun) {
                $verb = $exists ? 'WOULD OVERWRITE' : 'WOULD';
                $this->line("  {$verb}   {$relativePath}");

                continue;
            }

            $dir = dirname($target);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $content = file_get_contents($stub);
            foreach ($replacements as $placeholder => $value) {
                $content = str_replace($placeholder, $value, $content);
            }

            file_put_contents($target, $content);
            $this->line(($exists ? '  REWRITE' : '  CREATE ').' '.$relativePath);
        }

        return true;
    }

    protected function printPackageNextSteps(string $appKey, string $exampleResource = 'Invoice'): void
    {
        $this->line('Review the generated domain fields, permissions, migrations and translations.');
        $this->line('Run nexia dev from this App or its connected project.');
        $this->line('Installation and required initialization are managed by the platform.');
    }
}
