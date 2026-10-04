<?php

declare(strict_types=1);

namespace Nexia\Devtools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nexia\AppRuntime\AppDefinition;
use Nexia\AppRuntime\AppPackageMetadataReader;
use Nexia\Devtools\Commands\Concerns\InteractsWithPackageApps;
use Nexia\Devtools\Commands\Concerns\ScaffoldsResource;
use Nexia\Devtools\Commands\Concerns\WritesFlatTranslationCatalogs;
use Throwable;

class MakePackageApp extends Command
{
    use InteractsWithPackageApps;
    use ScaffoldsResource;
    use WritesFlatTranslationCatalogs;

    protected $signature = 'make:app
        {name? : The PHP class and namespace seed for the app}
        {--family= : App Family key in lower kebab-case}
        {--display-name= : Exact user-facing App Name; defaults to a headline made from name}
        {--key= : App key in lower kebab-case; defaults to kebab(name)}
        {--table-prefix= : Table prefix in lower snake_case; defaults to snake(key)}
        {--prerequisite=* : App key required before this app can be installed; repeatable}
        {--vendor= : Composer vendor / PHP namespace vendor}
        {--directory= : Destination directory; defaults to vendor/app-key}
        {--icon=box : Shell icon name for the App Launcher and app overview}
        {--sort=500 : App order inside its App Family and overview navigation order}
        {--description= : English catalog description; defaults to a generated sentence}
        {--readiness=available : Catalog readiness state (available, beta, or preview)}
        {--repository-files-only : For an existing App, create only standalone repository support files}
        {--dry-run : Show what files would be created without writing them}';

    protected $description = 'Scaffold an independent Nexia App with vendor-qualified identity';

    public function handle(): int
    {
        $appNameInput = $this->scaffoldRequiredArgument(
            'name',
            'App name (PascalCase)',
            'App name is required when running non-interactively. Re-run with an app name.',
        );
        if ($appNameInput === null) {
            return self::FAILURE;
        }

        $appName = Str::studly($appNameInput);
        $defaultAppTitle = Str::of($appName)->headline()->value();

        if ($appName === '' || $defaultAppTitle === '') {
            $this->error('App name must be a non-empty display name.');

            return self::FAILURE;
        }

        if ((bool) $this->option('repository-files-only')) {
            return $this->scaffoldRepositoryFiles($defaultAppTitle);
        }

        $appFamily = $this->scaffoldRequiredOption(
            'family',
            'App family (lower kebab-case)',
            'App family is required when running non-interactively. Re-run with --family=<family-key>.',
        );
        if ($appFamily === null) {
            return self::FAILURE;
        }

        $displayName = $this->scaffoldText(
            'Display name',
            trim((string) $this->option('display-name')),
            $defaultAppTitle,
        );
        $appTitle = $displayName !== '' ? $displayName : $defaultAppTitle;
        $appKey = $this->scaffoldText(
            'App key (lower kebab-case)',
            trim((string) $this->option('key')),
            Str::kebab($appName),
        );
        $appTablePrefix = $this->scaffoldText(
            'Table prefix (lower snake_case)',
            trim((string) $this->option('table-prefix')),
            Str::snake(str_replace('-', ' ', $appKey)),
        );

        $prerequisiteApps = $this->option('prerequisite');
        $prerequisiteApps = is_array($prerequisiteApps)
            ? array_values(array_map(static fn (mixed $key): string => trim((string) $key), $prerequisiteApps))
            : [];

        $prerequisiteInput = $this->scaffoldText(
            'Prerequisite app keys (comma-separated, optional)',
            implode(', ', array_filter($prerequisiteApps)),
        );
        $prerequisiteApps = $prerequisiteInput === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $prerequisiteInput))));

        $vendorInput = $this->scaffoldRequiredOption('vendor', 'Composer vendor', 'Use --vendor=<vendor>.');
        if ($vendorInput === null) {
            return self::FAILURE;
        }
        $vendorComposer = Str::of($vendorInput)->lower()->replace('\\', '-')->replace('_', '-')->replace(' ', '-')->value();
        $vendorNamespace = Str::of($vendorInput)->replace(['-', '_'], ' ')->studly()->replace(' ', '')->value();

        if (! preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $vendorComposer)
            || ! preg_match('/^[A-Z][A-Za-z0-9]*$/D', $vendorNamespace)
            || ! preg_match('/^[A-Z][A-Za-z0-9]*$/D', $appName)) {
            $this->error('Vendor and App name must produce valid package and PHP namespace segments.');

            return self::FAILURE;
        }

        $packageComposerName = "{$vendorComposer}/{$appKey}";
        $packageNpmName = "@{$vendorComposer}/{$appKey}";
        $packageDir = (string) $this->option('directory');
        $packageDir = $packageDir !== '' ? $packageDir : base_path("{$vendorComposer}/{$appKey}");
        $packageNamespace = "Nexia\\Apps\\{$vendorNamespace}\\{$appName}";
        $packageNamespaceJson = str_replace('\\', '\\\\', $packageNamespace);
        $manifestClass = "{$packageNamespace}\\{$appName}AppManifest";
        $appIcon = Str::of($this->scaffoldText(
            'Shell icon',
            trim((string) $this->option('icon')),
            'box',
        ))->trim()->value();
        $appSort = $this->parseSortOption('sort', $this->scaffoldText(
            'App order inside its family',
            trim((string) $this->option('sort')),
            '500',
        ));

        if ($appIcon === '') {
            $this->error('--icon must be a non-empty shell icon name.');

            return self::FAILURE;
        }

        if ($appSort === null) {
            return self::FAILURE;
        }

        $appDescription = $this->scaffoldText(
            'English description',
            trim((string) $this->option('description')),
            "{$appTitle} app for Nexia.",
        );
        $appReadiness = $this->scaffoldChoice(
            'Catalog readiness',
            [
                AppDefinition::READINESS_AVAILABLE,
                AppDefinition::READINESS_BETA,
                AppDefinition::READINESS_PREVIEW,
            ],
            trim((string) $this->option('readiness')),
            AppDefinition::READINESS_AVAILABLE,
        );

        try {
            $definition = new AppDefinition(
                manifestClass: $manifestClass,
                appFamily: $appFamily,
                appName: $appTitle,
                appKey: $appKey,
                appTablePrefix: $appTablePrefix,
                prerequisiteApps: $prerequisiteApps,
                descriptions: ['en' => $appDescription],
                readiness: $appReadiness,
                appIcon: $appIcon,
                launcherOrder: $appSort,
                overviewNavigationId: $appKey,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (file_exists($packageDir) || is_link($packageDir)) {
            $this->error("Package path [{$packageDir}] already exists. Choose a new App key; use --repository-files-only only to add repository support files.");

            return self::FAILURE;
        }

        $appPrefix = Str::studly($definition->appKey);

        $replacements = [
            '{{ vendorComposer }}' => $vendorComposer,
            '{{ vendorNamespace }}' => $vendorNamespace,
            '{{ packageComposerName }}' => $packageComposerName,
            '{{ packageNpmName }}' => $packageNpmName,
            '{{ packageNamespace }}' => $packageNamespace,
            '{{ packageNamespaceJson }}' => $packageNamespaceJson,
            '{{ packageDescriptionJson }}' => json_encode("{$appTitle} package for Nexia", JSON_THROW_ON_ERROR),
            '{{ appName }}' => $appName,
            '{{ appPrefix }}' => $appPrefix,
            '{{ appDisplayNameJson }}' => json_encode($definition->appName, JSON_THROW_ON_ERROR),
            '{{ appFamily }}' => $definition->appFamily,
            '{{ appIcon }}' => $definition->appIcon,
            '{{ appLauncherOrder }}' => (string) $definition->launcherOrder,
            '{{ appOverviewNavigationId }}' => $definition->overviewNavigationId,
            '{{ appKey }}' => $definition->appKey,
            '{{ appTablePrefix }}' => $definition->appTablePrefix,
            '{{ prerequisiteAppsJson }}' => json_encode($definition->prerequisiteApps, JSON_THROW_ON_ERROR),
            '{{ appDescriptionsJson }}' => json_encode($definition->descriptions, JSON_THROW_ON_ERROR),
            '{{ appReadiness }}' => $definition->readiness,
            '{{ appTitle }}' => $appTitle,
            '{{ appSort }}' => (string) $appSort,
        ];

        $stubDir = dirname(__DIR__, 2).'/stubs/package-app';
        $files = [
            "{$stubDir}/composer.stub" => "{$packageDir}/composer.json",
            "{$stubDir}/gitkeep.stub" => "{$packageDir}/database/migrations/tenant/.gitkeep",
            "{$stubDir}/package-json.stub" => "{$packageDir}/package.json",
            "{$stubDir}/vite-config.stub" => "{$packageDir}/vite.config.mjs",
            "{$stubDir}/dockerfile.stub" => "{$packageDir}/Dockerfile",
            "{$stubDir}/compose.stub" => "{$packageDir}/compose.yaml",
            "{$stubDir}/dockerignore.stub" => "{$packageDir}/.dockerignore",
            "{$stubDir}/agents.stub" => "{$packageDir}/AGENTS.md",
            "{$stubDir}/claude.stub" => "{$packageDir}/CLAUDE.md",
            "{$stubDir}/gitignore.stub" => "{$packageDir}/.gitignore",
            "{$stubDir}/ci-workflow.stub" => "{$packageDir}/.github/workflows/ci.yml",
            "{$stubDir}/empty-readme.stub" => "{$packageDir}/README.md",
            "{$stubDir}/pest.stub" => "{$packageDir}/tests/Pest.php",
            "{$stubDir}/phpunit.stub" => "{$packageDir}/phpunit.xml",
            "{$stubDir}/empty-app-manifest.stub" => "{$packageDir}/src/{$appName}AppManifest.php",
            "{$stubDir}/app-database.stub" => "{$packageDir}/src/Support/DB.php",
            "{$stubDir}/app-schema.stub" => "{$packageDir}/src/Support/Schema.php",
            "{$stubDir}/service-provider.stub" => "{$packageDir}/src/{$appName}ServiceProvider.php",
            "{$stubDir}/descriptor-slot-widgets.stub" => "{$packageDir}/src/Descriptors/{$appName}SlotWidgets.php",
            "{$stubDir}/empty-routes.stub" => "{$packageDir}/routes/routes.php",
            "{$stubDir}/empty-resources-js-index.stub" => "{$packageDir}/resources/js/index.ts",
            "{$stubDir}/empty-overview.stub" => "{$packageDir}/resources/js/overview/{$appPrefix}OverviewSurface.tsx",
            "{$stubDir}/docs-readme.stub" => "{$packageDir}/docs/README.md",
            "{$stubDir}/docs-domain-model.stub" => "{$packageDir}/docs/DOMAIN-MODEL.md",
            "{$stubDir}/docs-pages.stub" => "{$packageDir}/docs/PAGES.md",
        ];

        $dryRun = (bool) $this->option('dry-run');

        $this->info("Scaffolding package app: {$appTitle}");
        $this->line("  package:   {$packageComposerName}");
        $this->line("  namespace: {$packageNamespace}");
        $this->line("  family:    {$appFamily}");
        $this->line("  app key:   {$appKey}");
        $this->line("  table:     {$appTablePrefix}_*");
        $this->line("  launcher:  {$appIcon}, order {$appSort}");
        $this->line("  path:      {$packageDir}");
        $this->line("  readiness: {$appReadiness}");
        if ($prerequisiteApps !== []) {
            $this->line('  requires:  '.implode(', ', $prerequisiteApps));
        }
        $this->newLine();

        if (! $this->confirmScaffoldPlan('Create this package app?', $dryRun)) {
            return self::SUCCESS;
        }

        // MakePackageApp does not offer --force so existing files always
        // SKIP — pass `false` for the force flag through the shared loop.
        if (! $this->processGeneratedFiles($files, $replacements, $dryRun, false)) {
            return self::FAILURE;
        }

        if (! $this->mergeTranslationCatalogStubs(
            "{$packageDir}/resources/lang",
            [
                'en' => "{$stubDir}/empty-i18n-en.stub",
                'ko' => "{$stubDir}/empty-i18n-ko.stub",
                'zh' => "{$stubDir}/empty-i18n-zh.stub",
            ],
            $replacements,
            "{$appTitle} overview translations",
            $dryRun,
        )) {
            return self::FAILURE;
        }

        // Publish metadata last so direct generator users do not expose a partial App to project discovery.
        if (! $this->processGeneratedFiles([
            "{$stubDir}/nexia.stub" => "{$packageDir}/nexia.json",
        ], $replacements, $dryRun, false)) {
            return self::FAILURE;
        }

        $this->newLine();

        if ($dryRun) {
            $this->info('Dry run complete. No files were written.');

            return self::SUCCESS;
        }

        $this->info('Package app scaffolded.');
        $this->printPackageBaselineNextSteps($appKey);

        return self::SUCCESS;
    }

    private function parseSortOption(string $option, ?string $value = null): ?int
    {
        $raw = $value ?? (string) $this->option($option);

        if (! preg_match('/^-?\d+$/', $raw)) {
            $this->error("--{$option} must be an integer.");

            return null;
        }

        return (int) $raw;
    }

    private function scaffoldRepositoryFiles(string $appTitle): int
    {
        $appKey = trim((string) $this->option('key'));
        if ($appKey === '') {
            $appKey = Str::kebab($appTitle);
        }

        $packageDir = (string) $this->option('directory');
        if ($packageDir === '') {
            $this->error('Use --directory for an existing App.');

            return self::FAILURE;
        }
        $composerPath = "{$packageDir}/composer.json";

        if (! is_file($composerPath)) {
            $this->error("Package app not found at {$packageDir}/composer.json.");

            return self::FAILURE;
        }

        try {
            $definition = (new AppPackageMetadataReader)->read($packageDir);
            $composer = json_decode(
                (string) file_get_contents($composerPath),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $exception) {
            $this->error("Could not read package metadata: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $packageComposerName = is_array($composer) ? ($composer['name'] ?? null) : null;
        if ($definition->appKey !== $appKey
            || ! is_string($packageComposerName)
            || preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/', $packageComposerName) !== 1) {
            $this->error("Package app identity does not match [{$appKey}].");

            return self::FAILURE;
        }

        [$composerVendor, $composerPackage] = explode('/', $packageComposerName, 2);
        $replacements = [
            '{{ appKey }}' => $appKey,
            '{{ packageComposerName }}' => $packageComposerName,
            '{{ packageNpmName }}' => "@{$composerVendor}/{$composerPackage}",
        ];
        $stubDir = dirname(__DIR__, 2).'/stubs/package-app';
        $files = [
            "{$stubDir}/package-json.stub" => "{$packageDir}/package.json",
            "{$stubDir}/vite-config.stub" => "{$packageDir}/vite.config.mjs",
            "{$stubDir}/dockerfile.stub" => "{$packageDir}/Dockerfile",
            "{$stubDir}/compose.stub" => "{$packageDir}/compose.yaml",
            "{$stubDir}/dockerignore.stub" => "{$packageDir}/.dockerignore",
            "{$stubDir}/agents.stub" => "{$packageDir}/AGENTS.md",
            "{$stubDir}/claude.stub" => "{$packageDir}/CLAUDE.md",
            "{$stubDir}/gitignore.stub" => "{$packageDir}/.gitignore",
            "{$stubDir}/ci-workflow.stub" => "{$packageDir}/.github/workflows/ci.yml",
        ];
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Scaffolding repository files for package app: {$definition->appName}");

        if (! $this->processGeneratedFiles($files, $replacements, $dryRun, false)) {
            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run complete. No files were written.' : 'Package repository files scaffolded.');

        return self::SUCCESS;
    }

    private function printPackageBaselineNextSteps(string $appKey): void
    {
        $this->line('Review docs/README.md, docs/DOMAIN-MODEL.md and docs/PAGES.md in the generated App.');
        $this->line('Add a resource: nexia make resource ExampleResource (from the App directory) --label-ko 예시');
        $this->line('In a linked project: run npm install in this App, then nexia dev from the project or App directory. Project dev registers Apps automatically. For a new standalone App, connect its parent project with nexia connect before starting dev.');
    }
}
