
import { existsSync, mkdirSync, readFileSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const lock = join(root, 'storage', 'framework', 'gate.lock');

const STALE_AFTER_SECONDS = 3600;

const [action, owner = 'npm run check'] = process.argv.slice(2);

if (action === 'release') {
    try {
        unlinkSync(lock);
    } catch {
    }

    process.exit(0);
}

if (action !== 'acquire') {
    process.stderr.write('Uso: node scripts/gate-lock.mjs acquire|release <nombre>\n');
    process.exit(2);
}

if (existsSync(lock)) {
    const held = readFileSync(lock, 'utf8').trim() || 'otra receta';
    const age = Math.round((Date.now() - statSync(lock).mtimeMs) / 1000);

    if (age < STALE_AFTER_SECONDS) {
        process.stderr.write(
            `\n  No se puede arrancar «${owner}»: «${held}» lleva ${age} s corriendo.\n\n` +
                '  Las dos recetas no pueden correr a la vez. `vite build` escribe en public/ mientras\n' +
                "  Storage::fake('public') borra directorios, y salen ~25 fallos que no son del código\n" +
                '. Espera a que termine y lánzala después.\n\n' +
                '  Si aquello ya murió: node scripts/gate-lock.mjs release\n\n',
        );

        process.exit(1);
    }

    process.stderr.write(`  Aviso: el cerrojo de «${held}» llevaba ${age} s sin soltarse y se da por abandonado.\n`);
}

mkdirSync(dirname(lock), { recursive: true });
writeFileSync(lock, owner);
