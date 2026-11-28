import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useLocale } from '@/store/locale';
import TeacherDomainModal from './teacher-domain-modal';

vi.mock('@/components/ui/confirm-dialog', () => ({
  confirmDialog: vi.fn().mockResolvedValue(true),
}));

const dns = {
  cname_host: 'www',
  cname_value: 'lms.example.com',
  a_host: '@',
  a_value: '203.0.113.9',
  txt_host: '@',
  txt_value: 'fahimni-verify=lms.example.com',
  steps: ['أضف سجل CNAME'],
  hostinger_steps: ['hPanel ← DNS'],
  provider_note: 'لا نحتاج كلمة مرور Hostinger',
};

const status = (o: Record<string, any> = {}) => ({
  domain: 'academy-jo.com',
  verified: false,
  verified_at: null,
  status: 'pending_dns',
  status_reason: null,
  https_enabled: false,
  site_url: 'https://academy-jo.com',
  www_url: 'https://www.academy-jo.com',
  platform_domain: 'lms.example.com',
  subdomain: 'teacher.lms.example.com',
  subdomain_url: 'https://teacher.lms.example.com',
  dns_instructions: dns,
  ...o,
});

/** Route the modal's requests to a small, stateful stub. */
function stubFetch(payload: Record<string, any>) {
  return vi.fn(async (input: unknown, init?: RequestInit) => {
    const url = String(input);
    const method = (init?.method || 'GET').toUpperCase();
    if (url.includes('/domain/verify')) return { ok: true, json: async () => status({ status: 'active', verified: true, https_enabled: true }) };
    if (method === 'PUT') return { ok: true, json: async () => ({ ...status(), ...payload.connect }) };
    if (method === 'DELETE') return { ok: true, json: async () => ({ ...status({ domain: null, status: 'none' }) }) };
    return { ok: true, json: async () => payload.status ?? status() };
  });
}

beforeEach(() => {
  useLocale.setState({ locale: 'ar' });
});

const teacher = { id: 't1', name: 'أستاذ أحمد' };

describe('TeacherDomainModal', () => {
  it('shows the pending status and the exact DNS records', async () => {
    vi.stubGlobal('fetch', stubFetch({ status: status() }));

    render(<TeacherDomainModal open onOpenChange={() => {}} teacher={teacher} />);

    await screen.findByTestId('domain-status');
    expect(screen.getByTestId('domain-status')).toHaveTextContent('بانتظار إعداد DNS');
    expect(screen.getByText('أستاذ أحمد')).toBeInTheDocument();
    // The exact CNAME/A values from the server are shown, never invented.
    const text = screen.getByTestId('teacher-domain-modal').textContent || '';
    expect(text).toContain('lms.example.com');
    expect(text).toContain('203.0.113.9');
  });

  it('connects a domain via PUT and strips a scheme', async () => {
    const fetchMock = stubFetch({});
    vi.stubGlobal('fetch', fetchMock);

    render(<TeacherDomainModal open onOpenChange={() => {}} teacher={teacher} />);
    await screen.findByTestId('domain-status');

    fireEvent.change(screen.getByTestId('domain-input'), { target: { value: 'https://academy-jo.com/' } });
    await userEvent.click(screen.getByTestId('domain-connect'));

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'PUT');
      expect(call).toBeTruthy();
      const body = JSON.parse((call![1] as RequestInit).body as string);
      expect(body.domain).toBe('academy-jo.com');
    });
  });

  it('runs the DNS check via POST /domain/verify', async () => {
    const fetchMock = stubFetch({});
    vi.stubGlobal('fetch', fetchMock);

    render(<TeacherDomainModal open onOpenChange={() => {}} teacher={teacher} />);
    await screen.findByTestId('domain-status');

    await userEvent.click(screen.getByTestId('domain-verify'));

    await waitFor(() => {
      expect(fetchMock.mock.calls.some(([input]) => String(input).includes('/domain/verify'))).toBe(true);
    });
  });

  it('disconnects an active domain via DELETE', async () => {
    const fetchMock = stubFetch({ status: status({ status: 'active', verified: true, https_enabled: true }) });
    vi.stubGlobal('fetch', fetchMock);

    render(<TeacherDomainModal open onOpenChange={() => {}} teacher={teacher} />);
    await screen.findByTestId('domain-status');

    await userEvent.click(screen.getByTestId('domain-disconnect'));

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'DELETE');
      expect(call).toBeTruthy();
    });
  });

  it('renders Cloudflare-mode records and the apex note when provided', async () => {
    const cfDns = {
      mode: 'cloudflare_saas',
      cname_host: '@',
      cname_value: 'customers.fahimni.me',
      apex_note: 'استخدم سجل ALIAS للجذر (@).',
      records: [
        { type: 'CNAME', host: '@', value: 'customers.fahimni.me', purpose: 'الجذر / apex' },
        { type: 'TXT', host: '_cf.brand.com', value: 'own-token-123', purpose: 'تحقق الملكية' },
      ],
      steps: ['أضف سجل CNAME'],
    };
    vi.stubGlobal('fetch', stubFetch({ status: status({ dns_instructions: cfDns }) }));

    render(<TeacherDomainModal open onOpenChange={() => {}} teacher={teacher} />);
    await screen.findByTestId('domain-status');

    const text = screen.getByTestId('teacher-domain-modal').textContent || '';
    expect(text).toContain('customers.fahimni.me');
    expect(text).toContain('own-token-123');
    expect(screen.getByTestId('apex-note')).toBeInTheDocument();
  });
});
