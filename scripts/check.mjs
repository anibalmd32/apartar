
import { spawnSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const scripts = dirname(fileURLToPath(import.meta.url));
const lock = join(scripts, 'gate-lock.mjs');

const run = (command, args) => spawnSync(command, args, { stdio: 'inherit', shell: process.platform === 'win32' });

const acquired = run(process.execPath, [lock, 'acquire', 'npm run check']);

if (acquired.status !== 0) {
    process.exit(acquired.status ?? 1);
}

const steps = run('npm', ['run', 'check:steps']);

run(process.execPath, [lock, 'release']);

process.exit(steps.status ?? 1);
