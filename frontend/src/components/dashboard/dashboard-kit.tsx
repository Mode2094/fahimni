'use client';

import { useEffect, useState, type ComponentType, type ReactNode } from 'react';
import { ChevronDown } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * Shared building blocks for the panel dashboards (teacher + admin).
 *
 * One responsive component set serves every width — the same markup renders the
 * phone layout (2-up tiles, collapsed sections) and the desktop layout (4-up
 * tiles, always-open sections) instead of duplicating the interface twice.
 */

/**
 * Tracks the desktop breakpoint so a section header behaves as a real toggle on
 * phones and as a plain, always-open heading on large screens. SSR-safe: it
 * starts `false` (phone shape) and settles after mount.
 */
export function useIsDesktop() {
  const [isDesktop, setIsDesktop] = useState(false);
  useEffect(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return;
    const mq = window.matchMedia('(min-width: 1024px)');
    const onChange = () => setIsDesktop(mq.matches);
    onChange();
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, []);
  return isDesktop;
}

/**
 * A dashboard section. On phones it collapses behind its header (progressive
 * disclosure) so the page opens short and focused; on desktop it is always
 * expanded.
 */
export function SectionCard({
  title, icon: Icon, description, badge, defaultOpen = false, forceOpen = false, children, testId,
}: {
  title: string;
  icon: ComponentType<{ className?: string }>;
  description?: string;
  badge?: ReactNode;
  defaultOpen?: boolean;
  forceOpen?: boolean;
  children: ReactNode;
  testId?: string;
}) {
  const [open, setOpen] = useState(defaultOpen);
  const expanded = forceOpen || open;

  return (
    <Card className="overflow-hidden border-0 shadow-sm" data-testid={testId}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={expanded}
        className={cn(
          'flex w-full items-center gap-3 p-4 text-start transition-colors hover:bg-muted/40 lg:p-5',
          forceOpen && 'lg:pointer-events-none lg:hover:bg-transparent',
        )}
      >
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-teal-500/10">
          <Icon className="h-[18px] w-[18px] text-teal-600" />
        </span>
        <span className="min-w-0 flex-1">
          <span className="flex items-center gap-2">
            <span className="font-bold">{title}</span>
            {badge}
          </span>
          {description && <span className="mt-0.5 block text-xs text-muted-foreground">{description}</span>}
        </span>
        <ChevronDown
          className={cn('h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200 lg:hidden', expanded && 'rotate-180')}
        />
      </button>
      <div className={cn('px-4 pb-4 sm:px-5 sm:pb-5', expanded ? 'block' : 'hidden')}>{children}</div>
    </Card>
  );
}

/** One headline statistic (2-up on phones, 4-up on desktop), linked to its page. */
export function StatTile({
  label, value, href, icon: Icon, tone, testId,
}: {
  label: string;
  value: ReactNode;
  href?: string;
  icon: ComponentType<{ className?: string }>;
  tone: string;
  testId?: string;
}) {
  const body = (
    <Card className="h-full border-0 shadow-sm transition-all duration-200 group-hover:-translate-y-0.5 group-hover:shadow-md">
      <CardContent className="flex h-full flex-col gap-3 p-4 sm:p-5">
        <span className={cn('flex h-10 w-10 items-center justify-center rounded-xl', tone)}>
          <Icon className="h-5 w-5" />
        </span>
        <div className="min-w-0">
          <p className="text-2xl font-bold leading-none tabular-nums">{value}</p>
          <p className="mt-1.5 text-xs text-muted-foreground sm:text-sm">{label}</p>
        </div>
      </CardContent>
    </Card>
  );

  if (!href) {
    return <div className="h-full" data-testid={testId}>{body}</div>;
  }

  return (
    <a href={href} data-testid={testId} className="group h-full rounded-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
      {body}
    </a>
  );
}
