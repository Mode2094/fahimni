import { describe, it, expect, vi, afterEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useUnsavedChangesWarning } from './use-unsaved-changes';

describe('useUnsavedChangesWarning', () => {
  afterEach(() => vi.restoreAllMocks());

  it('does not intercept unload when there is nothing unsaved', () => {
    const add = vi.spyOn(window, 'addEventListener');
    renderHook(() => useUnsavedChangesWarning(false));
    expect(add).not.toHaveBeenCalledWith('beforeunload', expect.anything());
  });

  it('prompts on unload while dirty and clears the guard once saved', () => {
    const add = vi.spyOn(window, 'addEventListener');
    const remove = vi.spyOn(window, 'removeEventListener');
    const { rerender } = renderHook(({ dirty }) => useUnsavedChangesWarning(dirty), {
      initialProps: { dirty: true },
    });

    expect(add).toHaveBeenCalledWith('beforeunload', expect.any(Function));

    // The handler cancels the default unload when invoked.
    const handler = add.mock.calls.find((c) => c[0] === 'beforeunload')?.[1] as (e: BeforeUnloadEvent) => void;
    const event = { preventDefault: vi.fn(), returnValue: undefined } as unknown as BeforeUnloadEvent;
    handler(event);
    expect(event.preventDefault).toHaveBeenCalled();

    rerender({ dirty: false });
    expect(remove).toHaveBeenCalledWith('beforeunload', expect.any(Function));
  });
});
