'use client';

import { useState } from 'react';
import { cn } from '@/lib/utils';

type TabsProps = {
  tabs: { value: string; label: string; icon?: React.ReactNode }[];
  defaultValue?: string;
  value?: string;
  onChange?: (v: string) => void;
  className?: string;
};

export function Tabs({ tabs, defaultValue, value, onChange, className }: TabsProps) {
  const [internal, setInternal] = useState(defaultValue ?? tabs[0]?.value);
  const current = value ?? internal;
  return (
    <div
      className={cn('inline-flex items-center gap-1 overflow-x-auto rounded-xl bg-muted p-1', className)}
      role="tablist"
      aria-label="Tabs"
    >
      {tabs.map((t) => {
        const active = current === t.value;
        return (
          <button
            key={t.value}
            role="tab"
            aria-selected={active}
            onClick={() => { setInternal(t.value); onChange?.(t.value); }}
            className={cn(
              'flex shrink-0 items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-medium transition-all',
              active ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
            )}
          >
            {t.icon}
            {t.label}
          </button>
        );
      })}
    </div>
  );
}
