import { create } from 'zustand';

/**
 * Focus mode: hides the panel chrome (sidebar, header, bottom nav) so the
 * current page — e.g. a course lesson or exam — gets the entire viewport.
 */
export const useFocus = create<{ focused: boolean; setFocus: (f: boolean) => void }>((set) => ({
  focused: false,
  setFocus: (f) => set({ focused: f }),
}));
