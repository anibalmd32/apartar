/**
 * El cerrojo de las dos recetas, lado Node (`T-003b`, `REQ-QAX-001`).
 *
 * Gemelo de `scripts/gate-lock.php` y sobre **el mismo archivo**: son dos
 * implementaciones porque son dos runtimes —PHP dentro del contenedor, Node en
 * el host—, no porque sean dos cerrojos. El porqué entero está escrito en el
 * de PHP; lo corto es que `composer check` y `npm run check` a la vez producen
 * ~25 fallos que no son del código, porque `vite build` escribe en `public/`
 * mientras `Storage::fake('public')` borra directorios.
 *
 * Uso: `node scripts/gate-lock.mjs acquire "npm run check"` y `… release`.
 */

import { existsSync, mkdirSync, readFileSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const lock = join(root, 'storage', 'framework', 'gate.lock');

/** Segundos tras los cuales un cerrojo se da por abandonado. */
const STALE_AFTER_SECONDS = 3600;

const [action, owner = 'npm run check'] = process.argv.slice(2);

if (action === 'release') {
    try {
        unlinkSync(lock);
    } catch {
        // No estaba: soltar algo que nadie tiene no es un error, y es el caso
        // normal cuando la receta falló antes de cogerlo.
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
                '  (medido en T-032). Espera a que termine y lánzala después.\n\n' +
                '  Si aquello ya murió: node scripts/gate-lock.mjs release\n\n',
        );

        process.exit(1);
    }

    process.stderr.write(`  Aviso: el cerrojo de «${held}» llevaba ${age} s sin soltarse y se da por abandonado.\n`);
}

mkdirSync(dirname(lock), { recursive: true });
writeFileSync(lock, owner);
