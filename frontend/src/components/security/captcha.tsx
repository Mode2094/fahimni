'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RefreshCw, ShieldCheck } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

/**
 * Server-driven math CAPTCHA.
 *
 * Renders nothing when the platform has `captcha_enabled` off, so a form can
 * always mount it unconditionally. The challenge id is single-use on the
 * server (MathCaptcha::verify uses Cache::pull), so callers must remount the
 * component after a failed attempt (pass a changing `key`) to hand the user a
 * fresh challenge.
 */
export function Captcha({
  value,
  onChange,
  onId,
  locale = 'ar',
}: {
  value: string;
  onChange: (v: string) => void;
  onId: (id: string) => void;
  locale?: 'ar' | 'en';
}) {
  const [enabled, setEnabled] = useState(false);
  const [question, setQuestion] = useState('');
  const [id, setId] = useState('');
  const answerId = useId();

  const load = useCallback(() => {
    fetch(`${API}/auth/captcha`, { method: 'POST', headers: { Accept: 'application/json' } })
      .then((r) => r.json())
      .then((d) => {
        setEnabled(!!d.enabled);
        if (d.enabled) {
          setQuestion(d.question);
          setId(d.captcha_id);
          onId(d.captcha_id);
        }
      })
      .catch(() => {
        /* offline: leave the field hidden; the server will reject if enabled */
      });
  }, [onId]);

  useEffect(() => {
    load();
  }, [load]);

  if (!enabled) return null;

  const label = locale === 'ar' ? 'التحقق الأمني' : 'Security check';
  const refreshLabel = locale === 'ar' ? 'تحديث سؤال التحقق' : 'Refresh the security question';
  const placeholder = locale === 'ar' ? 'الحل…' : 'Answer…';

  return (
    <div className="space-y-2">
      <Label htmlFor={answerId} className="text-sm font-medium">
        {label}
      </Label>
      <div className="flex items-center gap-2">
        <div
          className="flex flex-1 items-center gap-2 rounded-xl border bg-gray-100 p-3 select-none"
          dir="ltr"
          style={{ fontFamily: 'monospace', letterSpacing: '0.15em' }}
        >
          <ShieldCheck className="h-4 w-4 shrink-0 text-teal-600" aria-hidden="true" />
          <span className="text-lg font-bold" aria-live="polite">
            {question}
          </span>
        </div>
        <button
          type="button"
          onClick={load}
          aria-label={refreshLabel}
          title={refreshLabel}
          className="flex h-11 w-11 items-center justify-center rounded-xl border hover:bg-gray-50"
        >
          <RefreshCw className="h-4 w-4 text-gray-400" aria-hidden="true" />
        </button>
      </div>
      <Input
        id={answerId}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="h-11"
        placeholder={placeholder}
        inputMode="numeric"
        autoComplete="off"
        autoCorrect="off"
        spellCheck={false}
        required
      />
      {/* Kept for programmatic readers/automation that inspect the DOM. */}
      <input type="hidden" name="captcha_id" value={id} readOnly />
    </div>
  );
}
