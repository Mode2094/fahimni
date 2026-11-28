import { create } from 'zustand';

/**
 * Whether the current tenant's student leaderboard is enabled. The teacher owns
 * this setting (`disabled` / `anonymous` / `named`); students only need the
 * boolean so every student-facing surface can hide ranking affordances when it
 * is turned off. `null` means "not resolved yet" — optimistic UIs keep the item
 * visible until the server says otherwise, then it disappears on the next tick.
 */
const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

type LeaderboardState = {
  /** false only when the teacher explicitly disabled it. */
  enabled: boolean | null;
  mode: string | null;
  loaded: boolean;
  load: () => Promise<void>;
};

export const useLeaderboard = create<LeaderboardState>((set) => ({
  enabled: null,
  mode: null,
  loaded: false,
  load: async () => {
    try {
      const tid = typeof window !== 'undefined' ? localStorage.getItem('tenant_id') : null;
      const res = await fetch(`${API}/student/leaderboard`, {
        headers: { Accept: 'application/json', ...(tid ? { 'X-Tenant-Id': tid } : {}) },
        credentials: 'same-origin',
        cache: 'no-store',
      });
      if (!res.ok) {
        set({ loaded: true });
        return;
      }
      const d = await res.json();
      set({ enabled: d.enabled !== false, mode: d.mode ?? null, loaded: true });
    } catch {
      set({ loaded: true });
    }
  },
}));

/** Navigation/routes stay usable unless the teacher explicitly disabled it. */
export function leaderboardVisible(enabled: boolean | null | undefined): boolean {
  return enabled !== false;
}
