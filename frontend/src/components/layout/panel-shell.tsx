'use client';

import { useEffect, useState } from 'react';
import { usePathname } from 'next/navigation';
import { GraduationCap, LogOut, Globe, ChevronRight, Menu, X, PanelLeftClose, PanelLeftOpen, Crown } from 'lucide-react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { resolveLogoUrl } from '@/lib/logo';
import { usePlan } from '@/store/plan';
import { useFocus } from '@/store/focus';
import { cn, init } from '@/lib/utils';
import {
  ADMIN_NAV, TEACHER_NAV, STUDENT_NAV, STUDENT_BOTTOM_NAV, navLabelFor,
  type NavGroup, type NavItem,
} from './nav-config';

/* ── Unread badges for notifications / messages ── */
function useUnread() {
  const [notif, setNotif] = useState(0);
  const [chat, setChat] = useState(0);
  useEffect(() => {
    const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';
    const headers = { 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' };
    const load = () => {
      fetch(`${API}/notifications/unread-count`, { headers }).then(r => r.json()).then(d => setNotif(d.unread_count || 0)).catch(() => {});
      fetch(`${API}/conversations/unread-total`, { headers }).then(r => r.json()).then(d => setChat(d.total || 0)).catch(() => {});
    };
    load();
    const i = setInterval(load, 30000);
    return () => clearInterval(i);
  }, []);
  return { notif, chat };
}

type SidebarProps = {
  groups: NavGroup[];
  collapsed?: boolean;
  variant: 'admin' | 'teacher' | 'student';
  showAccount?: boolean;
};

function SidebarNav({ groups, collapsed, variant, showAccount = true }: SidebarProps) {
  const { settings } = useSettings();
  const { locale } = useLocale();
  const { user, logout } = useAuth();
  const { gates } = usePlan();
  const { notif, chat } = useUnread();
  const path = usePathname();
  const isAdmin = variant === 'admin';

  const badgeFor = (item: NavItem): number => {
    if (item.badge === 'notifications') return notif;
    if (item.badge === 'messages') return chat;
    return 0;
  };

  const isActive = (item: NavItem) => {
    if (path === item.href) return true;
    if (item.href !== '/' && path.startsWith(item.href + '/')) return true;
    return (item.match ?? []).some((m) => m !== '/' && path.startsWith(m + '/'));
  };

  const visibleGroups = groups
    .map((g) => ({ ...g, items: g.items.filter((i) => (!i.gate || gates[i.gate]) && !(i.hideWithApp && settings.student_app_hidden)) }))
    .filter((g) => g.items.length > 0);

  return (
    <div className="flex h-full flex-col">
      <div className="flex h-16 shrink-0 items-center justify-between border-b border-sidebar-border px-4">
        <div className="flex items-center gap-2.5 min-w-0">
          <img src={resolveLogoUrl(settings.logo_url)} alt="logo" className="h-9 w-9 shrink-0 rounded-xl object-cover ring-1 ring-white/10" onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }} />
          {!collapsed && <LogoName />}
        </div>
      </div>

      <nav className="flex-1 space-y-5 overflow-y-auto p-3" aria-label="Main navigation">
        {visibleGroups.map((g, gi) => (
          <div key={gi}>
            {g.title && !collapsed && (
              <p className="mb-1.5 px-3 text-[11px] font-bold uppercase tracking-wider text-sidebar-foreground/40">
                {locale === 'ar' ? g.title.ar : g.title.en}
              </p>
            )}
            {g.title && collapsed && <div className="mx-2 mb-1.5 border-t border-sidebar-border" />}
            <div className="space-y-0.5">
              {g.items.map((item) => {
                const active = isActive(item);
                const badge = badgeFor(item);
                const label = locale === 'ar' ? item.ar : item.en;
                return (
                  <a
                    key={item.href}
                    href={item.href}
                    title={collapsed ? label : undefined}
                    className={cn(
                      'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition-all',
                      collapsed && 'justify-center px-0',
                      active
                        ? 'bg-gradient-to-r from-teal-600 to-teal-500 font-semibold text-white shadow-lg shadow-teal-950/40'
                        : 'text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground',
                    )}
                    aria-current={active ? 'page' : undefined}
                  >
                    {active && (
                      <span className={cn('absolute start-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-full bg-teal-300', collapsed && 'hidden')} />
                    )}
                    <item.icon className={cn('h-[18px] w-[18px] shrink-0', active && 'text-teal-100')} />
                    {!collapsed && <span className="truncate">{label}</span>}
                    {!collapsed && badge > 0 && (
                      <span className="ms-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1.5 text-[10px] font-bold text-white">
                        {badge > 99 ? '99+' : badge}
                      </span>
                    )}
                    {collapsed && badge > 0 && (
                      <span className="absolute -top-0.5 -end-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-bold text-white">
                        {badge > 99 ? '99+' : badge}
                      </span>
                    )}
                  </a>
                );
              })}
            </div>
          </div>
        ))}
      </nav>

      <div className="shrink-0 border-t border-sidebar-border p-3">
        <button
          onClick={logout}
          className={cn(
            'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-red-400 transition-colors hover:bg-sidebar-accent',
            collapsed && 'justify-center px-0',
          )}
        >
          <LogOut className="h-[18px] w-[18px] shrink-0" />
          {!collapsed && <span>{locale === 'ar' ? 'تسجيل خروج' : 'Logout'}</span>}
        </button>
      </div>
    </div>
  );
}

function LogoName() {
  const { settings } = useSettings();
  const { locale } = useLocale();
  return (
    <span className="truncate text-base font-bold text-white">{settings.platform_name || (locale === 'ar' ? 'منصتي' : 'Tadreesly')}</span>
  );
}

function PlanChip() {
  const { plan } = usePlan();
  const { locale } = useLocale();
  if (!plan) return null;
  return (
    <div className="mb-2 rounded-xl border border-amber-400/20 bg-gradient-to-r from-amber-400/10 to-teal-400/5 px-3 py-2.5">
      <div className="flex items-center gap-2">
        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-amber-400/15">
          <Crown className="h-3.5 w-3.5 text-amber-300" />
        </span>
        <p className="truncate text-[11px] text-sidebar-foreground/60">
          {locale === 'ar' ? 'خطتك' : 'Plan'}: <span className="font-bold text-amber-200">{plan.name}</span>
        </p>
      </div>
      {plan.status === 'trialing' && plan.trial_ends_at && (
        <p className="mt-1 ps-8 text-[10px] text-yellow-400/90">
          {locale === 'ar' ? 'تجربة تنتهي' : 'Trial ends'}: {new Date(plan.trial_ends_at).toLocaleDateString()}
        </p>
      )}
    </div>
  );
}

type PanelShellProps = {
  variant: 'admin' | 'teacher' | 'student';
  /** يخفي بطاقة الخطة والحساب من أسفل القائمة (مثل صفحة الإعدادات) */
  hideAccount?: boolean;
  children: React.ReactNode;
};

export function PanelShell({ variant, children, hideAccount = false }: PanelShellProps) {
  const pathname = usePathname();
  // صفحة الإعدادات تُخفي بطاقة الحساب/الخطة تلقائياً (لا قيمة مكررة هناك).
  const showAccount = !hideAccount && !pathname.startsWith('/teacher/settings') && !pathname.startsWith('/admin/settings');
  const { user, logout } = useAuth();
  const { locale, setLocale } = useLocale();
  const { settings } = useSettings();
  const path = usePathname();
  const [drawer, setDrawer] = useState(false);
  const { focused } = useFocus();
  const [collapsed, setCollapsed] = useState(() => {
    if (typeof window === 'undefined') return false;
    return localStorage.getItem('sidebar-collapsed') === '1';
  });

  const isStudent = variant === 'student';
  const groups = variant === 'admin' ? ADMIN_NAV : variant === 'teacher' ? TEACHER_NAV : STUDENT_NAV;

  useEffect(() => { setDrawer(false); }, [path]);
  useEffect(() => {
    try { localStorage.setItem('sidebar-collapsed', collapsed ? '1' : '0'); } catch { /* */ }
  }, [collapsed]);

  // Focus mode: hide every shell element so the page (course lesson, exam…)
  // fills the whole viewport.
  if (focused) {
    return <div className="min-h-screen bg-background">{children}</div>;
  }

  const title = navLabelFor(path, locale, groups);
  const sidebarWidth = collapsed ? 'lg:w-[76px]' : 'lg:w-[264px]';

  return (
    <div className="flex min-h-screen bg-background">
      {/* ── Desktop sidebar ── */}
      <aside
        className={cn(
          'fixed inset-y-0 start-0 z-40 hidden bg-sidebar text-sidebar-foreground transition-all duration-200 lg:flex',
          sidebarWidth,
        )}
      >
        <div className="absolute -end-3 top-20 z-10 hidden xl:flex">
          <button
            onClick={() => setCollapsed(!collapsed)}
            className="flex h-7 w-7 items-center justify-center rounded-full border border-sidebar-border bg-sidebar text-sidebar-foreground/60 shadow-lg transition-colors hover:text-white"
            aria-label={collapsed ? (locale === 'ar' ? 'توسيع القائمة' : 'Expand sidebar') : (locale === 'ar' ? 'طي القائمة' : 'Collapse sidebar')}
          >
            {collapsed ? <PanelLeftOpen className="h-3.5 w-3.5" /> : <PanelLeftClose className="h-3.5 w-3.5" />}
          </button>
        </div>
        <SidebarNav groups={groups} collapsed={collapsed} variant={variant} showAccount={showAccount} />
      </aside>

      {/* ── Mobile drawer ── */}
      <div className={cn('fixed inset-0 z-50 lg:hidden', drawer ? 'pointer-events-auto' : 'pointer-events-none')}>
        <div
          className={cn('absolute inset-0 bg-black/50 backdrop-blur-sm transition-opacity', drawer ? 'opacity-100' : 'opacity-0')}
          onClick={() => setDrawer(false)}
          aria-hidden="true"
        />
        <aside
          className={cn(
            'absolute inset-y-0 start-0 flex w-[280px] max-w-[85vw] bg-sidebar text-sidebar-foreground shadow-2xl transition-transform duration-300',
            drawer ? 'translate-x-0' : 'ltr:-translate-x-full rtl:translate-x-full',
          )}
          role="dialog"
          aria-modal="true"
          aria-label={locale === 'ar' ? 'قائمة التنقل' : 'Navigation'}
        >
          <SidebarNav groups={groups} variant={variant} />
        </aside>
      </div>

      {/* ── Content column ── */}
      <div className={cn('flex min-w-0 flex-1 flex-col transition-all duration-200', collapsed ? 'lg:ms-[76px]' : 'lg:ms-[264px]')}>
        <header className="sticky top-0 z-30 flex h-14 items-center gap-2 border-b bg-background/85 px-3 backdrop-blur-xl sm:px-4">
          <button
            onClick={() => setDrawer(true)}
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border text-muted-foreground hover:bg-muted lg:hidden"
            aria-label={locale === 'ar' ? 'فتح القائمة' : 'Open menu'}
          >
            <Menu className="h-5 w-5" />
          </button>
          <h1 className="min-w-0 truncate text-sm font-bold sm:text-base">{title}</h1>
          <div className="ms-auto flex shrink-0 items-center gap-1.5">
            {settings.logo_url && <img src={settings.logo_url} alt="logo" className="hidden h-7 w-7 rounded-lg object-cover sm:block" />}
            {!settings.logo_url && <img src="/logo.png" alt="logo" className="hidden h-7 w-7 rounded-lg object-cover sm:block" />}
            <button
              onClick={() => setLocale(locale === 'ar' ? 'en' : 'ar')}
              className="flex h-9 w-9 items-center justify-center rounded-xl text-muted-foreground hover:bg-muted"
              aria-label={locale === 'ar' ? 'Switch to English' : 'التبديل إلى العربية'}
            >
              <Globe className="h-4 w-4" />
              <span className="sr-only">EN/AR</span>
            </button>
            {user ? (
              <button
                onClick={logout}
                className="flex h-9 w-9 items-center justify-center rounded-xl text-muted-foreground hover:bg-muted lg:hidden"
                aria-label={locale === 'ar' ? 'تسجيل خروج' : 'Logout'}
              >
                <LogOut className="h-4 w-4" />
              </button>
            ) : null}
            {user && (
              <span className="hidden h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary lg:flex">
                {init(user.name)}
              </span>
            )}
          </div>
        </header>

        <main className={cn('flex-1 p-4 sm:p-6', isStudent && 'pb-24 lg:pb-8')}>{children}</main>
      </div>

      {/* ── Student bottom navigation (mobile) ── */}
      {isStudent && (
        <nav
          className="fixed inset-x-0 bottom-0 z-40 border-t bg-card/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-xl lg:hidden"
          aria-label={locale === 'ar' ? 'تنقل سفلي' : 'Bottom navigation'}
        >
          <div className="grid grid-cols-5">
            {STUDENT_BOTTOM_NAV.map((item) => {
              const active = path === item.href || (item.match ?? []).some((m) => m !== '/' && path.startsWith(m + '/'));
              const label = locale === 'ar' ? item.ar : item.en;
              return (
                <a
                  key={item.href}
                  href={item.href}
                  className={cn(
                    'group flex flex-col items-center gap-0.5 py-2 text-[10px] font-bold transition-colors',
                    active ? 'text-teal-700' : 'text-muted-foreground hover:text-foreground',
                  )}
                  aria-current={active ? 'page' : undefined}
                >
                  <span className={cn('flex h-7 w-12 items-center justify-center rounded-full transition-all', active && 'bg-teal-600 shadow-brand')}>
                    <item.icon className={cn('h-5 w-5 transition-colors', active && 'text-white')} />
                  </span>
                  {label}
                </a>
              );
            })}
          </div>
        </nav>
      )}

      {/* Mobile drawer close button (accessibility) */}
      <button
        className="sr-only"
        onClick={() => setDrawer(false)}
        tabIndex={drawer ? 0 : -1}
        aria-label={locale === 'ar' ? 'إغلاق القائمة' : 'Close menu'}
      >
        <X className="h-5 w-5" />
      </button>
    </div>
  );
}
