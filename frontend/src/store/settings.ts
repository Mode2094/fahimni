import { create } from 'zustand';

export type PlatformSettings = {
  platform_name: string;
  currency: string;
  currency_rate: number;
  default_locale: 'ar' | 'en';
  logo_url: string | null;
  primary_color: string;
  notify_new_teacher: boolean;
  notify_payment: boolean;
  notify_exam_submitted: boolean;
  notify_low_storage: boolean;
  registration_enabled: boolean;
  teacher_registration_enabled: boolean;
  maintenance_mode: boolean;
  two_factor_enabled: boolean;
  captcha_enabled: boolean;
  platform_domain: string;
  // Booking gateways (student ↔ teacher one-off payments).
  paytabs_profile_id: string;
  paytabs_server_key: string;
  paytabs_enabled: boolean;
  paytabs_test_mode: boolean;
  paypal_client_id: string;
  paypal_secret: string;
  paypal_enabled: boolean;
  paypal_test_mode: boolean;
  // Freemius — the only platform-subscription gateway (secrets from env).
  freemius_enabled: boolean;
  freemius_product_id: string;
  freemius_api_url: string;
  freemius_checkout_url: string;
  freemius_sandbox: boolean;
  freemius_api_token_set: boolean;
  freemius_secret_key_set: boolean;
  freemius_public_key_set: boolean;
  student_mobile_app: boolean;
  student_app_hidden: boolean;
  student_app_android_url: string;
  student_app_ios_url: string;
  contact_email: string;
  contact_whatsapp: string;
  live_google_meet_enabled: boolean;
  live_default_provider: string;
};

type SettingsState = {
  settings: PlatformSettings;
  loaded: boolean;
  load: () => Promise<void>;
  save: (patch: Partial<PlatformSettings>) => Promise<void>;
  formatPrice: (amount: number | string | null | undefined) => string;
};

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export const useSettings = create<SettingsState>((set, get) => ({
  settings: {
    platform_name: 'Tadreesly',
    currency: 'JOD',
    currency_rate: 1,
    default_locale: 'ar',
    logo_url: null,
    primary_color: '#0d9488',
    notify_new_teacher: true,
    notify_payment: true,
    notify_exam_submitted: true,
    notify_low_storage: false,
    registration_enabled: true,
    teacher_registration_enabled: false,
    maintenance_mode: false,
    two_factor_enabled: false,
    captcha_enabled: false,
    platform_domain: 'lms.example.com',
    paytabs_profile_id: '',
    paytabs_server_key: '',
    paytabs_enabled: false,
    paytabs_test_mode: true,
    paypal_client_id: '',
    paypal_secret: '',
    paypal_enabled: false,
    paypal_test_mode: true,
    freemius_enabled: false,
    freemius_product_id: '',
    freemius_api_url: 'https://api.freemius.com',
    freemius_checkout_url: 'https://checkout.freemius.com',
    freemius_sandbox: false,
    freemius_api_token_set: false,
    freemius_secret_key_set: false,
    freemius_public_key_set: false,
    student_mobile_app: false,
    student_app_hidden: false,
    student_app_android_url: '',
    student_app_ios_url: '',
    contact_email: 'support@tadreesly.com',
    contact_whatsapp: '',
    live_google_meet_enabled: true,
    live_default_provider: 'google_meet',
  },
  loaded: false,

  load: async () => {
    try {
      // X-Tenant-Id يجعل الخادم يعيد لوجو/اسم المعلم (منصته) بدل لوجو المنصة العام.
      const rawTid = typeof window !== 'undefined' ? localStorage.getItem('tenant_id') : null;
      // tenant_id values are UUIDs server-side; a stale/garbled value makes the
      // API 500, so only send well-formed UUIDs.
      const tid = rawTid && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(rawTid) ? rawTid : null;
      const res = await fetch(`${API}/platform-settings`, {
        headers: {
          Accept: 'application/json',
          ...(tid ? { 'X-Tenant-Id': tid } : {}),
        },
      });
      if (!res.ok) return;
      const d = await res.json();
      set({ settings: { ...get().settings, ...d.settings }, loaded: true });
    } catch {
      set({ loaded: false });
    }
  },

  save: async (patch) => {
    const res = await fetch(`${API}/platform-settings`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(patch),
    });
    if (!res.ok) throw new Error();
    const d = await res.json();
    set({ settings: { ...get().settings, ...d.settings } });
  },

  formatPrice: (amount) => {
    const { currency } = get().settings;
    // الأسعار مخزنة بعملة المنصة (JOD) وتُعرض كما هي — currency_rate
    // يُستخدم للتحويل الداخلي (التكلفة بالدولار) فقط ولا يضرب العرض.
    const num = Number(amount) || 0;
    const value = Math.round(num * 100) / 100;
    return `${value.toLocaleString('en-US', { maximumFractionDigits: 2 })} ${currency}`;
  },
}));
