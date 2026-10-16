<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Nexia\Devtools\Validation\AppSurfaceStandardScanner;

foreach (['../Figures', '#app/Figures'] as $import) {
    $root = sys_get_temp_dir().'/nexia-surface-standard-'.bin2hex(random_bytes(8));
    $js = $root.'/resources/js';
    mkdir($js, 0777, true);
    mkdir($js.'/overview', 0777, true);
    mkdir($root.'/src', 0777, true);

    file_put_contents($js.'/index.ts', <<<'TS'
export const app = {
    overview: () => import('./overview/EmptyOverviewSurface'),
    overrides: { WorkSurface: () => import('./WorkSurface'), MixedSurface: () => import('./MixedSurface') },
};
TS);
    file_put_contents($js.'/overview/EmptyOverviewSurface.tsx', "import Figures from '{$import}';\nexport default function OverviewSurface() { return <Figures />; }");
    file_put_contents($js.'/Figures.tsx', 'export default function Figures() { return <NxStatCard value={3} />; }');
    file_put_contents($js.'/ImportPreviewSurface.tsx', <<<'TSX'
export function ImportPreviewSurface() {
    useMutation();
    return <>
        <NxTextInput />
        <ResourceTable label="Preview" rows={[]} columns={[]} getKey={() => "row"} />
        <NxDialog footer={<NxButton>Save</NxButton>} />
    </>;
}
TSX);
    file_put_contents($js.'/HostedCreateListSurface.tsx', <<<'TSX'
export function HostedCreateListSurface() {
    useMutation();
    return <><NxTextInput /><ResourceTable toolbar label="Records" rows={[]} columns={[]} getKey={() => "row"} /></>;
}
TSX);
    file_put_contents($js.'/ModalActionListSurface.tsx', <<<'TSX'
export function ModalActionListSurface() {
    useMutation();
    return <><ResourceTable toolbar label="Records" rows={[]} columns={[]} getKey={() => "row"} /><NxModalDialog><NxTextInput /></NxModalDialog></>;
}
TSX);

    file_put_contents($root.'/src/ExampleAppManifest.php', <<<'PHP'
<?php
final class ExampleAppManifest {
    public static function navigation() {
        return [NavigationItem::make(route: '/apps/example/review', groupId: 'management'), NavigationItem::make(route: '/apps/example/assign', groupId: 'management'), NavigationItem::make(route: '/apps/example/manage-mixed', groupId: 'management'), NavigationItem::make(route: '/apps/example/browse-mixed', groupId: 'operations')];
    }
    public static function pageElementsExtras() {
        return ['/apps/example/review' => 'WorkSurface', '/apps/example/assign' => 'WorkSurface', '/apps/example/manage-mixed' => 'MixedSurface', '/apps/example/browse-mixed' => 'MixedSurface'];
    }
}
PHP);
    file_put_contents($js.'/WorkSurface.tsx', <<<'TSX'
export default function WorkSurface() {
    useMutation();
    return <><NxTextInput /><ResourceTable toolbar label="Review" rows={[]} columns={[]} getKey={() => "row"} /></>;
}
TSX);

    file_put_contents($js.'/MixedSurface.tsx', str_replace('WorkSurface', 'MixedSurface', file_get_contents($js.'/WorkSurface.tsx')));
    file_put_contents($js.'/ResolvedLabelSurface.tsx', 'const label = item.reference.display ?? context.initialLabels?.employee_public_id;');
    file_put_contents($js.'/RawLabelSurface.tsx', 'const label = item.name ?? item.public_id;');

    try {
        $all = (new AppSurfaceStandardScanner($root, 'example'))->scan();
        $violations = array_values(array_filter(
            $all,
            static fn (array $violation): bool => $violation['rule'] === 'list-hosts-create-form',
        ));

        assert(count($all) === 4);
        assert(count($violations) === 2);
        assert(array_map(static fn (array $violation): string => basename($violation['file']), $violations) === ['HostedCreateListSurface.tsx', 'MixedSurface.tsx']);
        assert(str_ends_with(array_values(array_filter($all, static fn (array $violation): bool => $violation['rule'] === 'record-identifier-as-label'))[0]['file'], 'RawLabelSurface.tsx'));

    } finally {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
echo "Independent App surface standards and both local import forms passed.\n";
