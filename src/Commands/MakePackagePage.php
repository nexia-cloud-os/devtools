<?php

declare(strict_types=1);

namespace Nexia\Devtools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Nexia\AppRuntime\AppPackageMetadataReader;
use Nexia\Devtools\Commands\Concerns\InteractsWithPackageApps;
use RuntimeException;
use Throwable;

/** A business page owns its queries; it does not imply a model or Resource. */
final class MakePackagePage extends Command
{
    use InteractsWithPackageApps;

    protected $signature = 'make:page {package : Existing App directory} {name : PascalCase page name}
        {--label-ko= : Korean page label}
        {--navigation-group=operations : App navigation group}
        {--sort=100 : App navigation order}
        {--without-record : Omit the related read/edit routes and record surface}
        {--without-navigation : Do not add a sidebar item}
        {--dry-run : Inspect all planned files without writing}';

    protected $description = 'Add a business page, controller, query and permission/navigation contribution without creating a Resource';

    public function handle(AppPackageMetadataReader $reader): int
    {
        try {
            $package = $this->resolvePackageApp((string) $this->argument('package'), $reader);
            if ($package === null) {
                throw new RuntimeException('Choose an existing Nexia App directory.');
            }
            $jsonMenu = array_key_exists('navigation', $reader->declaration($package['directory']));
            $name = (string) $this->argument('name');
            $label = trim((string) $this->option('label-ko'));
            $group = (string) $this->option('navigation-group');
            $sort = (string) $this->option('sort');
            if (! preg_match('/\A[A-Z][A-Za-z0-9]*\z/D', $name)
                || $label === '' || mb_strlen($label) > 160
                || ! in_array($group, ['insights', 'management', 'operations', 'master-data', 'settings'], true)
                || ! preg_match('/\A[0-9]{1,6}\z/D', $sort)) {
                throw new RuntimeException('Use a PascalCase name, --label-ko, a supported navigation group and a nonnegative sort order.');
            }
            token_get_all("<?php class {$name} {}", TOKEN_PARSE);
            $directory = $package['directory'];
            $definition = $package['definition'];
            $appKey = $definition->appKey;
            $prefix = Str::studly($appKey);
            $slug = Str::kebab($name);
            $key = Str::snake($name);
            $namespace = $this->manifestNamespace($definition);
            $replacements = [
                '{{ packageNamespace }}' => $namespace, '{{ appKey }}' => $appKey,
                '{{ PageName }}' => $name, '{{ pageSlug }}' => $slug,
                '{{ pageKey }}' => $key, '{{ pageCamel }}' => Str::camel($name),
                '{{ navigationGroup }}' => $jsonMenu ? 'operations' : $group, '{{ sort }}' => $jsonMenu ? '100' : (string) (int) $sort,
            ];
            $withRecord = ! (bool) $this->option('without-record');
            $replacements['{{ recordImport }}'] = $withRecord ? "import { Link } from 'react-router-dom';" : '';
            $replacements['{{ recordLink }}'] = $withRecord
                ? "<Link to={`/apps/{$appKey}/{$slug}/\${encodeURIComponent(item.id)}`}>{item.label}</Link>"
                : '{item.label}';
            $planned = [];
            foreach ([
                'controller' => "src/Http/Controllers/{$name}Controller.php",
                'contribution' => "src/Contribution/Pages/{$name}Module.php",
                'queries' => "resources/js/pages/{$slug}-queries.ts",
                'surface' => "resources/js/pages/{$name}Surface.tsx",
                ...($withRecord ? ['record-surface' => "resources/js/pages/{$name}RecordSurface.tsx"] : []),
            ] as $stub => $target) {
                if (file_exists($directory.'/'.$target)) {
                    throw new RuntimeException("Page target already exists: {$target}. Existing business code is never overwritten.");
                }
                $planned[$target] = strtr($this->read(dirname(__DIR__, 2)."/stubs/package-page/{$stub}.stub"), $replacements);
            }
            if ($withRecord) {
                $planned["resources/js/pages/{$slug}-queries.ts"] .= strtr($this->read(dirname(__DIR__, 2).'/stubs/package-page/record-queries.stub'), $replacements);
                $planned["src/Http/Controllers/{$name}Controller.php"] = substr(rtrim($planned["src/Http/Controllers/{$name}Controller.php"]), 0, -1)
                    .strtr($this->read(dirname(__DIR__, 2).'/stubs/package-page/record-controller.stub'), $replacements)."}\n";
                $planned["src/Contribution/Pages/{$name}Module.php"] = str_replace(
                    "'read', assignmentScope: AssignmentScope::Tenant)]",
                    "'read', assignmentScope: AssignmentScope::Tenant), PermissionDefinition::make('{$appKey}', '{$key}', 'update', assignmentScope: AssignmentScope::Tenant)]",
                    $planned["src/Contribution/Pages/{$name}Module.php"],
                );
            }
            if ($this->option('without-navigation') && ! $jsonMenu) {
                $planned["src/Contribution/Pages/{$name}Module.php"] = preg_replace('/return \[NavigationItem::make\(.*?\)\];/s', 'return [];', $planned["src/Contribution/Pages/{$name}Module.php"]);
            }
            $routes = $this->read($directory.'/routes/routes.php');
            if (! str_contains($routes, "app.installed:{$appKey}")) {
                throw new RuntimeException('App routes must retain their installation guard.');
            }
            $controller = "\\{$namespace}\\Http\\Controllers\\{$name}Controller::class";
            $planned['routes/routes.php'] = $this->insert($routes,
                '// nexia:make-package-resource:routes:end',
                "Route::get('api/{$appKey}/pages/{$slug}', [{$controller}, 'index'])->middleware('can.tenant_wide:{$appKey}.{$key}.read');",
            );
            if ($withRecord) {
                $planned['routes/routes.php'] = $this->insert($planned['routes/routes.php'], '// nexia:make-package-resource:routes:end',
                    "Route::get('api/{$appKey}/pages/{$slug}/{id}', [{$controller}, 'show'])->middleware('can.tenant_wide:{$appKey}.{$key}.read');\n"
                    ."            Route::put('api/{$appKey}/pages/{$slug}/{id}', [{$controller}, 'update'])->middleware('can.tenant_wide:{$appKey}.{$key}.update');");
            }
            $manifestPath = 'src/'.$this->manifestClassName($definition).'.php';
            $route = "/apps/{$appKey}/{$slug}";
            $manifest = $this->read($directory.'/'.$manifestPath);
            if (str_contains($manifest, "'{$route}'")) {
                throw new RuntimeException("Page route already exists: {$route}.");
            }
            $planned[$manifestPath] = $this->insert($manifest, '// nexia:pages:end', "'{$route}' => '{$prefix}{$name}Surface',");
            $entry = $this->read($directory.'/resources/js/index.ts');
            $planned['resources/js/index.ts'] = $this->insert($entry, '// nexia:pages:end',
                "'{$prefix}{$name}Surface': () => import('./pages/{$name}Surface'),");
            if ($withRecord) {
                $planned[$manifestPath] = $this->insert($planned[$manifestPath], '// nexia:pages:end',
                    "'{$route}/:id' => '{$prefix}{$name}ReadSurface',\n            '{$route}/:id/edit' => '{$prefix}{$name}EditSurface',");
                $planned['resources/js/index.ts'] = $this->insert($planned['resources/js/index.ts'], '// nexia:pages:end',
                    "'{$prefix}{$name}ReadSurface': { load: () => import('./pages/{$name}RecordSurface'), mode: 'read' },\n"
                    ."            '{$prefix}{$name}EditSurface': { load: () => import('./pages/{$name}RecordSurface'), mode: 'edit' },");
            }
            foreach (['en' => Str::headline($name), 'ko' => $label, 'zh' => Str::headline($name)] as $locale => $title) {
                $target = "resources/lang/{$locale}.json";
                $catalog = json_decode($this->read($directory.'/'.$target), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($catalog) || array_is_list($catalog)) {
                    throw new RuntimeException("Expected a flat translation catalog: {$target}.");
                }
                $translation = "{$appKey}.pages.{$key}.title";
                if (array_key_exists($translation, $catalog)) {
                    throw new RuntimeException("Translation already exists: {$translation}.");
                }
                $catalog[$translation] = $title;
                $permission = "permissions.keys.{$appKey}.{$key}.read";
                foreach (['label' => $locale === 'ko' ? "{$title} 조회" : "View {$title}",
                    'description' => $locale === 'ko' ? "{$title} 페이지 조회를 허용합니다." : "Allows viewing the {$title} page."] as $field => $value) {
                    if (array_key_exists($permission.'.'.$field, $catalog)) {
                        throw new RuntimeException("Permission translation already exists: {$permission}.{$field}.");
                    }
                    $catalog[$permission.'.'.$field] = $value;
                }
                if ($withRecord) {
                    $sectionKey = "{$appKey}.pages.{$key}.sections.basics";
                    if (array_key_exists($sectionKey, $catalog)) {
                        throw new RuntimeException("Translation already exists: {$sectionKey}.");
                    }
                    $catalog[$sectionKey] = match ($locale) {
                        'ko' => '기본 정보',
                        'zh' => '基本信息',
                        default => 'Basic information',
                    };
                    foreach (['label' => $locale === 'ko' ? "{$title} 수정" : "Edit {$title}",
                        'description' => $locale === 'ko' ? "{$title} 수정을 허용합니다." : "Allows updating {$title}."] as $field => $value) {
                        $permissionKey = "permissions.keys.{$appKey}.{$key}.update.{$field}";
                        if (array_key_exists($permissionKey, $catalog)) {
                            throw new RuntimeException("Permission translation already exists: {$permissionKey}.");
                        }
                        $catalog[$permissionKey] = $value;
                    }
                }
                ksort($catalog, SORT_STRING);
                $planned[$target] = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            }
            $menu = $this->plannedNavigation($directory, [
                'screen' => "{$appKey}.page.{$key}", 'group' => $group, 'sort' => (int) $sort,
                'icon' => 'layout-dashboard',
            ], (bool) $this->option('without-navigation'));
            if ($menu !== null) {
                $planned['nexia.json'] = $menu;
            }
            // Resolve every target and registration before the first write.
            foreach ($planned as $target => $content) {
                $this->line(($this->option('dry-run') ? 'WOULD WRITE ' : 'WRITE ').$target);
                if ($this->option('dry-run')) {
                    continue;
                }
                $parent = dirname($directory.'/'.$target);
                if (! is_dir($parent) && ! mkdir($parent, 0755, true)) {
                    throw new RuntimeException("Cannot create directory for {$target}.");
                }
                if (file_put_contents($directory.'/'.$target, $content) === false) {
                    throw new RuntimeException("Cannot write {$target}; inspect earlier reported writes.");
                }
            }
            $this->info('Page scaffold prepared. Implement its business query and display; no model, migration or Resource was created.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function read(string $path): string
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new RuntimeException("Required scaffold file is missing: {$path}.");
        }

        return $content;
    }

    private function insert(string $source, string $marker, string $line): string
    {
        if (substr_count($source, $marker) !== 1) {
            throw new RuntimeException("Expected exactly one {$marker} registration marker; no files were written.");
        }

        return str_replace($marker, $line."\n            ".$marker, $source);
    }
}
