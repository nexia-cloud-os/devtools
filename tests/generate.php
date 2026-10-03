<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Filesystem\Filesystem;
use Nexia\Apps\Acme\LeaveManager\Contribution\Pages\LeaveCalendarModule;
use Nexia\Apps\Acme\LeaveManager\Contribution\Pages\WorkSummaryModule;
use Nexia\Apps\Acme\LeaveManager\Contribution\Resources\SharedNoteModule;
use Nexia\Apps\Acme\LeaveManager\Contribution\Resources\WorkEntryModule;
use Symfony\Component\Process\Process;

$root = sys_get_temp_dir().'/nexia-devtools-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$binary = dirname(__DIR__).'/bin/nexia-app';
$run = static function (array $arguments, bool $success = true) use ($root, $binary): string {
    $process = new Process([PHP_BINARY, $binary, ...$arguments, '--no-interaction'], $root);
    $process->run();
    if ($process->isSuccessful() !== $success) {
        throw new RuntimeException($process->getOutput().$process->getErrorOutput());
    }

    return $process->getOutput();
};
try {
    $run(['make:app', 'LeaveManager', '--vendor=acme', '--family=people', '--key=leave', '--table-prefix=leave_records', '--dry-run']);
    assert(! file_exists($root.'/acme'));
    $run(['make:app', 'LeaveManager', '--vendor=acme', '--family=people', '--key=leave', '--table-prefix=leave_records']);
    $directory = $root.'/acme/leave';
    $composer = json_decode(file_get_contents($directory.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    assert($composer['name'] === 'acme/leave');
    assert($composer['require']['nexia-cloud-os/sdk-laravel'] === '^0.8.0');
    assert(str_contains(file_get_contents($directory.'/Dockerfile'), 'nexia-cloud-os/devtools:'));
    assert(! isset($composer['require']['nexia/sdk-laravel']));
    assert($composer['autoload']['psr-4'] === ['Nexia\\Apps\\Acme\\LeaveManager\\' => 'src/']);
    assert(! isset($composer['extra']['nexia']));
    assert(is_file($directory.'/src/Support/DB.php'));
    assert(is_file($directory.'/src/Support/Schema.php'));
    assert(str_contains(file_get_contents($directory.'/src/Support/DB.php'), "namespace Nexia\\Apps\\Acme\\LeaveManager\\Support;"));
    assert(str_contains(file_get_contents($directory.'/src/Support/DB.php'), "public const APP_KEY = 'leave';"));
    assert(str_contains(file_get_contents($directory.'/src/Support/Schema.php'), 'extends AppSchema'));
    $manifestSource = file_get_contents($directory.'/src/LeaveManagerAppManifest.php');
    preg_match('/public function tenantMigrationPaths\(\): array\s*\{(.*?)\n    \}/s', $manifestSource, $migrationMethod);
    assert(isset($migrationMethod[1]));
    $migrationBody = str_replace('__DIR__', var_export($directory.'/src', true), $migrationMethod[1]);
    $migrationPaths = eval('return static function (): array {'.$migrationBody.'};');
    assert(count($migrationPaths()) === 1);
    unlink($directory.'/database/migrations/tenant/.gitkeep');
    rmdir($directory.'/database/migrations/tenant');
    assert($migrationPaths() === [], 'An empty App snapshot has no migration directory.');
    mkdir($directory.'/database/migrations/tenant');
    touch($directory.'/database/migrations/tenant/.gitkeep');
    assert(count($migrationPaths()) === 1);

    $declaration = json_decode(file_get_contents($directory.'/nexia.json'), true, flags: JSON_THROW_ON_ERROR);
    assert($declaration['schema_version'] === '2' && $declaration['runtime'] === 'laravel');
    assert($declaration['app']['app_table_prefix'] === 'leave_records');
    assert(file_get_contents($directory.'/.dockerignore') === "**\n!Dockerfile\n");
    assert(str_contains(file_get_contents($directory.'/compose.yaml'), 'NEXIA_CONFIG_HOME: /nexia-config/'));
    $routes = file_get_contents($directory.'/routes/routes.php');
    assert(str_contains($routes, 'Middleware::APP_REQUEST'));
    assert(! str_contains($routes, 'Stancl\\Tenancy'));
    $run(['make:page', 'acme/leave', 'LeaveCalendar', '--label-ko=휴가 달력', '--dry-run']);
    assert(! file_exists($directory.'/src/Http/Controllers/LeaveCalendarController.php'));
    $run(['make:page', 'acme/leave', 'LeaveCalendar', '--label-ko=휴가 달력', '--without-record']);
    assert(! file_exists($directory.'/src/Models/LeaveCalendar.php'));
    assert(! file_exists($directory.'/src/Contribution/Resources/LeaveCalendarModule.php'));
    assert(str_contains(file_get_contents($directory.'/routes/routes.php'), 'can.tenant_wide:leave.leave_calendar.read'));
    assert(str_contains(file_get_contents($directory.'/resources/js/index.ts'), 'LeaveLeaveCalendarSurface'));
    assert(json_decode(file_get_contents($directory.'/resources/lang/ko.json'), true)['leave.pages.leave_calendar.title'] === '휴가 달력');
    require $directory.'/src/Contribution/Pages/LeaveCalendarModule.php';
    $page = LeaveCalendarModule::class;
    assert($page::catalogPermissionDefinitions()[0]->key === 'leave.leave_calendar.read');
    assert($page::navigationItems()[0]['permission'] === 'leave.leave_calendar.read');
    $run(['make:page', 'acme/leave', 'WorkSummary', '--label-ko=업무 요약', '--without-navigation']);
    require $directory.'/src/Contribution/Pages/WorkSummaryModule.php';
    $summaryPage = WorkSummaryModule::class;
    $menu = json_decode(file_get_contents($directory.'/nexia.json'), true, flags: JSON_THROW_ON_ERROR)['navigation'];
    assert(! in_array('leave.page.work_summary', array_column($menu, 'screen'), true));
    assert(in_array('leave.page.leave_calendar', array_column($menu, 'screen'), true));
    assert(count($summaryPage::navigationItems()) === 1); // Declared screen remains routable; JSON controls menu selection.

    assert(array_map(fn ($permission) => $permission->key, $summaryPage::catalogPermissionDefinitions()) === ['leave.work_summary.read', 'leave.work_summary.update']);
    assert(str_contains(file_get_contents($directory.'/resources/js/index.ts'), "mode: 'edit'"));
    assert(str_contains(file_get_contents($directory.'/src/LeaveManagerAppManifest.php'), '/apps/leave/work-summary/:id/edit'));
    assert(str_contains(file_get_contents($directory.'/routes/routes.php'), 'can.tenant_wide:leave.work_summary.update'));
    assert(str_contains(file_get_contents($directory.'/resources/js/pages/WorkSummaryRecordSurface.tsx'), "formKey: 'leave.pages.work_summary'"));
    assert(! file_exists($directory.'/src/Models/WorkSummary.php'));
    $pageBefore = file_get_contents($directory.'/src/Http/Controllers/LeaveCalendarController.php');
    $run(['make:page', 'acme/leave', 'LeaveCalendar', '--label-ko=휴가 달력'], false);
    assert(file_get_contents($directory.'/src/Http/Controllers/LeaveCalendarController.php') === $pageBefore);
    $manifest = file_get_contents($directory.'/src/LeaveManagerAppManifest.php');
    $run(['make:app', 'LeaveManager', '--vendor=acme', '--family=people', '--key=leave'], false);
    assert(file_get_contents($directory.'/src/LeaveManagerAppManifest.php') === $manifest);
    $run(['make:resource', 'acme/leave', 'Class', '--label-ko=종류'], false);
    assert(! file_exists($directory.'/src/Models/Class.php'));
    $run(['make:resource', 'acme/leave', 'Request', '--label-ko=휴가 신청']);
    assert(str_contains(file_get_contents($directory.'/src/Models/Request.php'), 'namespace Nexia\\Apps\\Acme\\LeaveManager\\Models;'));
    assert(str_contains(file_get_contents($directory.'/src/Models/Request.php'), 'extends NexiaEntityModel'));
    assert(count(glob($directory.'/database/migrations/tenant/*_create_leave_records_requests_table.php')) === 1);
    assert(str_contains(file_get_contents(glob($directory.'/database/migrations/tenant/*_create_leave_records_requests_table.php')[0]), 'Illuminate\\Support\\Facades\\Schema'));
    assert(! is_dir($directory.'/src/Filament/Tenant/Resources/Requests'));
    assert(file_get_contents($directory.'/src/LeaveManagerAppManifest.php') === $manifest);
    $run(['make:resource', 'acme/leave', 'Request', '--label-ko=휴가 신청', '--with-filament', '--force']);
    $adminPath = $directory.'/src/Filament/Tenant/Resources/Requests/RequestResource.php';
    assert(is_file($adminPath));
    $adminSource = file_get_contents($adminPath)."\n// Keep existing administration customizations.\n";
    file_put_contents($adminPath, $adminSource);
    $adminManifest = file_get_contents($directory.'/src/LeaveManagerAppManifest.php');
    assert(str_contains($adminManifest, 'RequestResource::class'));
    $migration = glob($directory.'/database/migrations/tenant/*_create_leave_records_requests_table.php')[0];
    $migrationSource = file_get_contents($migration)."\n// Existing migration history must survive regeneration.\n";
    file_put_contents($migration, $migrationSource);
    $run(['make:resource', 'acme/leave', 'Request', '--label-ko=휴가 신청', '--force']);
    assert(file_get_contents($migration) === $migrationSource);
    assert(file_get_contents($adminPath) === $adminSource);
    assert(file_get_contents($directory.'/src/LeaveManagerAppManifest.php') === $adminManifest);
    assert(count(glob($directory.'/database/migrations/tenant/*_create_leave_records_requests_table.php')) === 1);

    $entryPath = $directory.'/resources/js/index.ts';
    $entry = file_get_contents($entryPath);
    $legacyEntry = str_replace("    import.meta.glob('./resources/*/*-resource-contract.ts', { eager: true }),\n", '', $entry);
    file_put_contents($entryPath, $legacyEntry);
    $run(['make:resource', 'acme/leave', 'WorkEntry', '--label-ko=작업 항목', '--dry-run']);
    assert(file_get_contents($entryPath) === $legacyEntry);
    $run(['make:resource', 'acme/leave', 'WorkEntry', '--label-ko=작업 항목']);
    assert(file_get_contents($entryPath) === $entry);
    $inspector = $directory.'/resources/js/resources/work-entries/inspector/WorkEntryInspector.tsx';
    assert(! file_exists($inspector));
    $contract = $directory.'/resources/js/resources/work-entries/work-entry-resource-contract.ts';
    assert(str_contains(file_get_contents($contract), "import('./surface/WorkEntryRecordSurface')"));
    mkdir(dirname($inspector), recursive: true);
    file_put_contents($inspector, '// Custom App-owned Inspector');
    $run(['make:resource', 'acme/leave', 'WorkEntry', '--label-ko=작업 항목', '--force']);
    assert(file_get_contents($inspector) === '// Custom App-owned Inspector');
    assert(str_contains(file_get_contents($contract), "import('./inspector/WorkEntryInspector')"));
    $module = file_get_contents($directory.'/src/Contribution/Resources/WorkEntryModule.php');
    require $directory.'/src/Contribution/Resources/WorkEntryModule.php';
    $mutation = WorkEntryModule::resourceModuleDefinition()->descriptor->mutation;
    assert($mutation->createInputSchema['required'] === ['name']);
    assert(! isset($mutation->updateInputSchema['required']));
    assert($mutation->createInputSchema['properties']['name']['maxLength'] === 255);
    assert(! isset($mutation->createInputSchema['properties']['status']));
    assert(! is_dir($directory.'/src/Enums'));
    assert($mutation->createInputSchema['properties']['legal_entity_public_id']['format'] === 'uuid');
    assert(! isset($mutation->createInputSchema['properties']['public_id']));
    $run(['make:resource', 'acme/leave', 'SharedNote', '--record-owner=tenant', '--label-ko=공통 노트']);
    require $directory.'/src/Contribution/Resources/SharedNoteModule.php';
    assert(str_contains(file_get_contents($directory.'/routes/routes.php'), "Route::get('/shared-notes/actions', [SharedNoteController::class, 'collectionActions'])"));
    assert(str_contains(file_get_contents($directory.'/src/Http/Controllers/SharedNoteController.php'), 'public function collectionActions(Request $request)'));
    assert(str_contains(file_get_contents($directory.'/resources/js/resources/shared-notes/surface/SharedNoteRecordSurface.tsx'), "sharedNoteApiRoute() + '/actions'"));
    $tenantMutation = SharedNoteModule::resourceModuleDefinition()->descriptor->mutation;
    assert(array_keys($tenantMutation->createInputSchema['properties']) === ['name']);
    assert($tenantMutation->updateInputSchema['properties'] === $tenantMutation->createInputSchema['properties']);
    $tenantMigration = file_get_contents(glob($directory.'/database/migrations/tenant/*_create_leave_records_shared_notes_table.php')[0]);
    assert(! str_contains($tenantMigration, "foreignId('legal_entity_id')"));
    assert(str_contains($tenantMigration, "\$table->index(['created_at'],"));
    assert(! str_contains($tenantMigration, 'status'));
    assert(! file_exists($directory.'/resources/js/pages/LeaveCalendarRecordSurface.tsx'));
    assert(str_contains($module, "action: 'leave.workEntry.locate'"));
    $surface = file_get_contents($directory.'/resources/js/resources/work-entries/surface/WorkEntryRecordSurface.tsx');
    $section = $surface;
    assert(! is_file($directory.'/resources/js/resources/work-entries/section/WorkEntryFormSection.tsx'));
    assert(! is_file($directory.'/resources/js/resources/work-entries/section/WorkEntryListSection.tsx'));
    assert(! str_contains($surface, 'useRegisterTabActions'));
    assert(str_contains($module, "shapes: ['list', 'record']"));
    assert(! is_file($directory.'/resources/js/resources/work-entries/surface/WorkEntryShowSurface.tsx'));
    assert(! is_file($directory.'/resources/js/resources/work-entries/surface/WorkEntryFormSurface.tsx'));
    assert(! file_exists($directory.'/resources/js/resources/work-entries/work-entry-information-schema.tsx'));
    assert(str_contains($section, 'NxFormField layout="row" fieldKey="name"'));
    assert(! str_contains($section, 'NxResourceInformationForm'));
    assert(str_contains($section, 'current.name === current.baseline'));
    assert(str_contains($section, "binding={{ effect: 'draft', disabled: pending || submitDisabled, onSaved:"));
    $run(['make:app', 'LeaveManager', '--vendor=other', '--family=people', '--key=leave']);
    assert(is_file($root.'/other/leave/composer.json'));
    $preflight = $root.'/other/leave';
    $snapshot = static function (string $path): array {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($path))] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);

        return $files;
    };
    // Regeneration must preserve business code, registrations and persistence history.
    $controllerPath = $directory.'/src/Http/Controllers/RequestController.php';
    file_put_contents($controllerPath, file_get_contents($controllerPath)."\n// App-owned customization.\n");
    $before = $snapshot($directory);
    $run(['make:resource', 'acme/leave', 'Request', '--label-ko=휴가 신청']);
    assert($snapshot($directory) === $before);
    $output = $run(['make:resource', 'acme/leave', 'Request', '--label-ko=휴가 신청', '--record-owner=tenant', '--force'], false);
    assert(str_contains($output, 'Refusing to change record ownership'));
    assert($snapshot($directory) === $before);

    $translatedCatalogPath = $preflight.'/resources/lang/ko.json';
    $translatedCatalog = json_decode(file_get_contents($translatedCatalogPath), true, flags: JSON_THROW_ON_ERROR);
    $translatedCatalog['permissions.groups.leave.label'] = '휴가 관리';
    file_put_contents($translatedCatalogPath, json_encode($translatedCatalog, JSON_THROW_ON_ERROR));
    $run(['make:resource', 'other/leave', 'Invoice', '--label-ko=청구서', '--icon=ticket', '--sort=250']);
    $invoiceModulePath = $preflight.'/src/Contribution/Resources/InvoiceModule.php';
    $invoiceModule = file_get_contents($invoiceModulePath);
    assert(str_contains($invoiceModule, "'icon' => 'ticket',"));
    $menu = array_column(json_decode(file_get_contents($preflight.'/nexia.json'), true)['navigation'], null, 'screen');
    assert($menu['leave-invoices']['sort'] === 250 && $menu['leave-invoices']['icon'] === 'ticket');
    $run(['make:resource', 'other/leave', 'WorkOrder', '--label-ko=작업 지시', '--without-navigation']);
    $workOrderModule = file_get_contents($preflight.'/src/Contribution/Resources/WorkOrderModule.php');
    $menu = array_column(json_decode(file_get_contents($preflight.'/nexia.json'), true)['navigation'], null, 'screen');
    assert(! isset($menu['leave-work-orders']));
    assert(str_contains($workOrderModule, "'visible' => true,"));
    assert(str_contains(file_get_contents($preflight.'/routes/routes.php'), "[WorkOrderController::class, 'index']"));
    $run(['make:resource', 'other/leave', 'Invoice', '--label-ko=청구서', '--icon=ticket', '--force']);
    assert(file_get_contents($invoiceModulePath) === $invoiceModule);
    assert(json_decode(file_get_contents($translatedCatalogPath), true, flags: JSON_THROW_ON_ERROR)['permissions.groups.leave.label'] === '휴가 관리');
    $before = $snapshot($preflight);
    $run(['make:resource', 'other/leave', 'Invalid', '--label-ko=거절', '--sort=first'], false);
    $run(['make:resource', 'other/leave', 'Invalid', '--label-ko=거절', '--record-owner=workspace'], false);
    assert($snapshot($preflight) === $before);
    $pageEntryPath = $preflight.'/resources/js/index.ts';
    $pageEntry = file_get_contents($pageEntryPath);
    file_put_contents($pageEntryPath, str_replace('// nexia:pages:end', '// custom pages', $pageEntry));
    $before = $snapshot($preflight);
    $run(['make:page', 'other/leave', 'BlockedPage', '--label-ko=거절'], false);
    assert($snapshot($preflight) === $before);
    file_put_contents($pageEntryPath, $pageEntry);
    $legacySurfaceDirectory = $preflight.'/resources/js/resources/legacy-notes/surface';
    mkdir($legacySurfaceDirectory, 0700, true);
    file_put_contents($legacySurfaceDirectory.'/LegacyNoteShowSurface.tsx', '// Existing custom screen');
    $before = $snapshot($preflight);
    $run(['make:resource', 'other/leave', 'LegacyNote', '--label-ko=기존 노트', '--force'], false);
    assert($snapshot($preflight) === $before);
    $routePath = $preflight.'/routes/routes.php';
    $originalRoutes = file_get_contents($routePath);
    file_put_contents($routePath, str_replace('// nexia:make-package-resource:routes:end', '// custom routes', $originalRoutes));
    $before = $snapshot($preflight);
    $run(['make:resource', 'other/leave', 'Blocked', '--label-ko=거절'], false);
    assert($snapshot($preflight) === $before); // A late route error must not leave early source files.
    file_put_contents($routePath, $originalRoutes);
    $localePath = $preflight.'/resources/lang/ko.json';
    $originalLocale = file_get_contents($localePath);
    file_put_contents($localePath, '{ invalid json');
    $before = $snapshot($preflight);
    $run(['make:resource', 'other/leave', 'Blocked', '--label-ko=거절'], false);
    assert($snapshot($preflight) === $before); // Translation validation also precedes all writes.
    file_put_contents($localePath, $originalLocale);
    $entryPath = $preflight.'/resources/js/index.ts';
    assert(! str_contains(file_get_contents($entryPath), 'frontend-inspector-imports:end'));
    $run(['make:resource', 'other/leave', 'Allowed', '--label-ko=허용']);
    assert(is_file($preflight.'/src/Models/Allowed.php'));

    $run(['make:app', 'LocalizedApp', '--vendor=example', '--family=people', '--display-name=현지화 앱']);
    $localized = json_decode(file_get_contents($root.'/example/localized-app/nexia.json'), true, flags: JSON_THROW_ON_ERROR);
    assert($localized['app']['app_name'] === '현지화 앱');
    assert(is_file($root.'/example/localized-app/database/migrations/tenant/.gitkeep'));
    $run(['make:app', 'BadApp', '--vendor=../escape', '--family=people'], false);
    assert(! file_exists($root.'/escape'));
    // The generator must never execute the target project's PHP bootstrap.
    file_put_contents($directory.'/bootstrap.php', '<?php throw new RuntimeException("Do not execute App bootstrap");');
    $external = $root.'/outside-models';
    mkdir($external);
    rename($directory.'/src/Models', $directory.'/src/Models-preserved');
    symlink($external, $directory.'/src/Models');
    $run(['make:resource', 'acme/leave', 'Escaped', '--label-ko=차단'], false);
    assert(glob($external.'/*') === []);
    assert(! is_file($directory.'/src/Enums/EscapedStatus.php'));
    unlink($directory.'/src/Models');
    rename($directory.'/src/Models-preserved', $directory.'/src/Models');
    $composerFile = $directory.'/composer.json';
    rename($composerFile, $directory.'/composer-retained.json');
    symlink($directory.'/composer-retained.json', $composerFile);
    $run(['make:resource', 'acme/leave', 'Escaped', '--label-ko=차단'], false);
    $run(['validate', 'acme/leave'], false);
    unlink($composerFile);
    rename($directory.'/composer-retained.json', $composerFile);
    $run(['make:resource', 'acme/leave', 'Balance', '--label-ko=잔여 휴가']);
    $sourceArgs = ['make:signature-data-source', 'acme/leave', 'SelectedRequest', '--subject-resource-key=leave.request', '--source-resource-key=leave.request'];
    $run($sourceArgs);
    assert(! file_exists($directory.'/src/Signature/SelectedRequestSignatureDocumentDataSourceProvider.php'));
    $run([...$sourceArgs, '--write']);
    assert(str_contains(file_get_contents($directory.'/src/Signature/SelectedRequestSignatureDocumentDataSourceProvider.php'), 'namespace Nexia\\Apps\\Acme\\LeaveManager\\Signature;'));
    $before = $snapshot($directory);
    $run([...$sourceArgs, '--write'], false);
    $run([...$sourceArgs, '--force'], false);
    assert($snapshot($directory) === $before);
    $manySourceArgs = ['make:signature-data-source', 'acme/leave', 'AssignedAssets', '--source-version=2', '--subject-resource-key=people.employment_contract', '--source-resource-key=leave.asset_assignment', '--cardinality=many', '--min-items=0', '--max-items=12', '--field-key=asset_ref', '--field-classification=confidential'];
    $run([...$manySourceArgs, '--write']);
    $descriptor = file_get_contents($directory.'/src/Descriptors/AssignedAssetsSignatureDocumentDataSource.php');
    $provider = file_get_contents($directory.'/src/Signature/AssignedAssetsSignatureDocumentDataSourceProvider.php');
    assert(str_contains($descriptor, 'sourceVersion: AssignedAssetsSignatureDocumentDataSourceProvider::SOURCE_VERSION'));
    assert(str_contains($descriptor, "supportedSubjectResourceKeys: ['people.employment_contract']"));
    assert(str_contains($descriptor, 'SignatureDataClassification::Confidential'));
    assert(str_contains($descriptor, "stableSortKey: 'stable_order'"));
    foreach (['private const MIN_ITEMS = 0;', 'private const MAX_ITEMS = 12;', 'private const LOOKUP_TIMEOUT_SECONDS = 5;', 'Reauthorize the exact actor', 'tenant and Legal Entity ownership', 'tests/Feature/Signature/', 'return self::unavailable(SignatureDocumentDataDiagnosticCode::Forbidden);'] as $required) {
        assert(str_contains($provider, $required));
    }
    foreach (['App\\', 'getFillable', 'getCasts', 'getColumnListing', 'getSchemaBuilder', 'Illuminate\\Database'] as $forbidden) {
        assert(! str_contains($descriptor.$provider, $forbidden));
    }
    $before = $snapshot($directory);
    assert(str_contains($run([...$manySourceArgs, '--max-items=51', '--write', '--force'], false), 'item bounds are invalid'));
    assert(str_contains($run([...$manySourceArgs, '--field-key=stable_order', '--stable-sort-key=stable_order', '--write', '--force'], false), 'to be different'));
    assert($snapshot($directory) === $before);
    $run([...$sourceArgs, "--label-key=leave.bad'key", '--write', '--force'], false);
    $run(['validate', 'acme/leave']);
    $nativeComposer = json_decode(file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
    $nativeManifest = json_decode(file_get_contents($directory.'/nexia.json'), true, flags: JSON_THROW_ON_ERROR);
    $legacyComposer = $nativeComposer;
    $legacyComposer['extra']['nexia'] = array_diff_key($nativeManifest, array_flip(['schema_version', 'runtime']));
    file_put_contents($composerFile, json_encode($legacyComposer, JSON_THROW_ON_ERROR));
    $run(['validate', 'acme/leave'], false);
    unlink($directory.'/nexia.json');
    assert(str_contains($run(['validate', 'acme/leave'], false), 'extra.nexia is unsupported'));
    file_put_contents($composerFile, json_encode($nativeComposer, JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/nexia.json', '{');
    $run(['validate', 'acme/leave'], false);
    file_put_contents($directory.'/nexia.json', json_encode([...$nativeManifest, 'schema_version' => '3'], JSON_THROW_ON_ERROR));
    $run(['validate', 'acme/leave'], false);
    file_put_contents($directory.'/nexia.json', json_encode($nativeManifest, JSON_THROW_ON_ERROR));
    $catalogFile = $directory.'/resources/lang/ko.json';
    $catalogSource = file_get_contents($catalogFile);
    foreach (['{"leave.note.label": 17}', '{"leave.note.label":"첫째","leave.note.label":"둘째"}'] as $invalidCatalog) {
        file_put_contents($catalogFile, $invalidCatalog);
        $run(['validate', 'acme/leave'], false);
    }
    file_put_contents($catalogFile, $catalogSource);
    symlink($directory.'/composer.json', $directory.'/resources/lang/invalid.json');
    $run(['validate', 'acme/leave'], false);
    unlink($directory.'/resources/lang/invalid.json');
    file_put_contents($directory.'/src/Bad.php', '<?php this is invalid PHP');
    $run(['validate', 'acme/leave'], false);
    unlink($directory.'/src/Bad.php');
    symlink($directory.'/composer.json', $directory.'/src/external.php');
    $run(['validate', 'acme/leave'], false);
    echo "Standalone App and resource generation, identity, dry-run and no-overwrite checks passed.\n";
} finally {
    (new Filesystem)->deleteDirectory($root);
}
