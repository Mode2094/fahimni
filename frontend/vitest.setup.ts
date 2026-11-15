import '@testing-library/jest-dom/vitest';
import { afterEach, vi } from 'vitest';
import { cleanup } from '@testing-library/react';

// Unmount rendered trees between tests so DOM queries never see leftovers.
afterEach(() => cleanup());

// ── Deterministic module mocks ────────────────────────────────────────────
// next/link needs an app-router context that does not exist in jsdom — render a
// plain anchor so navigation targets stay assertable.
vi.mock('next/link', async () => {
  const { createElement } = await import('react');

  return {
    __esModule: true,
    default: ({ href, children, ...rest }: any) =>
      createElement('a', { href: typeof href === 'string' ? href : '#', ...rest }, children),
  };
});

// sonner draws toasts into a portal that jsdom does not exercise; stub the API
// so tests can assert on success/error notifications.
vi.mock('sonner', () => ({
  __esModule: true,
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn(), message: vi.fn() },
  Toaster: () => null,
}));

// ── Browser APIs jsdom does not implement ─────────────────────────────────
class ObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
  takeRecords() {
    return [];
  }
}

vi.stubGlobal('IntersectionObserver', ObserverStub);
vi.stubGlobal('ResizeObserver', ObserverStub);

if (!window.matchMedia) {
  vi.stubGlobal(
    'matchMedia',
    vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener() {},
      removeListener() {},
      addEventListener() {},
      removeEventListener() {},
      dispatchEvent() {
        return false;
      },
    })),
  );
}

window.scrollTo = vi.fn() as unknown as typeof window.scrollTo;
Element.prototype.scrollIntoView = vi.fn();

if (!navigator.clipboard) {
  Object.defineProperty(navigator, 'clipboard', {
    configurable: true,
    value: { writeText: vi.fn().mockResolvedValue(undefined) },
  });
}
