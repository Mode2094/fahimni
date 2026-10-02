import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

type AlertProps = {
  variant?: 'info' | 'success' | 'warning' | 'error';
  title?: string;
  children?: React.ReactNode;
  icon?: LucideIcon;
  className?: string;
};

const STYLES: Record<string, string> = {
  info: 'border-teal-200 bg-teal-50 text-teal-900',
  success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  warning: 'border-amber-200 bg-amber-50 text-amber-800',
  error: 'border-rose-200 bg-rose-50 text-rose-800',
};

export function Alert({ variant = 'info', title, children, icon: Icon, className }: AlertProps) {
  return (
    <div className={cn('flex items-start gap-3 rounded-2xl border p-4 text-sm', STYLES[variant], className)} role="alert">
      {Icon && <Icon className="mt-0.5 h-4 w-4 shrink-0" />}
      <div className="min-w-0 space-y-0.5">
        {title && <p className="font-bold">{title}</p>}
        {children && <div className="leading-relaxed">{children}</div>}
      </div>
    </div>
  );
}
