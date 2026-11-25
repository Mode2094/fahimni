'use client';

import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { AlertTriangle } from 'lucide-react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * Replacements for the blocking native `window.confirm` / `window.prompt`.
 *
 * Native dialogs are unstyleable, break RTL/focus/accessibility and freeze the
 * page. These render an in-app, fully accessible dialog instead while keeping
 * the exact same call semantics: `confirmDialog` resolves to a boolean and
 * `promptDialog` to the entered string (or `null` when cancelled) — so call
 * sites only need `await`, not a rewrite of their control flow.
 *
 * The imperative API mirrors `sonner`'s `toast()`: a single <ConfirmDialogHost>
 * is mounted once in `<Providers>` and registers itself here on mount. If the
 * host is missing (e.g. a unit test that renders a single page), the helpers
 * gracefully fall back to the native API so nothing ever crashes.
 */

export type ConfirmOptions = {
  /** Short heading. Falls back to a localized generic title. */
  title?: string;
  /** Body text — already-localized string from the call site. */
  description?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  /** Red confirm button + warning icon for irreversible actions. */
  danger?: boolean;
};

export type PromptOptions = ConfirmOptions & {
  inputLabel?: string;
  placeholder?: string;
  defaultValue?: string;
  /** Disable the confirm button while the field is empty. */
  required?: boolean;
};

type ConfirmRequest = {
  id: number;
  kind: 'confirm';
  opts: ConfirmOptions;
  resolve: (value: boolean) => void;
};

type PromptRequest = {
  id: number;
  kind: 'prompt';
  opts: PromptOptions;
  resolve: (value: string | null) => void;
};

type Request = ConfirmRequest | PromptRequest;

let enqueue: ((request: Request) => void) | null = null;
let sequence = 0;

/** Accessible, non-blocking replacement for `window.confirm`. */
export function confirmDialog(opts: ConfirmOptions = {}): Promise<boolean> {
  if (!enqueue) {
    const message =
      typeof opts.description === 'string' ? opts.description : opts.title ?? '';
    const ok = typeof window !== 'undefined' ? window.confirm(message) : false;
    return Promise.resolve(ok);
  }
  return new Promise<boolean>((resolve) => {
    enqueue?.({ id: ++sequence, kind: 'confirm', opts, resolve });
  });
}

/** Accessible, non-blocking replacement for `window.prompt`. */
export function promptDialog(opts: PromptOptions = {}): Promise<string | null> {
  if (!enqueue) {
    const value =
      typeof window !== 'undefined'
        ? window.prompt(opts.inputLabel ?? opts.title ?? '', opts.defaultValue ?? '')
        : null;
    return Promise.resolve(value);
  }
  return new Promise<string | null>((resolve) => {
    enqueue?.({ id: ++sequence, kind: 'prompt', opts, resolve });
  });
}

export function ConfirmDialogHost() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [queue, setQueue] = useState<Request[]>([]);
  const [value, setValue] = useState('');
  const current = queue[0] as Request | undefined;

  const dialogRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const titleId = useId();
  const descId = useId();
  const inputId = useId();

  // Register the imperative bridge for the whole app lifetime.
  useEffect(() => {
    enqueue = (request) => setQueue((q) => [...q, request]);
    return () => {
      enqueue = null;
    };
  }, []);

  // Seed the prompt field each time a new request reaches the head of the queue.
  useEffect(() => {
    if (current?.kind === 'prompt') setValue(current.opts.defaultValue ?? '');
    else setValue('');
  }, [current?.id, current?.kind]); // eslint-disable-line react-hooks/exhaustive-deps

  const settle = (result: boolean | string | null) => {
    if (!current) return;
    if (current.kind === 'confirm') current.resolve(result === true);
    else current.resolve(typeof result === 'string' ? result : null);
    setQueue((q) => q.slice(1));
  };

  const cancel = () => settle(null);

  // Move focus into the dialog when it opens.
  useEffect(() => {
    if (!current) return;
    const id = window.setTimeout(() => {
      if (current.kind === 'prompt') inputRef.current?.focus();
      else dialogRef.current?.querySelector<HTMLElement>('[data-autofocus]')?.focus();
    }, 0);
    return () => window.clearTimeout(id);
  }, [current?.id, current?.kind]); // eslint-disable-line react-hooks/exhaustive-deps

  // Escape to cancel, Tab cycles inside the dialog, Enter submits a prompt.
  useEffect(() => {
    if (!current) return;
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        cancel();
        return;
      }
      if (e.key === 'Enter' && current.kind === 'prompt') {
        const target = e.target as HTMLElement;
        if (target.tagName !== 'TEXTAREA') {
          e.preventDefault();
          const required = current.opts.required && value.trim() === '';
          if (!required) settle(value);
        }
        return;
      }
      if (e.key === 'Tab') {
        const root = dialogRef.current;
        if (!root) return;
        const focusable = root.querySelectorAll<HTMLElement>(
          'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
        );
        const list = Array.from(focusable).filter((el) => !el.hasAttribute('disabled'));
        if (list.length === 0) return;
        const first = list[0];
        const last = list[list.length - 1];
        const active = document.activeElement as HTMLElement | null;
        if (e.shiftKey && active === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && active === last) {
          e.preventDefault();
          first.focus();
        }
      }
    };
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [current, value]); // eslint-disable-line react-hooks/exhaustive-deps

  // Lock background scroll while a dialog is open.
  useEffect(() => {
    if (!current) return;
    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = previous;
    };
  }, [current?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!current) return null;

  const opts = current.opts;
  const danger = opts.danger ?? current.kind === 'confirm';
  const title =
    opts.title ?? (danger ? t('تأكيد العملية', 'Confirm action') : t('تأكيد', 'Confirm'));
  const confirmLabel = opts.confirmLabel ?? t('تأكيد', 'Confirm');
  const cancelLabel = opts.cancelLabel ?? t('إلغاء', 'Cancel');
  const inputDisabled = current.kind === 'prompt' && current.opts.required === true && value.trim() === '';

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center p-4" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
      <div className="absolute inset-0 bg-black/50" onClick={cancel} aria-hidden="true" />
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={opts.description ? descId : undefined}
        className="relative z-10 w-full max-w-md max-h-[90vh] overflow-y-auto rounded-2xl bg-card p-6 text-start shadow-2xl"
      >
        <div className="flex items-start gap-3">
          {danger && (
            <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-destructive/10">
              <AlertTriangle className="h-5 w-5 text-destructive" aria-hidden="true" />
            </span>
          )}
          <div className="min-w-0 flex-1">
            <h2 id={titleId} className="text-lg font-bold">
              {title}
            </h2>
            {opts.description ? (
              <p id={descId} className="mt-2 text-sm leading-relaxed text-muted-foreground break-words whitespace-pre-line">
                {opts.description}
              </p>
            ) : null}
          </div>
        </div>

        {current.kind === 'prompt' && (
          <div className="mt-4">
            <label htmlFor={inputId} className="mb-1.5 block text-sm font-medium">
              {current.opts.inputLabel ?? title}
            </label>
            <input
              id={inputId}
              ref={inputRef}
              value={value}
              onChange={(e) => setValue(e.target.value)}
              placeholder={current.opts.placeholder}
              className={cn(
                'h-11 w-full rounded-xl border border-border bg-background px-3 text-sm',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              )}
              dir="auto"
            />
          </div>
        )}

        <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button type="button" variant="outline" onClick={cancel}>
            {cancelLabel}
          </Button>
          <Button
            data-autofocus
            data-testid="confirm-action"
            type="button"
            variant={danger ? 'destructive' : 'default'}
            disabled={inputDisabled}
            onClick={() => (current.kind === 'prompt' ? settle(value) : settle(true))}
          >
            {confirmLabel}
          </Button>
        </div>
      </div>
    </div>
  );
}
