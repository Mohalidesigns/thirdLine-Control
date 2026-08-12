/**
 * Bundle budget check (Phase 15.6, R6): every route chunk must gzip to
 * 250KB or less. Runs after `vite build` (wired into `npm run build`), so
 * CI — and any local build — fails the moment a chunk crosses the line
 * instead of after a user in a branch office notices.
 */
import { gzipSync } from 'node:zlib';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const BUDGET_BYTES = 250 * 1024;
const buildDir = new URL('../public/build/assets', import.meta.url).pathname;

let failures = 0;
let checked = 0;

for (const file of readdirSync(buildDir)) {
    if (!file.endsWith('.js') && !file.endsWith('.css')) continue;

    const path = join(buildDir, file);
    if (!statSync(path).isFile()) continue;

    const gzipped = gzipSync(readFileSync(path)).length;
    checked++;

    if (gzipped > BUDGET_BYTES) {
        failures++;
        console.error(
            `✗ ${file}: ${(gzipped / 1024).toFixed(1)}KB gzipped exceeds the ${BUDGET_BYTES / 1024}KB budget`,
        );
    }
}

if (failures > 0) {
    console.error(`\nBundle budget FAILED: ${failures} chunk(s) over ${BUDGET_BYTES / 1024}KB gzipped (R6).`);
    process.exit(1);
}

console.log(`Bundle budget OK: ${checked} chunk(s) all ≤ ${BUDGET_BYTES / 1024}KB gzipped.`);
