/**
 * `npm run check` con el cerrojo puesto (`T-003b`).
 *
 * Gemelo de `scripts/check.php`: coge el cerrojo, corre `npm run check:steps`
 * —donde vive el orden de los gates, a la vista en `package.json`— y lo suelta
 * pase lo que pase. El orden **no** se mueve aquí.
 *
 * Los dos cerrojos son el mismo archivo, así que lanzar esto con
 * `composer check` corriendo se para en seco en vez de producir los ~25 fallos
 * que no son del código (medido en `T-032`).
 */

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
