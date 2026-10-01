import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtemp, mkdir, readFile, readdir, appendFile, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const vite = process.argv[2];
assert(vite && path.isAbsolute(vite), 'Pass an independently installed Vite 7 CLI file path.');
const tool = fileURLToPath(new URL('../bin/nexia-app', import.meta.url));
const root = await mkdtemp(path.join(tmpdir(), 'nexia-frontend-check-'));
const app = path.join(root, 'app');
try {
    for (const args of [
        ['make:app', 'FrontendProof', '--vendor=example', '--family=other', `--directory=${app}`],
        ['make:resource', app, 'Note', '--label-ko=노트', '--record-owner=tenant'],
        ['make:resource', app, 'Memo', '--label-ko=메모', '--record-owner=legal_entity'],
        ['make:page', app, 'WorkSummary', '--label-ko=업무 요약', '--with-record'],
    ]) execFileSync('php', [tool, ...args, '--no-interaction'], { stdio: 'pipe' });
    const entrySource = await readFile(path.join(app, 'resources/js/index.ts'), 'utf8');
    assert.match(entrySource, /appKey: 'frontend-proof'/);
    assert(entrySource.includes("resourceContracts: import.meta.glob('./resources/*/*-resource-contract.ts')"));
    // Execute the actual generated detail component with controlled query observations.
    // This checks branch/retry behavior; browser layout remains a separate acceptance check.
    const { transformSync } = createRequire(vite)('esbuild');
    const showSource = await readFile(path.join(app, 'resources/js/resources/notes/surface/NoteRecordSurface.tsx'), 'utf8');
    const compiledShow = transformSync(showSource, { loader: 'tsx', format: 'cjs', jsx: 'automatic' }).code;
    // Execute the generated list's state derivation with cached rows and failed reads.
    for (const resource of ['Note', 'Memo']) {
        const folder = resource === 'Note' ? 'notes' : 'memos';
        const listSource = await readFile(path.join(app, `resources/js/resources/${folder}/surface/${resource}ListSurface.tsx`), 'utf8');
        const stateSource = listSource.slice(listSource.indexOf('    const accessPending ='), listSource.indexOf('    const labels ='));
        const state = new Function('input', 'isHttpError', `with (input) { ${stateSource}; return { rows, meta, canCreate, listReadable, failureStatus }; }`);
        const base = {
            contextError: false, contextPending: false, contextFailure: null,
            permissionsError: false, permissionsPending: false, permissionsFailure: null,
            organizationScope: { isLoading: false, isError: false, targets: {} },
            tenantId: 'tenant', canRead: true, canCreateFallback: true, isError: false, listFailure: null,
            list: { data: [{ id: 'cached' }], meta: { actions: { create: true } } },
        };
        const httpError = error => Boolean(error?.response);
        assert.equal(state(base, httpError).rows.length, 1);
        for (const status of [401, 403, 404, 500]) {
            const failed = state({ ...base, isError: true, listFailure: { response: { status } } }, httpError);
            assert.equal(failed.failureStatus, status);
            assert.deepEqual(failed.rows, []);
            assert.equal(failed.meta, undefined);
            assert.equal(failed.canCreate, false);
            assert.equal(failed.listReadable, false);
        }
        assert.equal(state({ ...base, canRead: false }, httpError).rows.length, 0);
        assert.equal(state({ ...base, canRead: false }, httpError).canCreate, true);
        assert.equal(state({ ...base, canRead: false, canCreateFallback: false }, httpError).canCreate, false);
        assert.equal(state({ ...base, contextPending: true }, httpError).canCreate, false);
        assert.equal(state({ ...base, contextError: true, contextFailure: { response: { status: 401 } } }, httpError).failureStatus, 401);
        assert.equal(state(base, httpError).canCreate, true);
    }
    let observation;
    let mutationOptions;
    let retries = 0;
    const navigations = [];
    const host = {
        firstFieldError: () => undefined,
        api: {}, serializeResourceTableSort: () => '-name', NxButton: 'button', NxEmptyView: 'empty', NxLoadingBlock: 'loading',
        NxPageFrame: 'frame', NxActionButton: 'action',
        useContextQuery: () => ({ data: { tenant: { id: 'tenant' } } }),
        useWorkSurfaceLabels: () => ({ breadcrumbLabel: 'Note', tabLabel: 'Note' }),
        useWorkTabLabel: () => {},
        useClearTabDirty: () => () => {},
        useResourceCreateCompletion: () => ({}),
        useOrganizationTargetsQuery: () => ({ data: { data: [] } }),
    };
    const dependencies = {
        '@nexia/sdk': { isHttpError: error => error !== null && typeof error === 'object' && 'response' in error },
        '@nexia/sdk/host': host,
        'react-router-dom': { useLocation: () => ({ state: null }), useNavigate: () => (...args) => navigations.push(args), useParams: () => ({ id: 'record' }), useSearchParams: () => [new URLSearchParams(), () => {}] },
        'react-i18next': { useTranslation: () => ({ t: key => key }) },
        '@tanstack/react-query': { useMutation: options => { mutationOptions = options; return { isPending: false }; }, useQueryClient: () => ({}), queryOptions: options => options, useQuery: options => options.queryKey.includes('collection-actions') ? { data: { meta: { actions: { create: true } } } } : ({ ...observation, refetch: () => { retries++; } }) },
        'react': { useState: value => [value, () => {}], useEffect: () => {}, useMemo: fn => fn(), useCallback: fn => fn },
        'react/jsx-runtime': { jsx: (type, props) => ({ type, props }), jsxs: (type, props) => ({ type, props }) },
    };
    const queryModule = { exports: {} };
    const querySource = await readFile(path.join(app, 'resources/js/resources/notes/note-queries.ts'), 'utf8');
    new Function('require', 'module', 'exports', transformSync(querySource, { loader: 'ts', format: 'cjs' }).code)(name => name.endsWith('note-resource-contract.ts') ? { noteApiRoute: () => '/notes' } : dependencies[name], queryModule, queryModule.exports);
    const listParams = { search: 'find', sort: { key: 'name', direction: 'desc' }, filters: { status: ['active'], blank: '', omitted: undefined }, page: 2, perPage: 25 };
    const listOptions = queryModule.exports.noteListQueryOptions('tenant', [], listParams);
    assert.deepEqual(listOptions.queryKey.slice(0, 3), queryModule.exports.noteListQueryScope('tenant', 'tenant'));
    let request;
    host.api.get = async (url, options) => { request = options; return { data: { data: [], meta: { total: 0 } } }; };
    const signal = new AbortController().signal;
    await listOptions.queryFn({ signal });
    assert.deepEqual(request.params, { search: 'find', sort: '-name', page: 2, per_page: 25, 'filter[status]': ['active'] });
    assert.equal(request.signal, signal);
    const memoQueryModule = { exports: {} };
    const memoQuerySource = await readFile(path.join(app, 'resources/js/resources/memos/memo-queries.ts'), 'utf8');
    new Function('require', 'module', 'exports', transformSync(memoQuerySource, { loader: 'ts', format: 'cjs' }).code)(name => name.endsWith('memo-resource-contract.ts') ? { memoApiRoute: () => '/memos' } : dependencies[name], memoQueryModule, memoQueryModule.exports);
    const memoList = memoQueryModule.exports.memoListQueryOptions('tenant', ['org-a', 'org-b'], listParams);
    assert.deepEqual(memoList.queryKey.slice(0, 3), memoQueryModule.exports.memoListQueryScope('tenant', 'org-a,org-b'));
    await memoList.queryFn({ signal });
    assert.deepEqual(request.params['legal_entity_public_ids[]'], ['org-a', 'org-b']);
    const pageModule = { exports: {} };
    const pageQuerySource = await readFile(path.join(app, 'resources/js/pages/work-summary-queries.ts'), 'utf8');
    new Function('require', 'module', 'exports', transformSync(pageQuerySource, { loader: 'ts', format: 'cjs' }).code)(name => dependencies[name], pageModule, pageModule.exports);
    const pageAppKey = JSON.parse(await readFile(path.join(app, 'nexia.json'), 'utf8')).app.app_key;
    const pageRequests = [];
    host.api.get = async (url, options) => { pageRequests.push({ method: 'GET', url, options }); return { data: {} }; };
    host.api.put = async (url, data) => { pageRequests.push({ method: 'PUT', url, data }); return { data: {} }; };
    await pageModule.exports.workSummaryQueryOptions('tenant').queryFn({ signal });
    await pageModule.exports.workSummaryRecordQueryOptions('tenant', 'record').queryFn({ signal });
    await pageModule.exports.saveWorkSummary('record', { title: 'Requested' });
    assert.deepEqual(pageRequests.map(({ method, url }) => [method, url]), [
        ['GET', `/${pageAppKey}/pages/work-summary`],
        ['GET', `/${pageAppKey}/pages/work-summary/record`],
        ['PUT', `/${pageAppKey}/pages/work-summary/record`],
    ]); // The SDK Axios client already owns the /api base URL.
    assert.equal(pageRequests[0].options.signal, signal);
    assert.deepEqual(pageRequests[2].data, { title: 'Requested' });
    // Cancel asks the router to leave without clearing the draft before its guard runs.
    const pageRecordModule = { exports: {} };
    const pageRecordSource = await readFile(path.join(app, 'resources/js/pages/WorkSummaryRecordSurface.tsx'), 'utf8');
    new Function('require', 'module', 'exports', transformSync(pageRecordSource, { loader: 'tsx', format: 'cjs', jsx: 'automatic' }).code)(
        name => name === './work-summary-queries' ? pageModule.exports : dependencies[name], pageRecordModule, pageRecordModule.exports);
    let clearedBeforeNavigation = 0;
    const previousClearDirty = host.useClearTabDirty;
    host.useClearTabDirty = () => () => { clearedBeforeNavigation++; };
    host.useMarkTabDirty = () => {};
    observation = { data: { id: 'record', title: 'Draft baseline', actions: { update: true } } };
    const recordPage = pageRecordModule.exports.default({ mode: 'edit' });
    const editorElement = recordPage.props.children.props.children[1];
    const savedPageObservation = observation;
    observation = { ...savedPageObservation, isError: true, error: { response: { status: 500 } } };
    const failedRefreshPage = pageRecordModule.exports.default({ mode: 'edit' });
    const [refreshError, retainedEditor] = failedRefreshPage.props.children.props.children;
    assert.equal(refreshError.props.kind, 'error');
    assert.equal(retainedEditor.type, editorElement.type);
    assert.equal(retainedEditor.props.record, editorElement.props.record);
    observation = { ...savedPageObservation, isError: true, error: { response: { status: 401 } } };
    const expiredPage = pageRecordModule.exports.default({ mode: 'edit' });
    assert.equal(expiredPage.props.children.props.description, 'common.errors.unauthenticated');
    assert.notEqual(expiredPage.props.children.type, retainedEditor.type);
    observation = savedPageObservation;
    const editor = editorElement.type(editorElement.props);
    editor.props.actions.props.children[0].props.onClick();
    assert.equal(clearedBeforeNavigation, 0, 'Cancel must preserve dirty state for the shared navigation confirmation');
    assert.deepEqual(navigations.at(-1), [pageModule.exports.workSummaryRecordRoute('record')]);
    host.useClearTabDirty = previousClearDirty;

    const generatedFiles = await readdir(path.join(app, 'resources/js/resources'), { recursive: true });
    assert(!generatedFiles.some(file => file.endsWith('Section.tsx')));
    assert(!generatedFiles.some(file => /register-.*-inspector\.ts$/.test(file)));
    assert(!generatedFiles.some(file => file.endsWith('Inspector.tsx')));
    assert.equal(generatedFiles.filter(file => file.startsWith('notes/') && /\.tsx?$/.test(file)).length, 4);
    assert((await readFile(path.join(app, 'resources/js/resources/notes/note-resource-contract.ts'), 'utf8')).includes("import('./surface/NoteRecordSurface')"));
    const module = { exports: {} };
    new Function('require', 'module', 'exports', compiledShow)(name => {
        if (name.endsWith('note-queries.ts')) return queryModule.exports;
        if (name.endsWith('note-resource-contract.ts')) return { noteShowRoute: id => `/notes/${id}`, noteEditRoute: id => `/notes/${id}/edit`, noteResourceId: record => record.public_id ?? 'record', notePermissions: { create: 'trial.note.create' } };
        assert(name in dependencies, `Unexpected generated dependency: ${name}`);
        return dependencies[name];
    }, module, module.exports);
    const render = (value, mode = 'read') => { observation = value; const entry = module.exports.default({ mode }); return entry.type(entry.props); };
    for (const [status, kind] of [[403, 'permission'], [404, 'notFound'], [500, 'error'], [401, 'error']]) {
        const view = render({ isError: true, error: { response: { status } } });
        assert.equal(view.props.children.props.kind, kind);
        if (status === 401) assert.equal(view.props.children.props.description, 'common.errors.unauthenticated');
        if (kind === 'error') view.props.children.props.children.props.onClick();
    }
    assert.equal(retries, 2);
    assert.equal(render({ isLoading: true }).props.children.type, 'loading');
    assert.equal(render({}).props.children.props.kind, 'notFound');
    assert.equal(render({ data: { note: { actions: { view: false } } } }).props.children.props.kind, 'permission');
    const retained = { note: { name: 'Saved', actions: { view: true, update: true } } };
    const refreshFailure = render({ data: retained, isError: true, error: { response: { status: 500 } } });
    assert.equal(refreshFailure.props.children[0].props.kind, 'error');
    assert.equal(refreshFailure.props.children[1].type, module.exports.RecordDetails);
    assert.equal(render({ data: retained, isError: true, error: { response: { status: 403 } } }).props.children.props.kind, 'permission');
    render({ data: retained });
    await assert.rejects(mutationOptions.mutationFn({ name: 'Forbidden read write' }), /not editable/);
    assert.throws(() => module.exports.default({}), /explicit SDK route mode/);
    assert.equal(render({ data: { note: { name: 'Read only', actions: { view: true, update: false } } } }, 'edit').props.children.props.kind, 'permission');
    // Execute generated completion without making another API write.
    const savedQueries = [];
    dependencies['@tanstack/react-query'].useQueryClient = () => ({
        invalidateQueries: value => savedQueries.push(value), setQueryData: (...args) => savedQueries.push(args),
    });
    host.useDateTimeFormatter = () => ({ formatDateTime: value => value });
    host.useMarkTabDirty = () => {};
    const createView = render({}, 'create');
    const form = createView.props.children.find(child => child?.props?.onSaved);
    const response = { note: { public_id: 'created-id', name: 'Server normalized', actions: { view: false, update: false } } };
    const completion = { action: 'create', response, submittedValues: { name: 'Submitted' }, currentValues: { name: 'Newer edit' } };
    assert.equal(form.props.onSaved(completion), response.note);
    assert.deepEqual(navigations.at(-1), ['/notes/created-id/edit', { replace: true,
        state: { savedForm: { tenantId: 'tenant', resourceId: 'created-id', name: 'Newer edit' } } }]);
    assert.equal(savedQueries.length, 2);
    assert(savedQueries.every(entry => !Array.isArray(entry)), 'Delegated permissions must never seed the human query cache');
    assert.equal(savedQueries[1].exact, true);
    form.props.onSaved({ ...completion, currentValues: { name: 'Submitted' } });
    assert.equal(navigations.at(-1)[1].state.savedForm.name, 'Server normalized');
    assert.equal(form.props.onSaved({ ...completion, response: { note: { name: 'Invalid', actions: {} } } }), null);
    const updates = [];
    dependencies.react.useState = value => [value, update => updates.push(update)];
    const formBody = form.type(form.props);
    formBody.props.binding.onSaved(completion);
    const updateDraft = updates.at(-1);
    assert.deepEqual(updateDraft({ name: 'Typed during completion', baseline: '' }), { name: 'Typed during completion', baseline: 'Server normalized' });
    assert.deepEqual(updateDraft({ name: 'Submitted', baseline: '' }), { name: 'Server normalized', baseline: 'Server normalized' });
    dependencies.react.useState = value => [value, () => {}];

    // Manual creation must not navigate to a forbidden detail or leave a second
    // POST available after success. The picker continuation still takes priority.
    const originalState = dependencies.react.useState;
    let savedWithoutRead = false;
    dependencies.react.useState = value => value === false
        ? [savedWithoutRead, next => { savedWithoutRead = next; }]
        : originalState(value);
    let continued = false;
    host.useResourceCreateCompletion = () => ({ complete: async () => continued });
    render({}, 'create');
    const manualMutation = mutationOptions;
    const navigationCount = navigations.length;
    await manualMutation.onSuccess(response);
    assert.equal(savedWithoutRead, true);
    assert.equal(navigations.length, navigationCount);
    const savedView = render({}, 'create');
    assert.match(savedView.props.children.props.title, /saved_without_read$/);
    assert.equal(savedView.props.children.props.children.type, 'button');
    savedView.props.children.props.children.props.onClick();
    assert.equal(savedWithoutRead, false);
    continued = true;
    await manualMutation.onSuccess(response);
    assert.equal(savedWithoutRead, false);
    continued = false;
    await manualMutation.onSuccess({ note: { ...response.note, actions: { ...response.note.actions, view: true } } });
    assert.deepEqual(navigations.at(-1), ['/notes/created-id']);
    dependencies.react.useState = originalState;

    // Inspector must fetch detail even when a caller supplies embedded data.
    const inspectorModule = { exports: {} };
    const inspectorSource = showSource;
    let inspectorActions;
    host.useMessage = () => ({ confirm: async () => false });
    host.ResourceInspectorQuery = 'query-boundary';
    host.InspectorResourceActions = 'actions';
    host.ResourceInspectorState = 'state';
    let inspectedOptions;
    const originalQuery = dependencies['@tanstack/react-query'].useQuery;
    dependencies['@tanstack/react-query'].useQuery = options => { inspectedOptions = options; return { ...observation, data: observation.data ? options.select(observation.data) : undefined, refetch: () => {} }; };
    new Function('require', 'module', 'exports', transformSync(inspectorSource, { loader: 'tsx', format: 'cjs', jsx: 'automatic' }).code)(name => {
        if (name.endsWith('NoteRecordSurface.tsx')) return module.exports;
        if (name.endsWith('note-queries.ts')) return queryModule.exports;
        if (name.endsWith('note-resource-contract.ts')) return { noteResourceActions: (_item, _t, callbacks) => { inspectorActions = callbacks; return ['authorized-action']; } };
        assert(name in dependencies, `Unexpected Inspector dependency: ${name}`);
        return dependencies[name];
    }, inspectorModule, inspectorModule.exports);
    for (const status of [401, 403, 404]) {
        observation = { data: retained, isError: true, error: { response: { status } } };
        const panel = inspectorModule.exports.NoteInspector({ resource: { id: 'record', data: retained.note }, stack: {} });
        assert.equal(inspectedOptions.enabled, true);
        assert.deepEqual(inspectedOptions.queryKey, queryModule.exports.noteDetailQueryOptions('tenant', 'record').queryKey);
        const boundary = panel.props.children[1];
        assert.equal(boundary.type, 'query-boundary');
        assert.deepEqual(boundary.props.actions().props.actions, []);
        assert.equal(boundary.props.query.error.response.status, status);
    }
    observation = { data: retained };
    assert.equal(inspectorModule.exports.NoteInspector({ resource: { id: 'record' }, stack: {} }).props.children[1].props.children(retained.note).type, inspectorModule.exports.RecordDetails);
    const removed = [];
    const invalidated = [];
    dependencies['@tanstack/react-query'].useQueryClient = () => ({ removeQueries: value => removed.push(value), invalidateQueries: value => invalidated.push(value) });
    let reset = 0;
    retained.note.actions.delete = true;
    host.useMessage = () => ({ confirm: async () => true });
    dependencies['@tanstack/react-query'].useMutation = options => {
        mutationOptions = options;
        return { isPending: false, mutate: (id, callbacks) => { options.onSuccess(undefined, id); callbacks.onSuccess(); } };
    };
    inspectorModule.exports.NoteInspector({ resource: { id: 'record' }, stack: { reset: () => reset++ } });
    inspectorActions.onDelete();
    await Promise.resolve();
    assert.deepEqual(removed, [{ queryKey: queryModule.exports.noteDetailQueryOptions('tenant', 'record').queryKey, exact: true }]);
    assert.equal(invalidated.length, 1);
    assert.equal(reset, 1);
    dependencies['@tanstack/react-query'].useQuery = originalQuery;
    host.NxDetailRow = 'detail-row';
    host.ResourceStatusBadge = 'status-badge';
    host.useDateTimeFormatter = () => ({ formatDateTime: value => value });
    const readContent = module.exports.RecordDetails({ item: { ...retained.note, created_at: null, updated_at: null } });
    const readNodes = [];
    const visit = node => {
        if (!node || typeof node !== 'object') return;
        if (Array.isArray(node)) { node.forEach(visit); return; }
        readNodes.push(node.type);
        visit(node.props?.children);
    };
    visit(readContent);
    assert(readNodes.includes('detail-row'));
    assert(!readNodes.some(type => ['frame', 'form', 'input', 'button'].includes(type)));

    await mkdir(path.join(app, 'public'));
    await writeFile(path.join(app, 'public/private.txt'), 'NEXIA_PUBLIC_COPY_SENTINEL');
    await writeFile(path.join(app, '.env'), 'VITE_NEXIA_BUILD_PROBE=NEXIA_ENV_SENTINEL\n');
    await appendFile(path.join(app, 'resources/js/index.ts'), '\nconsole.log(import.meta.env.VITE_NEXIA_BUILD_PROBE);\n');
    execFileSync(process.execPath, [vite, 'build'], { cwd: app, stdio: 'pipe' });
    const output = path.join(app, 'dist/frontend');
    const manifest = JSON.parse(await readFile(path.join(output, '.vite/manifest.json'), 'utf8'));
    assert.equal(manifest['resources/js/index.ts'].isEntry, true);
    for (const name of ['NoteListSurface', 'NoteRecordSurface', 'MemoRecordSurface', 'FrontendProofOverviewSurface']) {
        const chunk = Object.entries(manifest).find(([source]) => source.endsWith(`/${name}.tsx`))?.[1];
        assert(chunk?.isDynamicEntry, `${name} must remain a lazy surface`);
        await readFile(path.join(output, chunk.file));
    }
    const files = await readdir(output, { recursive: true });
    assert(!files.some(file => /\.(?:map|php)$/.test(file) || file === 'private.txt'));
    const javascript = (await Promise.all(files.filter(file => file.endsWith('.js')).map(file => readFile(path.join(output, file), 'utf8')))).join('\n');
    assert.match(javascript, /@nexia\/sdk\/host/);
    assert.match(javascript, /react\/jsx-runtime/);
    assert.doesNotMatch(javascript, /NEXIA_ENV_SENTINEL|NEXIA_PUBLIC_COPY_SENTINEL|import\.meta\.glob|#app\//);
    console.log('Generated App/Resource build, lazy surfaces, external SDK peers and private-file exclusion passed.');
} finally {
    await rm(root, { recursive: true, force: true });
}
