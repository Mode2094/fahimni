import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Regression guard: the app must never fall back to the blocking native
 * `window.confirm` / `window.prompt`. All confirmations go through the
 * accessible `confirm-dialog` component so RTL, focus and styling stay intact.
 *
 * The confirm-dialog module itself is allowed to reference the native API as
 * its last-resort fallback (e.g. a unit test that renders a single page).
 */

// `process.cwd()` is the frontend package root when Vitest runs `npm run test:ui`.
const SRC = join(process.cwd(), 'src');

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      if (entry === 'node_modules' || entry === '.next') continue;
      walk(full, out);
    } else if ((entry.endsWith('.ts') || entry.endsWith('.tsx')) && !entry.includes('.test.')) {
      out.push(full);
    }
  }
  return out;
}

// `window.confirm(...)` / `window.prompt(...)`, or a bare `confirm(...)` /
// `prompt(...)`. A method call on another object — `deferred.current.prompt()`
// (the PWA install prompt) — is fine and must not be flagged.
const NATIVE_DIALOG = /(?:window\.(?:confirm|prompt)|(?<![.\w])(?:confirm|prompt))\s*\(/;

describe('no native blocking dialogs', () => {
  it('uses the accessible confirm-dialog everywhere instead of confirm()/prompt()', () => {
    const offenders: string[] = [];

    for (const file of walk(SRC)) {
      if (file.endsWith('confirm-dialog.tsx')) continue;
      const source = readFileSync(file, 'utf8');
      source.split('\n').forEach((line, i) => {
        if (NATIVE_DIALOG.test(line)) {
          offenders.push(`${file.replace(SRC, '')}:${i + 1}: ${line.trim()}`);
        }
      });
    }

    expect(offenders).toEqual([]);
  });
});
