'use client';

import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { RefreshCw, ShieldCheck } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export function Captcha({ value, onChange, onId }: { value: string; onChange: (v: string) => void; onId: (id: string) => void }) {
  const [enabled, setEnabled] = useState(false);
  const [question, setQuestion] = useState('');
  const [id, setId] = useState('');

  const load = () => {
    fetch(`${API}/auth/captcha`, { method: 'POST', headers: { Accept: 'application/json' } })
      .then(r => r.json()).then(d => {
        setEnabled(!!d.enabled);
        if (d.enabled) { setQuestion(d.question); setId(d.captcha_id); onId(d.captcha_id); }
      }).catch(() => {});
  };

  useEffect(() => { load(); }, []);

  if (!enabled) return null;

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2">
        <div className="flex items-center gap-2 flex-1 p-3 rounded-xl bg-gray-100 border select-none" style={{ fontFamily: 'monospace', letterSpacing: '0.15em' }}>
          <ShieldCheck className="h-4 w-4 text-teal-600 shrink-0" />
          <span className="text-lg font-bold">{question}</span>
        </div>
        <button type="button" onClick={load} className="flex h-11 w-11 items-center justify-center rounded-xl border hover:bg-gray-50" title="تحديث"><RefreshCw className="h-4 w-4 text-gray-400" /></button>
      </div>
      <Input value={value} onChange={(e) => onChange(e.target.value)} className="h-11" placeholder="الحل..." inputMode="numeric" />
      <input type="hidden" value={id} />
    </div>
  );
}
