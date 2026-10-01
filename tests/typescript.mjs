import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtemp, symlink, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Use an installed public SDK and its React peer toolchain. Never copy Core source.
const modules = process.argv[2];
assert(modules && path.isAbsolute(modules), 'Pass an absolute node_modules path with TypeScript, public SDK and React peers.');
const root = await mkdtemp(path.join(tmpdir(), 'nexia-typescript-check-'));
const app = path.join(root, 'app');
const tool = fileURLToPath(new URL('../bin/nexia-app', import.meta.url));
try {
    for (const args of [
        ['make:app', 'TypeProof', '--vendor=example', '--family=other', `--directory=${app}`],
        ['make:resource', app, 'Note', '--label-ko=노트', '--record-owner=tenant'],
        ['make:resource', app, 'Memo', '--label-ko=메모', '--record-owner=legal_entity'],
        ['make:page', app, 'WorkSummary', '--label-ko=업무 요약', '--with-record'],
    ]) execFileSync('php', [tool, ...args, '--no-interaction'], { stdio: 'pipe' });
    await symlink(modules, path.join(app, 'node_modules'), 'dir');
    await writeFile(path.join(app, 'tsconfig.json'), JSON.stringify({
        compilerOptions: {
            target: 'ES2022', module: 'ESNext', moduleResolution: 'Bundler',
            jsx: 'react-jsx', strict: true, noEmit: true, skipLibCheck: true,
            allowImportingTsExtensions: true, resolveJsonModule: true,
            esModuleInterop: true, types: ['vite/client'],
        },
        include: ['resources/js/**/*.ts', 'resources/js/**/*.tsx'],
    }));
    execFileSync(process.execPath, [path.join(modules, 'typescript/bin/tsc'), '-p', path.join(app, 'tsconfig.json')], { stdio: 'inherit' });
    console.log('Generated tenant/organization App TypeScript check against the public SDK passed.');
} finally {
    await rm(root, { recursive: true, force: true });
}
