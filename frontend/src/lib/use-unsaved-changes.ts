'use client';

import { useEffect } from 'react';

/**
 * Warns before the tab is closed or reloaded while there are unsaved changes.
 *
 * SPA tab switches keep component state alive, so only a real page unload can
 * drop a teacher's half-finished settings edits. The browser shows its own
 * localized confirmation once `preventDefault()` is called — custom text is
 * ignored by modern browsers, so none is passed by default.
 */
export function useUnsavedChangesWarning(dirty: boolean, message?: string) {
  useEffect(() => {
    if (!dirty) return;
    const handler = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      // Legacy browsers require a non-empty returnValue to prompt.
      event.returnValue = message ?? '';
      return message ?? '';
    };
    window.addEventListener('beforeunload', handler);
    return () => window.removeEventListener('beforeunload', handler);
  }, [dirty, message]);
}
