<?php

declare(strict_types=1);

namespace Nexia\Devtools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Nexia\AppRuntime\AppPackageMetadataReader;
use Nexia\Contribution\ResourceRecordOwner;
use Nexia\Devtools\Commands\Concerns\InteractsWithPackageApps;
use Nexia\Devtools\Commands\Concerns\ScaffoldsResource;
use Nexia\Devtools\Commands\Concerns\WritesFlatTranslationCatalogs;
use RuntimeException;

class MakePackageResource extends Command
{
    use InteractsWithPackageApps;
    use ScaffoldsResource;
    use WritesFlatTranslationCatalogs;

    protected $signature = 'make:resource
        {package? : Existing App directory}
        {name? : Resource name in PascalCase (e.g., WorkOrder)}
        {--icon=box : Shell icon name for the app-local resource navigation entry}
        {--sort= : Shell app-local navigation sort order for this resource; defaults to the next app-local slot}
        {--navigation-group=operations : App Side Navigation group (`insights`, `management`, `operations`, `master-data`, or `settings`)}
        {--navigation-subgroup= : Optional App-owned subgroup id}
        {--record-owner=legal_entity : Canonical record owner (tenant|legal_entity)}
        {--label-ko= : Authored Korean singular resource label}
        {--label-ko-plural= : Korean list/menu label; defaults to the singular label}
        {--label-zh= : Optional Chinese singular resource label; defaults to English fallback}
        {--label-zh-plural= : Optional Chinese list/menu label; defaults to the singular label or English fallback}
        {--with-filament : Also generate and register optional Filament administration screens}
        {--without-navigation : Keep the resource routable and cataloged without publishing an App Menu entry}
        {--dry-run : Show what files and package-local edits would be made without writing them}
        {--force : Rewrite generated resource files in place}';

    protected $description = 'Add a package-owned tenant resource to an existing package-first app';

    public function handle(AppPackageMetadataReader $metadataReader): int
    {
        $packageInput = $this->scaffoldRequiredArgument(
            'package',
            'App directory',
            'App directory is required when running non-interactively.',
        );
        if ($packageInput === null) {
            return self::FAILURE;
        }

        try {
            $package = $this->resolvePackageApp($packageInput, $metadataReader);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($package === null) {
            $lookupDirectory = $this->packageLookupDirectory($packageInput);
            $this->error("Package app not found at {$lookupDirectory}. Run nexia-app make:app first.");

            return self::FAILURE;
        }

        $packageDir = $package['directory'];
        $definition = $package['definition'];
        $jsonMenu = array_key_exists('navigation', $metadataReader->declaration($packageDir));
        $appKey = $definition->appKey;
        $appTitle = $definition->appName;
        $appTablePrefix = $definition->appTablePrefix;

        if (! $this->packageRoutesHaveInstallGuard($packageDir, $appKey)) {
            $this->error(
                "Package routes must include [app.installed:{$appKey}] before adding resources.",
            );

            return self::FAILURE;
        }

        try {
            $packageNamespace = $this->manifestNamespace($definition);
            $manifestClassName = $this->manifestClassName($definition);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $appClassName = preg_replace('/AppManifest$/', '', $manifestClassName);

        if (! is_string($appClassName) || $appClassName === '') {
            $this->error("App manifest [{$definition->manifestClass}] must end with AppManifest.");

            return self::FAILURE;
        }

        $resourceNameInput = $this->scaffoldRequiredArgument(
            'name',
            'Resource name (PascalCase)',
            'Resource name is required when running non-interactively. Re-run with a resource name.',
        );
        if ($resourceNameInput === null) {
            return self::FAILURE;
        }

        $resourceName = Str::studly($resourceNameInput);
        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/D', $resourceName)) {
            $this->error('Resource name must produce a valid PHP class name.');

            return self::FAILURE;
        }
        try {
            token_get_all("<?php class {$resourceName} {}", TOKEN_PARSE);
        } catch (\ParseError) {
            $this->error('Resource name is reserved by PHP. Choose another name.');

            return self::FAILURE;
        }
        $resourceLower = Str::snake($resourceName);
        $resourceKebab = Str::kebab($resourceName);
        $resourceCamel = Str::camel($resourceName);
        $resourcePlural = Str::plural($resourceLower);
        $resourceTable = "{$appTablePrefix}_{$resourcePlural}";
        $resourcePluralKebab = Str::kebab(Str::plural($resourceName));
        $resourcePluralStudly = Str::studly($resourcePlural);
        $resourceTitle = Str::of($resourceName)->headline()->value();
        $resourcePluralTitle = Str::of($resourcePluralStudly)->headline()->value();
        $localizedLabels = $this->resolveLocalizedResourceLabels($resourceName, $resourcePlural);

        if ($localizedLabels === null) {
            return self::FAILURE;
        }

        $localeReplacements = $this->localizedResourceTranslationReplacements($localizedLabels);
        $resourceIcon = Str::of($this->scaffoldText(
            'Shell icon',
            trim((string) $this->option('icon')),
            'box',
        ))->trim()->value();
        $suggestedResourceSort = $this->resolveResourceSort($packageDir, $resourceName);
        if ($suggestedResourceSort === null) {
            return self::FAILURE;
        }
        $resourceSort = $this->scaffoldText(
            'App menu order',
            trim((string) ($this->option('sort') ?? '')),
            (string) $suggestedResourceSort,
        );
        $resourceKey = "{$appKey}.{$resourceLower}";
        $apiRouteFunction = "{$resourceCamel}ApiRoute";
        $timestamp = now()->format('Y_m_d_His');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $withoutNavigation = (bool) $this->option('without-navigation');
        if ($this->scaffoldWizardIsActive()) {
            $withoutNavigation = ! $this->confirm('Publish an App Menu entry?', ! $withoutNavigation);
        }
        $recordOwner = $this->resolveRecordOwner($this->scaffoldChoice(
            'Record owner',
            [ResourceRecordOwner::LegalEntity->value, ResourceRecordOwner::Tenant->value],
            trim((string) $this->option('record-owner')),
            ResourceRecordOwner::LegalEntity->value,
        ));

        if (! $recordOwner instanceof ResourceRecordOwner) {
            return self::FAILURE;
        }

        if ($resourceIcon === '') {
            $this->error('--icon must be a non-empty shell icon name.');

            return self::FAILURE;
        }

        $navigationGroup = $this->scaffoldChoice(
            'App navigation group',
            ['insights', 'management', 'operations', 'master-data', 'settings'],
            trim((string) $this->option('navigation-group')),
            'operations',
        );
        $navigationSubgroup = ($value = $this->scaffoldText(
            'App navigation subgroup (optional)',
            trim((string) $this->option('navigation-subgroup')),
        )) !== '' ? $value : null;

        if (! (in_array($navigationGroup, ['insights', 'management', 'operations', 'master-data', 'settings'], true) && ($navigationSubgroup === null || preg_match('/^[a-z][a-z0-9-]*$/D', $navigationSubgroup)))) {
            $this->error('Invalid navigation placement. Use group `insights`, `management`, `operations`, `master-data`, or `settings` and an optional subgroup.');

            return self::FAILURE;
        }

        if (! preg_match('/^-?\d+$/', $resourceSort)) {
            $this->error('--sort must be an integer.');

            return self::FAILURE;
        }

        $resourceSort = (int) $resourceSort;

        $replacements = $this->buildStandardReplacements(
            $resourceName,
            $resourcePlural,
            [
                '{{ packageNamespace }}' => $packageNamespace,
                '{{ appName }}' => $appClassName,
                '{{ appKey }}' => $appKey,
                '{{ tableName }}' => $resourceTable,
                '{{ appTitle }}' => $appTitle,
                '{{ resourceTitle }}' => $resourceTitle,
                '{{ resourcePluralTitle }}' => $resourcePluralTitle,
                '{{ resourceIcon }}' => $resourceIcon,
                '{{ resourceSort }}' => $jsonMenu ? '500' : (string) $resourceSort,
                '{{ resourceKey }}' => $resourceKey,
                '{{ resource_key }}' => $resourceKey,
                '{{ navigationVisible }}' => $withoutNavigation && ! $jsonMenu ? 'false' : 'true',
                '{{ navigationGroup }}' => $jsonMenu ? 'operations' : $navigationGroup,
                '{{ navigationSubgroupLiteral }}' => var_export($jsonMenu ? null : $navigationSubgroup, true),
                '{{ recordOwnerCase }}' => $recordOwner === ResourceRecordOwner::Tenant ? 'Tenant' : 'LegalEntity',
                '{{ assignmentScopeCase }}' => $recordOwner === ResourceRecordOwner::Tenant ? 'Tenant' : 'LegalEntity',
                '{{ legalEntityParticipationCase }}' => $recordOwner === ResourceRecordOwner::Tenant ? 'None' : 'RecordOwner',
                '{{ legalEntityMigrationColumns }}' => $recordOwner === ResourceRecordOwner::LegalEntity
                    ? "            \$table->foreignId('legal_entity_id')\n                ->constrained('legal_entities')\n                ->cascadeOnDelete();"
                    : '',
                '{{ legalEntityFillable }}' => $recordOwner === ResourceRecordOwner::LegalEntity
                    ? "        'legal_entity_id',"
                    : '',
                '{{ generatedIndexes }}' => $this->migrationIndexes(
                    $resourceTable,
                    $recordOwner === ResourceRecordOwner::LegalEntity
                        ? [
                            ['legal_entity_id', 'created_at'],
                        ]
                        : [
                            ['created_at'],
                        ],
                ),
                '{{ apiRouteBase }}' => "'/{$appKey}/{$resourcePluralKebab}'",
                '{{ apiListRouteExpression }}' => "{$apiRouteFunction}()",
                '{{ apiDetailRouteExpression }}' => "{$apiRouteFunction}(id)",
                '{{ legalEntityOwned }}' => $recordOwner === ResourceRecordOwner::LegalEntity ? 'true' : 'false',
            ],
        );

        $stubDir = dirname(__DIR__, 2).'/stubs/package-resource';
        $migrationTarget = $this->resolveExistingMigrationOr(
            "{$packageDir}/database/migrations/tenant",
            $resourceTable,
            $timestamp,
        );
        $moduleTarget = "{$packageDir}/src/Contribution/Resources/{$resourceName}Module.php";

        if (! $this->recordOwnerChangeIsSafe($moduleTarget, $recordOwner)) {
            return self::FAILURE;
        }

        $surfaceDirectory = "{$packageDir}/resources/js/resources/{$resourcePluralKebab}/surface";
        if (! is_file("{$surfaceDirectory}/{$resourceName}RecordSurface.tsx")
            && (is_file("{$surfaceDirectory}/{$resourceName}ShowSurface.tsx") || is_file("{$surfaceDirectory}/{$resourceName}FormSurface.tsx"))) {
            $this->error('This Resource has separate Show/Form screens. Migrate its custom code and route declaration to RecordSurface before regenerating; no files were written.');

            return self::FAILURE;
        }

        // Existing custom adapters remain owned by the App, including under --force.
        $replacements['{{ inspectorModule }}'] = is_file("{$packageDir}/resources/js/resources/{$resourcePluralKebab}/inspector/{$resourceName}Inspector.tsx")
            ? "./inspector/{$resourceName}Inspector"
            : "./surface/{$resourceName}RecordSurface";

        $files = [
            "{$stubDir}/model.stub" => "{$packageDir}/src/Models/{$resourceName}.php",
            "{$stubDir}/resource-module.stub" => $moduleTarget,
            "{$stubDir}/controller.stub" => "{$packageDir}/src/Http/Controllers/{$resourceName}Controller.php",
            "{$stubDir}/policy.stub" => "{$packageDir}/src/Policies/{$resourceName}Policy.php",
            "{$stubDir}/migration.stub" => $migrationTarget,
            "{$stubDir}/filament-resource.stub" => "{$packageDir}/src/Filament/Tenant/Resources/{$resourcePluralStudly}/{$resourceName}Resource.php",
            "{$stubDir}/filament-list-page.stub" => "{$packageDir}/src/Filament/Tenant/Resources/{$resourcePluralStudly}/Pages/List{$resourcePluralStudly}.php",
            "{$stubDir}/filament-view-page.stub" => "{$packageDir}/src/Filament/Tenant/Resources/{$resourcePluralStudly}/Pages/View{$resourceName}.php",
            "{$stubDir}/resources-js-resource-contract.stub" => "{$packageDir}/resources/js/resources/{$resourcePluralKebab}/{$resourceKebab}-resource-contract.ts",
            "{$stubDir}/resources-js-list-surface.stub" => "{$packageDir}/resources/js/resources/{$resourcePluralKebab}/surface/{$resourceName}ListSurface.tsx",
            "{$stubDir}/resources-js-queries.stub" => "{$packageDir}/resources/js/resources/{$resourcePluralKebab}/{$resourceKebab}-queries.ts",
            "{$stubDir}/resources-js-record-surface.stub" => "{$packageDir}/resources/js/resources/{$resourcePluralKebab}/surface/{$resourceName}RecordSurface.tsx",
            "{$stubDir}/pest-test.stub" => "{$packageDir}/tests/Feature/{$resourceName}AuthorizationTest.php",
        ];

        if (! $this->option('with-filament')) {
            foreach (['filament-resource.stub', 'filament-list-page.stub', 'filament-view-page.stub'] as $stub) {
                unset($files["{$stubDir}/{$stub}"]);
            }
        }

        $this->info("Scaffolding package resource: {$appTitle} / {$resourceName}");
        $this->line("  package path: {$packageDir}");
        $this->line("  namespace:    {$packageNamespace}");
        $this->line("  route:        /apps/{$appKey}/{$resourcePluralKebab}");
        $this->line("  record owner: {$recordOwner->value}");
        $this->line("  Korean labels: {$localizedLabels['ko']['singular']} / {$localizedLabels['ko']['plural']}");
        $this->line(
            $localizedLabels['zh'] === $localizedLabels['en']
                ? '  Chinese labels: English fallback'
                : "  Chinese labels: {$localizedLabels['zh']['singular']} / {$localizedLabels['zh']['plural']}",
        );
        if ($force) {
            $this->warn('  overwrite:    enabled (--force)');
        }
        $this->newLine();

        if (! $this->confirmScaffoldPlan('Create this package resource?', $dryRun)) {
            return self::SUCCESS;
        }

        // Validate every planned registration/catalog before the first source write.
        // This is preflight, not a filesystem transaction: later I/O failures still need inspection.
        try {
            $menu = $this->plannedNavigation($packageDir, array_filter([
                'screen' => $appKey.'-'.Str::kebab(Str::plural($resourceName)),
                'group' => $navigationGroup, 'subgroup' => $navigationSubgroup,
                'sort' => $resourceSort, 'icon' => $resourceIcon,
            ], static fn ($value): bool => $value !== null), $withoutNavigation);
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
        $steps = [
            fn (bool $check) => $this->processGeneratedFiles($files, $replacements, $check, $force),
            fn (bool $check) => ! $this->option('with-filament') || $this->syncPackageManifest($packageDir, $packageNamespace, $manifestClassName, $resourceName, $resourcePluralStudly, $check),
            fn (bool $check) => $this->syncPackageRoutes($packageDir, $packageNamespace, $appKey, $resourceName, $resourceLower, $resourcePluralKebab, $recordOwner, $check),
            fn (bool $check) => $this->syncFrontendRegistration($packageDir, $check),
            fn (bool $check) => $this->syncPackagePermissionTranslations($packageDir, $resourceName, $replacements, $localeReplacements, $check, $force),
            fn (bool $check) => $this->syncPackageLocales($packageDir, $resourceName, $replacements, $localeReplacements, $check, $force),
        ];
        if ($menu !== null) {
            $steps[] = static fn (bool $check): bool => $check || file_put_contents($packageDir.'/nexia.json', $menu) !== false;
        }
        foreach ($dryRun ? [true] : [true, false] as $check) {
            foreach ($steps as $step) {
                if (! $step($check)) {
                    return self::FAILURE;
                }
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->info('Dry run complete. No files were written.');

            return self::SUCCESS;
        }

        $this->info('Package resource scaffolded.');
        $this->line('Delete route and UI were scaffolded, but the generated Policy denies delete by default. Enable it only after choosing the domain lifecycle.');
        $this->printPackageNextSteps($appKey);

        return self::SUCCESS;
    }

    private function syncPackageManifest(
        string $packageDir,
        string $packageNamespace,
        string $manifestClassName,
        string $resourceName,
        string $resourcePluralStudly,
        bool $dryRun,
    ): bool {
        $path = "{$packageDir}/src/{$manifestClassName}.php";

        // Permission keys flow from the Resource Module's
        // ContributesResourcePermissions trait — the trait satisfies
        // PermissionContribution, and NexiaContributionRegistry walks
        // the package's contribution locations to discover them. The
        // manifest no longer enumerates per-resource permissions, so
        // make-package-resource has nothing to inject here.

        // Navigation entry is contributed by the Resource Module
        // via ContributesNavigationDestination. Its stable Resource Key and generated
        // shell metadata derive the conventional id and route. The manifest's
        // static navigationItems() carries only the package launcher, so
        // make-package-resource does not edit the manifest's navigation block.

        // Page-element routes (/apps/{app}/{plural}, /new, /:id, /:id/edit)
        // are auto-derived by AbstractPackageAppManifest::pageElements() from the
        // Resource Module's ShellResourceContribution implementation. The
        // module stub already adds the interface plus the HasShellResource
        // default trait, so no manifest injection is needed here.

        $filamentResource = "\\{$packageNamespace}\\Filament\\Tenant\\Resources\\{$resourcePluralStudly}\\{$resourceName}Resource::class";

        if (! $this->syncPackageEdit(
            $path,
            '// nexia:make-package-resource:tenant-filament-resources:end',
            "            {$filamentResource},",
            $filamentResource,
            $dryRun,
            "{$resourceName} tenant Filament resource",
        )) {
            return false;
        }

        return true;
    }

    private function packageRoutesHaveInstallGuard(string $packageDir, string $appKey): bool
    {
        $path = "{$packageDir}/routes/routes.php";

        if (! is_file($path)) {
            return false;
        }

        $contents = file_get_contents($path);

        return is_string($contents)
            && str_contains($contents, "'app.installed:{$appKey}'");
    }

    private function syncPackageRoutes(
        string $packageDir,
        string $packageNamespace,
        string $appKey,
        string $resourceName,
        string $resourceLower,
        string $resourcePluralKebab,
        ResourceRecordOwner $recordOwner,
        bool $dryRun,
    ): bool {
        $path = "{$packageDir}/routes/routes.php";

        if (! $this->syncPackageEdit(
            $path,
            '// nexia:make-package-resource:controller-imports:end',
            "use {$packageNamespace}\\Http\\Controllers\\{$resourceName}Controller;",
            "{$resourceName}Controller",
            $dryRun,
            "{$resourceName} controller import",
        )) {
            return false;
        }

        if (! $this->sortPackageControllerImports($path, $packageNamespace, $dryRun)) {
            return false;
        }

        $routePrefix = "api/{$appKey}";
        $permissionMiddleware = fn (string $action): string => $recordOwner === ResourceRecordOwner::LegalEntity
            ? "can.scope:{$appKey}.{$resourceLower}.{$action},core.legal_entity,legalEntity"
            : "can.tenant_wide:{$appKey}.{$resourceLower}.{$action}";
        $readMiddleware = $permissionMiddleware('read');
        $createMiddleware = $permissionMiddleware('create');
        $updateMiddleware = $permissionMiddleware('update');
        $deleteMiddleware = $permissionMiddleware('delete');

        $routeBlock = implode("\n", [
            "    Route::prefix('{$routePrefix}')->group(function () {",
            ...($recordOwner === ResourceRecordOwner::LegalEntity ? [
                "        Route::get('/{$resourcePluralKebab}', [{$resourceName}Controller::class, 'index']);",
                "        Route::post('/{$resourcePluralKebab}', [{$resourceName}Controller::class, 'store']);",
                "        Route::get('/{$resourcePluralKebab}/{{$resourceLower}}', [{$resourceName}Controller::class, 'show'])->whereUuid('{$resourceLower}');",
                "        Route::put('/{$resourcePluralKebab}/{{$resourceLower}}', [{$resourceName}Controller::class, 'update'])->whereUuid('{$resourceLower}');",
                "        Route::delete('/{$resourcePluralKebab}/{{$resourceLower}}', [{$resourceName}Controller::class, 'destroy'])->whereUuid('{$resourceLower}');",
            ] : [
                "        Route::get('/{$resourcePluralKebab}/actions', [{$resourceName}Controller::class, 'collectionActions']);",
                "        Route::middleware(['{$readMiddleware}'])->group(function () {",
                "            Route::get('/{$resourcePluralKebab}', [{$resourceName}Controller::class, 'index']);",
                "            Route::get('/{$resourcePluralKebab}/{{$resourceLower}}', [{$resourceName}Controller::class, 'show'])->whereUuid('{$resourceLower}');",
                '        });',
                '',
                "        Route::middleware(['{$createMiddleware}'])->group(function () {",
                "            Route::post('/{$resourcePluralKebab}', [{$resourceName}Controller::class, 'store']);",
                '        });',
                '',
                "        Route::middleware(['{$updateMiddleware}'])->group(function () {",
                "            Route::put('/{$resourcePluralKebab}/{{$resourceLower}}', [{$resourceName}Controller::class, 'update'])->whereUuid('{$resourceLower}');",
                '        });',
                '',
                "        Route::middleware(['{$deleteMiddleware}'])->group(function () {",
                "            Route::delete('/{$resourcePluralKebab}/{{$resourceLower}}', [{$resourceName}Controller::class, 'destroy'])->whereUuid('{$resourceLower}');",
                '        });',
            ]),
            '    });',
            '',
        ]);

        if ($recordOwner === ResourceRecordOwner::LegalEntity) {
            $routeBlock .= implode("\n", [
                '    // Legacy scoped URLs remain for saved links; new surfaces use request targets above.',
                "    Route::prefix('api/legal-entities/{legalEntity:public_id}/{$appKey}')->group(function () {",
                "        Route::middleware(['{$readMiddleware}'])->group(function () {",
                "            Route::get('/{$resourcePluralKebab}', [{$resourceName}Controller::class, 'legalEntityIndex']);",
                "            Route::get('/{$resourcePluralKebab}/{{$resourceLower}}', [{$resourceName}Controller::class, 'legalEntityShow'])->whereUuid('{$resourceLower}');",
                '        });',
                "        Route::middleware(['{$createMiddleware}'])->group(function () {",
                "            Route::post('/{$resourcePluralKebab}', [{$resourceName}Controller::class, 'legalEntityStore']);",
                '        });',
                "        Route::middleware(['{$updateMiddleware}'])->group(function () {",
                "            Route::put('/{$resourcePluralKebab}/{{$resourceLower}}', [{$resourceName}Controller::class, 'legalEntityUpdate'])->whereUuid('{$resourceLower}');",
                '        });',
                "        Route::middleware(['{$deleteMiddleware}'])->group(function () {",
                "            Route::delete('/{$resourcePluralKebab}/{{$resourceLower}}', [{$resourceName}Controller::class, 'legalEntityDestroy'])->whereUuid('{$resourceLower}');",
                '        });',
                '    });',
                '',
            ]);
        }

        return $this->syncPackageEdit(
            $path,
            '// nexia:make-package-resource:routes:end',
            $routeBlock,
            "/{$resourcePluralKebab}/{{$resourceLower}}",
            $dryRun,
            "{$resourceName} routes",
        );
    }

    private function syncFrontendRegistration(string $packageDir, bool $dryRun): bool
    {
        // Custom entry points are valid too. No marker is edited or required here.
        if (! is_file("{$packageDir}/resources/js/index.ts")) {
            $this->error('App frontend entry resources/js/index.ts is missing. Restore it before generating resources.');

            return false;
        }

        $path = "{$packageDir}/resources/js/index.ts";
        $source = file_get_contents($path);
        if ($source === false) {
            $this->error('Could not read App frontend entry; no registration update was made.');

            return false;
        }
        // Upgrade only the exact previously generated call, preserving custom entries.
        $previous = "autoRegisterPackageResourceInspectors(\n    import.meta.glob('./resources/*/inspector/register-*-inspector.ts', { eager: true }),\n);";
        $current = "autoRegisterPackageResourceInspectors(\n    import.meta.glob('./resources/*/inspector/register-*-inspector.ts', { eager: true }),\n    import.meta.glob('./resources/*/*-resource-contract.ts', { eager: true }),\n);";
        if (str_contains($source, $previous)) {
            $this->line('  Update generated Inspector registration to include resource contracts.');
            if (! $dryRun && file_put_contents($path, str_replace($previous, $current, $source)) === false) {
                $this->error('Could not update App Inspector registration.');

                return false;
            }
        } elseif (! str_contains($source, $current) && $dryRun) {
            $this->warn('Custom frontend entry: register the generated resourceInspector declaration through autoRegisterPackageResourceInspectors or your existing registration code. The entry was preserved.');
        }

        return true;
    }

    /**
     * @param  array<string, string>  $replacements
     * @param  array<string, array<string, string>>  $localeReplacements
     */
    private function syncPackagePermissionTranslations(
        string $packageDir,
        string $resourceName,
        array $replacements,
        array $localeReplacements,
        bool $dryRun,
        bool $force,
    ): bool {
        $stubDir = dirname(__DIR__, 2).'/stubs/package-resource';

        return $this->mergeTranslationCatalogStubs(
            "{$packageDir}/resources/lang",
            [
                'en' => "{$stubDir}/permissions-en.stub",
                'ko' => "{$stubDir}/permissions-ko.stub",
                'zh' => "{$stubDir}/permissions-zh.stub",
            ],
            $replacements,
            "{$resourceName} permission translations",
            $dryRun,
            $force,
            $localeReplacements,
        );
    }

    /**
     * @param  array<string, string>  $replacements
     * @param  array<string, array<string, string>>  $localeReplacements
     */
    private function syncPackageLocales(
        string $packageDir,
        string $resourceName,
        array $replacements,
        array $localeReplacements,
        bool $dryRun,
        bool $force,
    ): bool {
        $stubDir = dirname(__DIR__, 2).'/stubs/package-resource';

        return $this->mergeTranslationCatalogStubs(
            "{$packageDir}/resources/lang",
            [
                'en' => "{$stubDir}/i18n-en.stub",
                'ko' => "{$stubDir}/i18n-ko.stub",
                'zh' => "{$stubDir}/i18n-zh.stub",
            ],
            $replacements,
            "{$resourceName} translations",
            $dryRun,
            $force,
            $localeReplacements,
        );
    }

    private function syncPackageEdit(
        string $path,
        string $anchor,
        string $insert,
        string $alreadyPresentNeedle,
        bool $dryRun,
        string $label,
    ): bool {
        $relativePath = $this->relativePath($path);

        if (! file_exists($path)) {
            $this->error("  CONFLICT {$relativePath} (file not found)");
            $this->line('  hint: restore the generated file or recreate the package app before adding this resource.');

            return false;
        }

        $content = (string) file_get_contents($path);

        if (str_contains($content, $alreadyPresentNeedle)) {
            $this->warn("  SKIP    {$relativePath} ({$label} already present)");

            return true;
        }

        $pattern = '/^([ \t]*)'.preg_quote($anchor, '/').'$/m';
        if (preg_match_all($pattern, $content) !== 1) {
            $this->error("  CONFLICT {$relativePath} (anchor '{$anchor}' not found exactly once)");
            $this->line("  hint: restore exactly one '{$anchor}' line, then re-run this command.");
            $this->line('  hint: a fresh --dry-run scaffold shows the canonical anchor placement without writing files.');

            return false;
        }

        if ($dryRun) {
            $this->line("  WOULD   {$relativePath} ({$label})");

            return true;
        }

        $updated = preg_replace_callback(
            $pattern,
            fn (array $matches): string => rtrim($insert, " \t")."\n{$matches[1]}{$anchor}",
            $content,
            1,
        );

        if (! is_string($updated)) {
            $this->error("  CONFLICT {$relativePath} (could not update anchor '{$anchor}')");
            $this->line("  hint: restore the generated anchor '{$anchor}' and re-run this command.");

            return false;
        }

        file_put_contents($path, $updated);
        $this->line("  UPDATE  {$relativePath} ({$label})");

        return true;
    }

    private function sortPackageControllerImports(string $path, string $packageNamespace, bool $dryRun): bool
    {
        $relativePath = $this->relativePath($path);
        $anchor = '// nexia:make-package-resource:controller-imports:end';
        $content = (string) file_get_contents($path);
        $lines = preg_split("/(\r\n|\n|\r)/", $content);

        if ($lines === false) {
            $this->error("  CONFLICT {$relativePath} (could not read lines)");
            $this->line('  hint: confirm the route file is readable and keeps the generated import anchor.');

            return false;
        }

        $controllerImportPattern = '/^use '.preg_quote($packageNamespace, '/').'\\\\Http\\\\Controllers\\\\[^;]+;$/';
        $controllerImports = [];
        $remainingLines = [];

        foreach ($lines as $line) {
            if (preg_match($controllerImportPattern, $line) === 1) {
                $controllerImports[] = $line;

                continue;
            }

            $remainingLines[] = $line;
        }

        $controllerImports = array_values(array_unique($controllerImports));
        sort($controllerImports, SORT_STRING);

        foreach ($remainingLines as $index => $line) {
            if (! str_contains($line, $anchor)) {
                continue;
            }

            array_splice($remainingLines, $index, 0, $controllerImports);
            if (! $dryRun) {
                file_put_contents($path, implode(PHP_EOL, $remainingLines));
            }

            return true;
        }

        $this->error("  CONFLICT {$relativePath} (anchor '{$anchor}' not found)");
        $this->line("  hint: restore the generated '{$anchor}' line before re-running this command.");

        return false;
    }

    private function parseSortOption(string $option): ?int
    {
        $raw = $this->option($option);

        if ($raw === null) {
            return null;
        }

        if (! preg_match('/^-?\d+$/', (string) $raw)) {
            $this->error("--{$option} must be an integer.");

            return null;
        }

        return (int) $raw;
    }

    private function resolveResourceSort(string $packageDir, string $resourceName): ?int
    {
        $explicit = $this->parseSortOption('sort');

        if ($explicit !== null) {
            return $explicit;
        }

        if ($this->option('sort') !== null) {
            return null;
        }

        $declaration = (new AppPackageMetadataReader)->declaration($packageDir);
        if (array_key_exists('navigation', $declaration)) {
            $screen = $declaration['app']['app_key'].'-'.Str::kebab(Str::plural($resourceName));
            $entries = array_column($declaration['navigation'], null, 'screen');
            if (isset($entries[$screen]['sort'])) {
                return $entries[$screen]['sort'];
            }
            return max([0, ...array_column($declaration['navigation'], 'sort')]) + 10;
        }

        $moduleDirectory = "{$packageDir}/src/Contribution/Resources";
        $targetModule = "{$moduleDirectory}/{$resourceName}Module.php";

        if (is_file($targetModule)) {
            $existingSort = $this->readResourceSort($targetModule);

            if ($existingSort !== null) {
                return $existingSort;
            }
        }

        $sorts = [];
        $moduleFiles = glob("{$moduleDirectory}/*Module.php") ?: [];
        sort($moduleFiles);

        foreach ($moduleFiles as $moduleFile) {
            $sort = $this->readResourceSort($moduleFile);

            if ($sort !== null) {
                $sorts[] = $sort;
            }
        }

        if ($sorts === []) {
            return 10;
        }

        return max($sorts) + 10;
    }

    private function readResourceSort(string $moduleFile): ?int
    {
        $moduleContent = (string) file_get_contents($moduleFile);

        if (preg_match("/'order'\\s*=>\\s*(-?\\d+)\\s*,/", $moduleContent, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }
}
