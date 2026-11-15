import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

/**
 * Vitest config for component/unit tests.
 *
 * The frontend had NO component tests: only `node --test` over plain `.ts`
 * scripts (`scripts/*.test.ts`), which cannot render JSX. This adds a jsdom +
 * Testing Library runner so the Teacher Dashboard, the student area and the
 * landing page are actually exercised (loading / empty / error / RTL / real
 * data), alongside the existing script tests which keep running via
 * `npm run test:unit`.
 */
export default defineConfig({
  // Mirror the `@/*` → `./src/*` path alias from tsconfig.json (Vitest does not
  // read tsconfig `paths` on its own).
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./vitest.setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    css: false,
    restoreMocks: true,
    clearMocks: true,
  },
});
