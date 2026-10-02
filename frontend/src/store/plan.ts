import { create } from 'zustand';

type PlanState = {
  plan: any;
  features: Record<string, string>;
  usage: any;
  gates: Record<string, boolean>;
  loaded: boolean;
  load: () => Promise<void>;
  can: (key: string) => boolean;
  usageLabel: (key: string) => string;
  storageLabel: () => string;
};

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export const usePlan = create<PlanState>((set, get) => ({
  plan: null,
  features: {},
  usage: null,
  gates: {},
  loaded: false,

  load: async () => {
    if (typeof window === 'undefined' || !localStorage.getItem('lms_authed')) return;
    try {
      const res = await fetch(`${API}/teacher/plan`, {
        headers: { 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' },
      });
      if (!res.ok) return;
      const d = await res.json();
      set({ plan: d.plan, features: d.features || {}, usage: d.usage, gates: d.gates || {}, loaded: true });
    } catch {
      set({ loaded: true });
    }
  },

  can: (key) => get().gates[key] === true,

  usageLabel: (key) => {
    const u = get().usage?.[key];
    if (!u) return '';
    if (u.unlimited) return '∞';
    return `${u.used} / ${u.limit}`;
  },

  storageLabel: () => {
    const u = get().usage?.storage;
    if (!u) return '';
    if (u.unlimited) return `${u.used_mb} MB ∞`;
    return `${u.used_mb} / ${u.limit_mb} MB`;
  },
}));
