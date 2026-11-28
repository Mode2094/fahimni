'use client';

import { useEffect, useRef, useState } from 'react';
import Image from 'next/image';
import { usePathname } from 'next/navigation';
import { GraduationCap, LogOut, Globe, ChevronRight, ChevronDown, ChevronUp, Menu, MoreHorizontal, X, PanelLeftClose, PanelLeftOpen, Crown, Search } from 'lucide-react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { resolveLogoUrl } from '@/lib/logo';
import { usePlan } from '@/store/plan';
import { useFocus } from '@/store/focus';
import { cn, init } from '@/lib/utils';
import {
  ADMIN_NAV, TEACHER_NAV, STUDENT_NAV, STUDENT_BOTTOM_NAV, filterNavGroups, isMoreNavRoute, navLabelFor,
  type NavGroup, type NavItem,
} from './nav-config';
import { TeacherNotificationBell } from './teacher-notification-bell';
import ViewAsBanner from './view-as-banner';
import AdminSupportBanner from './admin-support-banner';
import { useLeaderboard } from '@/store/leaderboard';

type UnreadCounts = { notifications: number; messages: number; adminNotifications: number };

/* ── One unread poller shared by the sidebar and mobile navigation ── */
function useUnread(variant: 'admin' | 'teacher' | 'student'): UnreadCounts {
  const [counts, setCounts] = useState<UnreadCounts>({ notifications: 0, messages: 0, adminNotifications: 0 });

  useEffect(() => {
    const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';
    const headers = { 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' };
    let mounted = true;

    const loadCount = async (path: string, update: (count: number) => void, keys: string[]) => {
      try {
        const response = await fetch(`${API}${path}`, { headers });
        if (!response.ok) return;
        const data = await response.json();
        const raw = keys.map((key) => data?.[key]).find((value) => value !== undefined);
        const count = Number(raw);
        if (mounted && Number.isFinite(count) && count >= 0) update(count);
      } catch {
        // Keep the last server-confirmed count when a transient poll fails.
      }
    };

    const load = () => {
      const requests: Promise<void>[] = [];
      if (variant === 'student' || variant === 'teacher') {
        requests.push(loadCount('/conversations/unread-total', (messages) => {
          setCounts((current) => ({ ...current, messages }));
        }, ['total', 'unread_count']));
      }
      if (variant === 'student') {
        requests.push(loadCount('/notifications/unread-count', (notifications) => {
          setCounts((current) => ({ ...current, notifications }));
        }, ['unread_count']));
      }
      if (variant === 'teacher') {
        requests.push(loadCount('/admin-notifications/unread-count', (adminNotifications) => {
          setCounts((current) => ({ ...current, adminNotifications }));
        }, ['unread_count']));
      }
      void Promise.all(requests);
    };

    load();
    const i = setInterval(load, 30000);
    return () => {
      mounted = false;
      clearInterval(i);
    };
  }, [variant]);

  return counts;
}

type SidebarProps = {
  groups: NavGroup[];
  collapsed?: boolean;
  variant: 'admin' | 'teacher' | 'student';
  showAccount?: boolean;
  unread: UnreadCounts;
  collapsedGroups: string[];
  onToggleGroup: (key: string) => void;
  onClose?: () => void;
};

function SidebarNav({
  groups,
  collapsed,
  variant,
  showAccount = true,
  unread,
  collapsedGroups,
  onToggleGroup,
  onClose,
}: SidebarProps) {
  const { settings } = useSettings();
  const { locale } = useLocale();
  const { user, logout } = useAuth();
  const { gates } = usePlan();
  const path = usePathname();
  const isAdmin = variant === 'admin';
  const [query, setQuery] = useState('');
  const searchId = collapsed === undefined ? 'teacher-navigation-search-mobile' : 'teacher-navigation-search-desktop';

  const badgeFor = (item: NavItem): number => {
    if (item.badge === 'notifications') return unread.notifications;
    if (item.badge === 'messages') return unread.messages;
    if (item.badge === 'adminNotifications') return unread.adminNotifications;
    return 0;
  };

  const isActive = (item: NavItem) => item.href === activeHref;

  // A disabled leaderboard must not leave a link behind on any student surface.
  const leaderboardEnabled = useLeaderboard((s) => s.enabled);
  const hideLeaderboard = variant === 'student' && leaderboardEnabled === false;

  const visibleGroups = groups
    .map((g) => ({ ...g, items: g.items.filter((i) => (!i.gate || gates[i.gate]) && !(i.hideWithApp && settings.student_app_hidden) && !(hideLeaderboard && i.href === '/leaderboard')) }))
    .filter((g) => g.items.length > 0);
  const filteredGroups = variant === 'teacher' ? filterNavGroups(visibleGroups, query, locale) : visibleGroups;

  // Exactly one nav item is highlighted: an exact/tab match wins over a section
  // prefix, and a deeper prefix wins over a shallower one. Without this,
  // `/admin/invoices` (a tab of «المبيعات») highlighted nothing, and
  // `/admin/monitoring/frontend` highlighted both itself and its parent.
  const normPath = path.replace(/\/+$/, '') || '/';
  const allItems = groups.flatMap((g) => g.items);
  let activeHref: string | null =
    allItems.find((i) => i.href === normPath || (i.match ?? []).includes(normPath))?.href ?? null;
  if (!activeHref) {
    let bestLen = -1;
    for (const item of allItems) {
      for (const m of [item.href, ...(item.match ?? [])]) {
        if (m && m !== '/' && normPath.startsWith(m + '/') && m.length > bestLen) {
          activeHref = item.href;
          bestLen = m.length;
        }
      }
    }
  }

  return (
    <div className="flex h-full flex-col">
      <div className="flex h-16 shrink-0 items-center justify-between border-b border-sidebar-border px-4">
        <div className="flex items-center gap-2.5 min-w-0">
          <img src={resolveLogoUrl(settings.logo_url)} alt="logo" className="h-9 w-9 shrink-0 rounded-xl object-cover ring-1 ring-white/10" onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }} />
          {!collapsed && <LogoName />}
        </div>
        {onClose && (
          <button
            type="button"
            onClick={onClose}
            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-white"
            aria-label={locale === 'ar' ? 'إغلاق القائمة' : 'Close menu'}
          >
            <X className="h-5 w-5" aria-hidden="true" />
          </button>
        )}
      </div>

      <nav className="flex-1 space-y-5 overflow-y-auto p-3" aria-label={locale === 'ar' ? 'التنقل الرئيسي' : 'Main navigation'}>
        {variant === 'teacher' && !collapsed && (
          <div className="relative">
            <label className="sr-only" htmlFor={searchId}>
              {locale === 'ar' ? 'ابحث في صفحات المعلم' : 'Search teacher pages'}
            </label>
            <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-sidebar-foreground/45" aria-hidden="true" />
            <input
              id={searchId}
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder={locale === 'ar' ? 'ابحث عن صفحة…' : 'Search pages…'}
              className="h-10 w-full rounded-xl border border-sidebar-border bg-sidebar-accent/60 ps-9 pe-3 text-sm text-sidebar-foreground placeholder:text-sidebar-foreground/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-400"
            />
          </div>
        )}

        {filteredGroups.map((g, gi) => {
          const groupKey = g.title?.en ?? `untitled-${gi}`;
          const groupId = `navigation-${variant}-group-${gi}`;
          const canCollapseGroup = variant === 'teacher' && !!g.title;
          const isCollapsed = canCollapseGroup && collapsedGroups.includes(groupKey) && !query.trim();
          return (
            <div key={groupKey}>
              {g.title && !collapsed && canCollapseGroup && (
                <button
                  type="button"
                  onClick={() => !query.trim() && onToggleGroup(groupKey)}
                  aria-expanded={!isCollapsed}
                  aria-controls={groupId}
                  disabled={!!query.trim()}
                  className="mb-1.5 flex min-h-8 w-full items-center justify-between gap-2 rounded-lg px-3 text-start text-[11px] font-bold uppercase tracking-wider text-sidebar-foreground/55 hover:text-sidebar-foreground disabled:cursor-default"
                >
                  <span>{locale === 'ar' ? g.title.ar : g.title.en}</span>
                  {isCollapsed
                    ? <ChevronDown className="h-3.5 w-3.5" aria-hidden="true" />
                    : <ChevronUp className="h-3.5 w-3.5" aria-hidden="true" />}
                </button>
              )}
              {g.title && !collapsed && !canCollapseGroup && (
                <p className="mb-1.5 px-3 text-[11px] font-bold uppercase tracking-wider text-sidebar-foreground/40">
                  {locale === 'ar' ? g.title.ar : g.title.en}
                </p>
              )}
              {g.title && collapsed && <div className="mx-2 mb-1.5 border-t border-sidebar-border" />}
              <div id={groupId} hidden={isCollapsed} className="space-y-0.5">
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
                        variant !== 'admin' && 'min-h-11',
                        collapsed && 'justify-center px-0',
                        active
                          ? 'bg-gradient-to-r from-teal-600 to-teal-500 font-semibold text-white shadow-lg shadow-teal-950/40'
                          : 'text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground',
                      )}
                      aria-label={badge > 0
                        ? `${label}${locale === 'ar' ? '، ' : ', '}${badge} ${locale === 'ar' ? 'غير مقروء' : 'unread'}`
                        : undefined}
                      aria-current={active ? 'page' : undefined}
                    >
                      {active && (
                        <span className={cn('absolute start-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-full bg-teal-300', collapsed && 'hidden')} />
                      )}
                      <item.icon className={cn('h-[18px] w-[18px] shrink-0', active && 'text-teal-100')} aria-hidden="true" />
                      {!collapsed && <span className="truncate">{label}</span>}
                      {!collapsed && badge > 0 && (
                        <span className="ms-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1.5 text-[10px] font-bold text-white" aria-hidden="true">
                          {badge > 99 ? '99+' : badge}
                        </span>
                      )}
                      {collapsed && badge > 0 && (
                        <span className="absolute -top-0.5 -end-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-bold text-white" aria-hidden="true">
                          {badge > 99 ? '99+' : badge}
                        </span>
                      )}
                    </a>
                  );
                })}
              </div>
            </div>
          );
        })}
        {variant === 'teacher' && query.trim() && filteredGroups.length === 0 && (
          <p className="px-3 py-4 text-sm text-sidebar-foreground/60" role="status">
            {locale === 'ar' ? 'لا توجد صفحات مطابقة' : 'No matching pages'}
          </p>
        )}
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
    <span className="truncate text-base font-bold text-white">{settings.platform_name || (locale === 'ar' ? 'منصتي' : 'Fahimni')}</span>
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
  const gates = usePlan((state) => state.gates);
  const path = usePathname();
  const [drawer, setDrawer] = useState(false);
  const drawerRef = useRef<HTMLElement>(null);
  const drawerTriggerRef = useRef<HTMLButtonElement>(null);
  const [collapsedGroups, setCollapsedGroups] = useState<string[]>([]);
  const [collapsedGroupsLoaded, setCollapsedGroupsLoaded] = useState(false);
  const { focused } = useFocus();
  const [collapsed, setCollapsed] = useState(() => {
    if (typeof window === 'undefined') return false;
    return localStorage.getItem('sidebar-collapsed') === '1';
  });

  const isStudent = variant === 'student';
  const groups = variant === 'admin' ? ADMIN_NAV : variant === 'teacher' ? TEACHER_NAV : STUDENT_NAV;
  const unread = useUnread(variant);
  const visibleBottomItems = STUDENT_BOTTOM_NAV.filter((item) => !item.gate || gates[item.gate]);
  const moreIsActive = isStudent && isMoreNavRoute(path, visibleBottomItems);
  const collapsedGroupsKey = `sidebar-collapsed-groups:${variant}`;

  // Resolve the tenant's leaderboard visibility so student navigation can hide
  // ranking links when the teacher disabled it.
  const loadLeaderboard = useLeaderboard((s) => s.load);
  useEffect(() => {
    if (isStudent) void loadLeaderboard();
  }, [isStudent, loadLeaderboard]);

  useEffect(() => { setDrawer(false); }, [path]);
  useEffect(() => {
    if (variant !== 'teacher') {
      setCollapsedGroups([]);
      setCollapsedGroupsLoaded(true);
      return;
    }
    try {
      const stored = localStorage.getItem(collapsedGroupsKey);
      const parsed: unknown = stored ? JSON.parse(stored) : [];
      setCollapsedGroups(Array.isArray(parsed) ? parsed.filter((item): item is string => typeof item === 'string') : []);
    } catch {
      setCollapsedGroups([]);
    } finally {
      setCollapsedGroupsLoaded(true);
    }
  }, [collapsedGroupsKey, variant]);
  useEffect(() => {
    if (variant !== 'teacher' || !collapsedGroupsLoaded) return;
    try {
      localStorage.setItem(collapsedGroupsKey, JSON.stringify(collapsedGroups));
    } catch {
      // The preference is optional; navigation remains usable without storage.
    }
  }, [collapsedGroups, collapsedGroupsKey, collapsedGroupsLoaded, variant]);

  const toggleNavGroup = (key: string) => {
    setCollapsedGroups((current) =>
      current.includes(key) ? current.filter((item) => item !== key) : [...current, key],
    );
  };
  useEffect(() => {
    if (!drawer) return;

    const focusable = () => Array.from(
      drawerRef.current?.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
      ) ?? [],
    ).filter((element) => !element.closest('[hidden], [aria-hidden="true"]') && !element.hasAttribute('disabled'));

    focusable()[0]?.focus();

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        setDrawer(false);
        return;
      }
      if (event.key !== 'Tab') return;

      const items = focusable();
      if (items.length === 0) {
        event.preventDefault();
        return;
      }

      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && (document.activeElement === first || !drawerRef.current?.contains(document.activeElement))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !drawerRef.current?.contains(document.activeElement))) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      drawerTriggerRef.current?.focus();
    };
  }, [drawer]);
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
        <SidebarNav
          groups={groups}
          collapsed={collapsed}
          variant={variant}
          showAccount={showAccount}
          unread={unread}
          collapsedGroups={collapsedGroups}
          onToggleGroup={toggleNavGroup}
        />
      </aside>

      {/* ── Mobile drawer ── */}
      <div className={cn('fixed inset-0 z-50 lg:hidden', drawer ? 'pointer-events-auto' : 'pointer-events-none')}>
        <div
          className={cn('absolute inset-0 bg-black/50 backdrop-blur-sm transition-opacity', drawer ? 'opacity-100' : 'opacity-0')}
          onClick={() => setDrawer(false)}
          aria-hidden="true"
        />
        <aside
          id="mobile-navigation-drawer"
          ref={drawerRef}
          aria-hidden={!drawer}
          inert={!drawer}
          className={cn(
            'absolute inset-y-0 start-0 flex w-[280px] max-w-[85vw] flex-col bg-sidebar pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] text-sidebar-foreground shadow-2xl transition-transform duration-300',
            drawer ? 'translate-x-0' : 'ltr:-translate-x-full rtl:translate-x-full',
          )}
          role="dialog"
          aria-modal="true"
          aria-label={locale === 'ar' ? 'قائمة التنقل' : 'Navigation'}
        >
          <SidebarNav
            groups={groups}
            variant={variant}
            unread={unread}
            collapsedGroups={collapsedGroups}
            onToggleGroup={toggleNavGroup}
            onClose={() => setDrawer(false)}
          />
        </aside>
      </div>

      {/* ── Content column ── */}
      <div className={cn('flex min-w-0 flex-1 flex-col transition-all duration-200', collapsed ? 'lg:ms-[76px]' : 'lg:ms-[264px]')}>
        <ViewAsBanner />
        <AdminSupportBanner />
        <header className="sticky top-0 z-30 flex min-h-14 items-center gap-2 border-b bg-background/85 px-3 pt-[env(safe-area-inset-top)] backdrop-blur-xl sm:px-4">
          {!isStudent && (
            <button
              type="button"
              ref={drawerTriggerRef}
              onClick={() => setDrawer(true)}
              className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border text-muted-foreground hover:bg-muted lg:hidden"
              aria-label={locale === 'ar' ? 'فتح القائمة' : 'Open menu'}
            >
              <Menu className="h-5 w-5" />
            </button>
          )}
          <h1 className="min-w-0 truncate text-sm font-bold sm:text-base">{title}</h1>
          <div className="ms-auto flex shrink-0 items-center gap-1.5">
            {settings.logo_url && <img src={settings.logo_url} alt="logo" className="hidden h-7 w-7 rounded-lg object-cover sm:block" />}
            {!settings.logo_url && <Image src="/logo.png" alt="logo" width={28} height={28} className="hidden h-7 w-7 rounded-lg object-cover sm:block" />}
            {variant === 'teacher' && <TeacherNotificationBell />}
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

        <main className={cn('flex-1 p-4 sm:p-6', (isStudent || variant === 'teacher') && 'pb-28 lg:pb-8')}>{children}</main>
      </div>

      {/* ── Student bottom navigation (mobile) ── */}
      {isStudent && (
        <nav
          className="fixed inset-x-0 bottom-0 z-40 border-t bg-card/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-xl lg:hidden"
          aria-label={locale === 'ar' ? 'تنقل سفلي' : 'Bottom navigation'}
        >
          <div className="flex items-stretch">
            {visibleBottomItems.map((item) => {
              const norm = path.replace(/\/+$/, '') || '/';
              const active = norm === item.href || (item.match ?? []).some((m) => m !== '/' && (norm === m || norm.startsWith(m + '/')));
              const label = locale === 'ar' ? item.ar : item.en;
              const badge = item.badge === 'messages' ? unread.messages : 0;
              return (
                <a
                  key={item.href}
                  href={item.href}
                  className={cn(
                    'group relative flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 py-2 text-[10px] font-bold transition-colors',
                    active ? 'text-teal-700' : 'text-muted-foreground hover:text-foreground',
                  )}
                  aria-current={active ? 'page' : undefined}
                  aria-label={badge > 0
                    ? `${label}${locale === 'ar' ? '، ' : ', '}${badge} ${locale === 'ar' ? 'غير مقروء' : 'unread'}`
                    : undefined}
                >
                  <span className={cn('flex h-7 w-12 items-center justify-center rounded-full transition-all', active && 'bg-teal-600 shadow-brand')}>
                    <item.icon className={cn('h-5 w-5 transition-colors', active && 'text-white')} aria-hidden="true" />
                  </span>
                  {badge > 0 && (
                    <span className="absolute end-1/2 top-1 flex min-h-4 min-w-4 translate-x-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-bold text-white" aria-hidden="true">
                      {badge > 99 ? '99+' : badge}
                    </span>
                  )}
                  {label}
                </a>
              );
            })}
            <button
              type="button"
              ref={drawerTriggerRef}
              onClick={() => setDrawer(true)}
              className={cn(
                'relative flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 py-2 text-[10px] font-bold transition-colors',
                moreIsActive ? 'text-teal-700' : 'text-muted-foreground hover:text-foreground',
              )}
              aria-label={unread.notifications > 0
                ? `${locale === 'ar' ? 'المزيد، ' : 'More, '}${unread.notifications} ${locale === 'ar' ? 'إشعارات غير مقروءة' : 'unread notifications'}`
                : (locale === 'ar' ? 'المزيد' : 'More')}
              aria-expanded={drawer}
              aria-controls="mobile-navigation-drawer"
              aria-current={moreIsActive ? 'page' : undefined}
            >
              <span className={cn('flex h-7 w-12 items-center justify-center rounded-full transition-all', moreIsActive && 'bg-teal-600 shadow-brand')}>
                <MoreHorizontal className={cn('h-5 w-5 transition-colors', moreIsActive && 'text-white')} aria-hidden="true" />
              </span>
              {unread.notifications > 0 && (
                <span className="absolute end-1/2 top-1 flex min-h-4 min-w-4 translate-x-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-bold text-white" aria-hidden="true">
                  {unread.notifications > 99 ? '99+' : unread.notifications}
                </span>
              )}
              {locale === 'ar' ? 'المزيد' : 'More'}
            </button>
          </div>
        </nav>
      )}

    </div>
  );
}
