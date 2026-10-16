<?php

declare(strict_types=1);

namespace Nexia\Devtools\Validation;

/** Static surface standards for one independent App; never loads App PHP. */
final class AppSurfaceStandardScanner
{
    private const DATA_WIDGETS = ['<NxStatCard', '<NxOverviewBand', '<NxBarChart', '<NxLineChart', '<NxDonutChart', '<ResourceTable'];

    private const FORM_INPUTS = '/<(NxTextInput|NxTextArea|NxSelect|NxCombobox|NxCheckbox|NxRadioGroup|NxDatePicker|NxFormField|NxFieldset|NxNumberInput)\b/';

    private const LINK_ROWS = '/<(NxSectionRowButton|NxSectionRow)\b/';

    private const MUTATIONS = '/(useMutation|api\.(post|put|patch|delete))\b/';

    public function __construct(private readonly string $root, private readonly string $appKey) {}

    /**
     * @return list<array{app: string, file: string, rule: string, detail: string}>
     */
    public function scan(): array
    {
        $violations = [];

        foreach ($this->apps() as $app => $directory) {
            $violations = array_merge(
                $violations,
                $this->hubSurfaces($app, $directory),
                $this->insightsBrowsingCollections($app, $directory),
                $this->insightsWithoutChart($app, $directory),
                $this->settingsHubDestinations($app, $directory),
                $this->overviewsWithoutFigures($app, $directory),
                $this->sharedDestinationSurfaces($app, $directory),
                $this->handRolledCollections($app, $directory),
                $this->listsHostingCreateForms($app, $directory),
                $this->tablesInsideCards($app, $directory),
                $this->createRoutesSharingTheList($app, $directory),
                $this->recordIdentifiersOnScreen($app, $directory),
            );
        }

        return $violations;
    }

    /**
     * A destination whose whole content is links to other destinations. Those
     * destinations belong in the App Menu, so the page adds a click and no
     * information.
     *
     * @return list<array{app: string, file: string, rule: string, detail: string}>
     */
    private function hubSurfaces(string $app, string $directory): array
    {
        $violations = [];

        foreach ($this->surfaceFiles($directory) as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match(self::LINK_ROWS, $source) !== 1) {
                continue;
            }
            if ($this->rendersData($source) || $this->acceptsInput($source)) {
                continue;
            }
            $links = preg_match_all("#'/apps/".preg_quote($app, '#').'#', $source);
            if ($links < 2) {
                continue;
            }

            $violations[] = [
                'app' => $app,
                'file' => $this->relative($file),
                'rule' => 'hub-surface',
                'detail' => "renders {$links} destination links and no data of its own; register those destinations in the App Menu instead",
            ];
        }

        return $violations;
    }

    /**
     * An Insights page presents an aggregate. A browsable collection with
     * search, sort, and pagination is a Resource list, and mixing the two hides
     * which page owns the rows.
     *
     * @return list<array{app: string, file: string, rule: string, detail: string}>
     */
    private function insightsBrowsingCollections(string $app, string $directory): array
    {
        $violations = [];

        foreach ($this->surfaceFiles($directory) as $file) {
            if (preg_match('/insight/i', $file) !== 1) {
                continue;
            }
            $source = (string) file_get_contents($file);
            if (! str_contains($source, '<ResourceTable')) {
                continue;
            }
            if (! $this->rendersListResourceTable($source)) {
                continue;
            }

            $violations[] = [
                'app' => $app,
                'file' => $this->relative($file),
                'rule' => 'insights-resource-table',
                'detail' => 'an Insights page enables ResourceTable list chrome; omit toolbar/footer/pagination for the aggregate and link to the owning list',
            ];
        }

        return $violations;
    }

    /**
     * A registered Insights surface must make its aggregate relationship
     * visible. Summary cards plus a table are not enough: without a chart the
     * page is a report-shaped Resource list. Apps with no registered Insights
     * destination document that composition decision in PAGES.md and never
     * mount NxInsightSurface, so they are outside this rule.
     *
     * @return list<array{app: string, file: string, rule: string, detail: string}>
     */
    private function insightsWithoutChart(string $app, string $directory): array
    {
        $violations = [];

        foreach ($this->surfaceFiles($directory) as $file) {
            $source = (string) file_get_contents($file);
            if (! str_contains($source, '<NxInsightSurface')) {
                continue;
            }
            if (preg_match('/<(NxBarChart|NxLineChart|NxDonutChart)\b/', $source) === 1) {
                continue;
            }

            $violations[] = [
                'app' => $app,
                'file' => $this->relative($file),
                'rule' => 'insights-without-chart',
                'detail' => 'an NxInsightSurface renders no chart; chart the owned aggregate or remove the unowned Insights destination and record that decision in PAGES.md',
            ];
        }

        return $violations;
    }

    /**
     * `settings` is an App Menu group holding one destination per policy or
     * configuration Resource, not a single page that links to them.
     *
     * @return list<array{app: string, file: string, rule: string, detail: string}>
     */
    private function settingsHubDestinations(string $app, string $directory): array
    {
        $violations = [];
        $source = dirname($directory, 2).'/src';
        if (! is_dir($source)) {
            return $violations;
        }

        // A route reaches the shell through a navigation contribution or through
        // `pageElementsExtras()`. Reading only the first left every extras-
        // registered hub reachable by URL while this rule reported nothing.
        foreach ($this->phpFiles($source) as $file) {
            $contents = (string) file_get_contents($file);
            $route = '/apps/'.$app.'/settings';
            $declared = preg_match("#route:\s*'".preg_quote($route, '#')."'#", $contents) === 1;
            $registered = preg_match("#'".preg_quote($route, '#')."'\s*=>#", $contents) === 1;
            if (! $declared && ! $registered) {
                continue;
            }

            $violations[] = [
                'app' => $app,
                'file' => $this->relative($file),
                'rule' => 'settings-hub-destination',
                'detail' => "registers {$route}; settings is a menu group holding one destination per configuration Resource, not a page that links to them",
            ];
        }

        return $violations;
    }

    /**
     * One surface serving several collection-level destinations is a workbench
     * wearing many names: every route shows the same page, which is why those
     * destinations read as duplicates. Create and edit routes legitimately
     * share a form, so only parameterless, non-`/new` routes count.
     *
     * @return list<array{app: string, file: string, rule: string, detail: string}>
     */
    private function sharedDestinationSurfaces(string $app, string $directory): array
    {
        $source = dirname($directory, 2).'/src';
        if (! is_dir($source)) {
            return [];
        }

        /** @var array<string, list<string>> $byComponent */
        $byComponent = [];
        foreach ($this->phpFiles($source) as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/pageElementsExtras\(\).*?return \[(.*?)\];/s', $contents, $block) !== 1) {
                continue;
            }
            if (preg_match_all("/'([^']+)'\s*=>\s*'([^']+)'/", $block[1], $matches, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($matches as $match) {
                [, $route, $component] = $match;
                // Record routes (`:id`, `{public_id}`) and create routes share
                // their collection's surface by design: list, form, and detail
                // are one page kind.
                if (str_contains($route, ':') || str_contains($route, '{') || str_ends_with($route, '/new')) {
                    continue;
                }
                $byComponent[$component][] = $route;
            }
        }

        $violations = [];
        $management = $this->managementFiles($directory);
        $index = is_file($directory.'/index.ts') ? (string) file_get_contents($directory.'/index.ts') : '';
        foreach ($byComponent as $component => $routes) {
            if (preg_match('/'.preg_quote($component, '/').':\s*\(\)\s*=>\s*import\([\'\"]([^\'\"]+)[\'\"]\)/', $index, $entry) === 1) {
                $file = $this->resolveImport($directory, $entry[1]);
                if ($file !== null && isset($management[$file])) {
                    continue;
                }
            }
            $routes = array_values(array_unique($routes));
            if (count($routes) < 2) {
                continue;
            }
            sort($routes);

            $violations[] = [
                'app' => $app,
                'file' => "resources/js::{$component}",
                'rule' => 'shared-destination-surface',
                'detail' => count($routes).' destinations render the same surface ('.implode(', ', $routes).'); give each destination its own page kind',
            ];
        }

        return $violations;
    }

    /**
     * The Overview page kind carries figures that open a list. Without them the
     * App root is either blank or a second copy of the App Menu.
     *
     * @return list<array{app: string, file: string, rule: string, detail: string}>
     */
    private function overviewsWithoutFigures(string $app, string $directory): array
    {
        $index = $directory.'/index.ts';
        if (! is_file($index)) {
            return [];
        }
        if (preg_match('/overview:\s*\(\)\s*=>\s*import\(\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents($index), $match) !== 1) {
            return [];
        }

        $entry = $this->resolveImport($directory, $match[1]);
        if ($entry === null) {
            return [];
        }

        $source = $this->expand($entry);
        if ($this->rendersData($source) || $this->isExplicitlyEmptyOverview($source)) {
            return [];
        }

        return [[
            'app' => $app,
            'file' => $this->relative($entry),
            'rule' => 'overview-without-figures',
            'detail' => 'the App Overview shows no figures (NxStatCard or NxOverviewBand); count the rows of the list each card opens',
        ]];
    }

    /** A scaffolded App owns no resource yet, so its landing page is explicit emptiness. */
    private function isExplicitlyEmptyOverview(string $source): bool
    {
        return str_contains($source, '<NxEmptyView')
            && preg_match('/\bkind\s*=\s*["\']empty["\']/', $source) === 1;
    }

    /**
     * A collection drawn as rows of link buttons instead of `ResourceTable`
     * carries no search, sort, or pagination, and silently truncates. It reads
     * as a list to the user while being none of the four page kinds.
     *
     * @return list<array{app: string, file: string, rule: string, detail: string}>
     */
    private function handRolledCollections(string $app, string $directory): array
    {
        $violations = [];

        foreach ($this->surfaceFiles($directory) as $file) {
            $source = $this->expand($file);
            if (preg_match(self::LINK_ROWS, $source) !== 1) {
                continue;
            }
            if ($this->rendersData($source)) {
                continue;
            }
            // Rows built by mapping over a fetched collection, rather than a
            // fixed set of hand-written entries.
            if (preg_match('/\.(map|slice)\(/', $source) !== 1) {
                continue;
            }
            if (preg_match('/(useQuery|useQueries|api\.get)\b/', $source) !== 1) {
                continue;
            }

            $violations[] = [
                'app' => $app,
                'file' => $this->relative($file),
                'rule' => 'hand-rolled-collection',
                'detail' => 'a fetched collection is drawn as link rows, so it has no search, sort, or pagination; render it with ResourceTable',
            ];
        }

        return $violations;
    }

    /**
     * A list answers "which records exist"; a create form answers "what is the
     * new record". Sharing one screen hides the list behind the form.
     *
     * @return list<array{app: string, file: string, rule: string, detail: string}>
     */
    private function listsHostingCreateForms(string $app, string $directory): array
    {
        $violations = [];
        $management = $this->managementFiles($directory);

        foreach ($this->surfaceFiles($directory) as $file) {
            // A Resource detail page edits its children in place: the child grid
            // plus a modal editor is the documented shape for a parent that must
            // be edited with its children.
            if (str_contains($file, '/section/') || isset($management[realpath($file) ?: $file])) {
                continue;
            }
            $source = $this->withoutModalDialogs((string) file_get_contents($file));
            if (! str_contains($source, '<ResourceTable')) {
                continue;
            }
            // A module may export a list surface and a form surface that answer
            // separate routes. Only one component rendering both is a defect.
            if (! $this->sameComponentRendersBoth($source)) {
                continue;
            }
            $violations[] = [
                'app' => $app,
                'file' => $this->relative($file),
                'rule' => 'list-hosts-create-form',
                'detail' => 'one screen renders a ResourceTable and a submitting form; create belongs on its own /new route',
            ];
        }

        return $violations;
    }

    /**
     * A list-chrome `ResourceTable` already draws its own destination surface.
     * Wrapping that shape in a section card puts a box inside a box and
     * indents every row. A chrome-free auxiliary table is different: the card
     * is its owner frame and the unified ResourceTable supplies only semantic
     * table rendering.
     *
     * @return list<array{app: string, file: string, rule: string, detail: string}>
     */
    private function tablesInsideCards(string $app, string $directory): array
    {
        $violations = [];

        foreach ($this->surfaceFiles($directory) as $file) {
            // A Resource detail page composes child grids inside its sections by
            // design; this rule targets list destinations.
            if (str_contains($file, '/section/')) {
                continue;
            }
            $source = (string) file_get_contents($file);
            // A tabbed operation surface owns phase-specific work tables, not a
            // collection destination. Its surrounding cards are the phase frame.
            if (str_contains($source, '<NxTabNav')) {
                continue;
            }
            if (preg_match_all('/<NxSectionCard\b(?:(?!<\/NxSectionCard>|<NxSectionCard\b).)*?<\/NxSectionCard>/s', $source, $cards) < 1) {
                continue;
            }

            foreach ($cards[0] as $card) {
                if (! str_contains($card, '<ResourceTable')) {
                    continue;
                }
                // `bordered={false}` draws no box, so the card is a heading
                // above the table rather than the nested surface targeted here.
                if (str_contains($card, 'bordered={false}')) {
                    continue;
                }
                // Chrome-free ResourceTable is the replacement for the retired
                // embedded-table API and deliberately keeps its owner frame.
                if (! $this->rendersListResourceTable($card)) {
                    continue;
                }

                $violations[] = [
                    'app' => $app,
                    'file' => $this->relative($file),
                    'rule' => 'table-inside-card',
                    'detail' => 'a list-chrome ResourceTable is nested in an NxSectionCard; use WorkSection layout="fullBleed" so the table owns its surface',
                ];
                break;
            }
        }

        return $violations;
    }

    /**
     * Create is its own destination. A contract whose create route equals its
     * list route sends the user to the list and relies on a toggle there.
     *
     * @return list<array{app: string, file: string, rule: string, detail: string}>
     */
    private function createRoutesSharingTheList(string $app, string $directory): array
    {
        $violations = [];
        $resources = $directory.'/resources';
        if (! is_dir($resources)) {
            return $violations;
        }

        foreach ($this->walk($resources) as $file) {
            if (! str_ends_with($file, '-resource-contract.ts')) {
                continue;
            }
            $source = (string) file_get_contents($file);
            if (preg_match('/function \w*CreateRoute\(\)[^{]*\{\s*return\s*(\w*ListRoute\(\))/', $source) !== 1) {
                continue;
            }
            // A generated or derived Resource has no create action, so pointing
            // its create route at the list is not a missing /new destination.
            if (preg_match('/\bcreate:\s*[\'"]/', $source) !== 1) {
                continue;
            }

            $violations[] = [
                'app' => $app,
                'file' => $this->relative($file),
                'rule' => 'create-route-is-the-list',
                'detail' => 'the create route returns the list route; register a /new route so create is its own destination',
            ];
        }

        return $violations;
    }

    /**
     * A public id is a database handle. Falling back to it as a label puts a
     * value the reader cannot recognise, or retype, on screen.
     *
     * @return list<array{app: string, file: string, rule: string, detail: string}>
     */
    private function recordIdentifiersOnScreen(string $app, string $directory): array
    {
        $violations = [];

        foreach ($this->walk($directory) as $file) {
            if (str_contains(basename($file), '.test.') || ! preg_match('/\.tsx?$/', $file)) {
                continue;
            }
            $source = (string) file_get_contents($file);
            // Only a label falling back to the identifier is a defect. Resolving
            // one identifier from another (`parent_public_id ?? parent.public_id`)
            // never reaches the screen.
            preg_match_all('/(name|label|title|code|number|display|descriptor)[\w.?\[\]\'\s]*\?\?\s*([\w.?\[\]\']*public_id)\b/i', $source, $matches);
            $identifiers = array_filter($matches[2], static fn (string $fallback): bool =>
                // Resource form initialLabels maps field keys to resolved labels.
                preg_match('/(?:^|[.?])initialLabels(?:[.?]|\[)/', $fallback) !== 1
            );
            if ($identifiers === []) {
                continue;
            }

            $violations[] = [
                'app' => $app,
                'file' => $this->relative($file),
                'rule' => 'record-identifier-as-label',
                'detail' => 'a label falls back to public_id; fall back to a name, code, or number the reader recognises',
            ];
        }

        return $violations;
    }

    /**
     * True when one component body renders both a ResourceTable and a
     * submitting form, rather than a module exporting a list and a form that
     * answer separate routes.
     */
    private function sameComponentRendersBoth(string $source): bool
    {
        $bodies = preg_split('/\n(?=(?:export )?(?:default )?function )/', $source);
        if ($bodies === false) {
            return false;
        }
        foreach ($bodies as $body) {
            if (
                $this->rendersListResourceTable($body)
                && preg_match(self::FORM_INPUTS, $body) === 1
                && preg_match(self::MUTATIONS, $body) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /** Dialog action forms do not turn the surrounding collection into a create screen. */
    private function withoutModalDialogs(string $source): string
    {
        return preg_replace('/<NxModalDialog\b.*?<\/NxModalDialog>/s', '', $source) ?? $source;
    }

    /**
     * Chrome-free ResourceTables are semantic preview/composition tables, not
     * browsable collection destinations. Only a table that opts into list
     * controls can conflict with a submitting form in the same component.
     */
    private function rendersListResourceTable(string $source): bool
    {
        $offset = 0;
        while (($position = strpos($source, '<ResourceTable', $offset)) !== false) {
            $opening = $this->jsxOpeningTag($source, $position);
            if (preg_match('/\b(toolbar|footer|pagination)(?:\s*=|\s+)/', $opening) === 1) {
                return true;
            }
            $offset = $position + strlen('<ResourceTable');
        }

        return false;
    }

    /**
     * Return one JSX opening tag without being confused by arrow functions,
     * strings, or nested object literals in prop expressions.
     */
    private function jsxOpeningTag(string $source, int $start): string
    {
        $length = strlen($source);
        $braceDepth = 0;
        $quote = null;
        $escaped = false;

        for ($index = $start; $index < $length; $index++) {
            $character = $source[$index];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'" || $character === '`') {
                $quote = $character;

                continue;
            }
            if ($character === '{') {
                $braceDepth++;

                continue;
            }
            if ($character === '}' && $braceDepth > 0) {
                $braceDepth--;

                continue;
            }
            if ($character === '>' && $braceDepth === 0) {
                return substr($source, $start, $index - $start + 1);
            }
        }

        return substr($source, $start);
    }

    private function rendersData(string $source): bool
    {
        foreach (self::DATA_WIDGETS as $widget) {
            if (str_contains($source, $widget)) {
                return true;
            }
        }

        return false;
    }

    private function acceptsInput(string $source): bool
    {
        return preg_match(self::FORM_INPUTS, $source) === 1
            || preg_match(self::MUTATIONS, $source) === 1;
    }

    /**
     * Follows relative and App-local imports so an assembled surface is
     * judged on everything it actually renders.
     *
     * @param  array<string, true>  $seen
     */
    private function expand(string $file, array &$seen = []): string
    {
        $real = realpath($file);
        if ($real === false || isset($seen[$real])) {
            return '';
        }
        $seen[$real] = true;

        $source = (string) file_get_contents($real);
        $expanded = $source;

        if (preg_match_all('/from\s+[\'"]((?:\.|#app\/)[^\'"]+)[\'"]/', $source, $matches) > 0) {
            foreach ($matches[1] as $specifier) {
                $target = $this->resolveImport(dirname($real), $specifier);
                if ($target !== null) {
                    $expanded .= $this->expand($target, $seen);
                }
            }
        }

        return $expanded;
    }

    /** @return array<string, true> Files reachable only from declared management destinations. */
    private function managementFiles(string $directory): array
    {
        $groups = [];
        $routes = [];
        foreach ($this->phpFiles(dirname($directory, 2).'/src') as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/NavigationItem::make\((.*?)\)/s', $source, $items);
            foreach ($items[1] as $item) {
                if (preg_match("/route:\\s*'([^']+)'/", $item, $route) === 1
                    && preg_match("/groupId:\\s*'([^']+)'/", $item, $group) === 1) {
                    $groups[$route[1]] = $group[1];
                }
            }
            if (preg_match('/pageElementsExtras\(\).*?return \[(.*?)\];/s', $source, $block) === 1) {
                preg_match_all("/'([^']+)'\\s*=>\\s*'([^']+)'/", $block[1], $entries, PREG_SET_ORDER);
                foreach ($entries as [, $route, $component]) {
                    $routes[$component][] = $route;
                }
            }
        }
        $index = $directory.'/index.ts';
        if (! is_file($index)) {
            return [];
        }
        preg_match_all('/(\w+):\s*\(\)\s*=>\s*import\([\'\"]([^\'\"]+)[\'\"]\)/', (string) file_get_contents($index), $imports, PREG_SET_ORDER);
        $management = [];
        $other = [];
        foreach ($imports as [, $component, $specifier]) {
            $entry = $this->resolveImport($directory, $specifier);
            if ($entry === null) {
                continue;
            }
            $seen = [];
            $this->expand($entry, $seen);
            $destinations = $routes[$component] ?? [];
            $onlyManagement = $destinations !== [];
            foreach ($destinations as $route) {
                $baseRoute = str_ends_with($route, '/new') ? substr($route, 0, -4) : $route;
                $onlyManagement = $onlyManagement && ($groups[$baseRoute] ?? null) === 'management';
            }
            if ($onlyManagement) {
                $management += $seen;
            } else {
                $other += $seen;
            }
        }

        return array_diff_key($management, $other);
    }

    private function resolveImport(string $from, string $specifier): ?string
    {
        if (str_starts_with($specifier, '#app/')) {
            while (basename($from) !== 'js' || basename(dirname($from)) !== 'resources') {
                $parent = dirname($from);
                if ($parent === $from) {
                    return null;
                }
                $from = $parent;
            }
            $specifier = substr($specifier, strlen('#app/'));
        }

        foreach (['.tsx', '.ts', '/index.tsx', '/index.ts', ''] as $suffix) {
            $candidate = realpath($from.'/'.$specifier.$suffix);
            if ($candidate !== false && is_file($candidate)) {
                $root = realpath($this->root);
                if ($root === false || ! str_starts_with($candidate, $root.'/')) {
                    throw new \RuntimeException('Surface imports must remain inside this App.');
                }

                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function surfaceFiles(string $directory): array
    {
        $files = [];
        foreach ($this->walk($directory) as $file) {
            if (! str_ends_with($file, '.tsx') || str_contains(basename($file), '.test.')) {
                continue;
            }
            $files[] = $file;
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        foreach ($this->walk($directory) as $file) {
            if (str_ends_with($file, '.php')) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function walk(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new \RuntimeException('Surface checks reject symlinks.');
            }
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                $files[] = $item->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return array<string, string> App key to its `resources/js` directory.
     */
    private function apps(): array
    {
        $js = $this->root.'/resources/js';

        return is_dir($js) ? [$this->appKey => $js] : [];
    }

    private function relative(string $path): string
    {
        $root = realpath($this->root);
        $real = realpath($path) ?: $path;

        return $root !== false && str_starts_with($real, $root.'/')
            ? substr($real, strlen($root) + 1)
            : $real;
    }
}
