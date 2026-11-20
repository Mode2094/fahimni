'use client';

import { useEffect, useRef, useState } from 'react';
import { Bot, Send, RotateCcw, Sparkles } from 'lucide-react';
import { useAskMe, SUGGESTIONS } from '@/store/askme';
import { useLocale } from '@/store/locale';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import TypingIndicator from './typing-indicator';

/** تنسيق خفيف للإجابات: خط عريض + فواصل أسطر. */
function renderAnswer(text: string) {
  const lines = text.split('\n');
  return lines.map((line, i) => {
    const bold = line.split(/\*\*(.+?)\*\*/g).map((part, j) =>
      j % 2 === 1 ? (
        <strong key={j} className="font-bold text-slate-900">
          {part}
        </strong>
      ) : (
        <span key={j}>{part}</span>
      ),
    );
    return (
      <p key={i} className={i > 0 ? 'mt-1.5' : ''}>
        {bold}
      </p>
    );
  });
}

/**
 * المساعد «اسألني عن المنصة» — نسخة مضمّنة داخل لوحة المعلم (صفحة كاملة).
 * نفس المتجر والرسائل والاقتراحات و«محادثة جديدة»، لكن بلا أي زر عائم
 * يتقاطع مع شريط التنقّل أو زر الخروج.
 */
export default function AssistantChat() {
  const { messages, asking, ask, restart } = useAskMe();
  const { locale } = useLocale();
  const isAr = locale === 'ar';
  const listRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const [draft, setDraft] = useState('');

  // زرع رسالة الترحيب عند أول فتح للصفحة (نفس سلوك اللوحة الأصلية).
  useEffect(() => {
    if (useAskMe.getState().messages.length === 0) restart();
  }, [restart]);

  useEffect(() => {
    const el = listRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [messages]);

  useEffect(() => {
    inputRef.current?.focus();
  }, []);

  const submit = () => {
    const q = draft.trim();
    if (!q) return;
    setDraft('');
    void ask(q);
  };

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <span className="grid h-11 w-11 place-items-center rounded-2xl bg-gradient-to-tr from-indigo-600 to-violet-600 text-white">
            <Sparkles className="h-5 w-5" />
          </span>
          <div>
            <h1 className="text-xl sm:text-2xl font-bold">{isAr ? 'اسألني عن المنصة' : 'Ask about the platform'}</h1>
            <p className="text-sm text-gray-500">{isAr ? 'مساعدك الشخصي في منصة فهمني' : 'Your personal assistant on Fahimni'}</p>
          </div>
        </div>
        <Button variant="outline" className="rounded-xl gap-2" onClick={restart}>
          <RotateCcw className="h-4 w-4" />
          {isAr ? 'محادثة جديدة' : 'New conversation'}
        </Button>
      </div>

      <Card className="flex h-[min(640px,calc(100dvh-12rem))] flex-col overflow-hidden border-0 shadow-sm">
        <div className="flex items-center gap-3 shrink-0 border-b bg-slate-50/80 px-4 py-3">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-white shadow-sm ring-1 ring-slate-900/5">
            <Bot className="h-4 w-4 text-indigo-600" />
          </span>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-bold text-slate-800">{isAr ? 'اسألني' : 'Ask Me'}</p>
            <p className="flex items-center gap-1.5 text-[11px] text-slate-500">
              <span className="relative flex h-2 w-2">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" />
              </span>
              {isAr ? 'متصل' : 'Online'}
            </p>
          </div>
        </div>

        <div ref={listRef} className="flex-1 space-y-3 overflow-y-auto bg-slate-50 px-4 py-4">
          {messages.map((m) => {
            if ('typing' in m) {
              return <TypingIndicator key={m.id} text={isAr ? 'يفكّر في منصتك...' : 'thinking...'} />;
            }

            return (
              <div key={m.id} data-testid="assistant-msg" data-from={m.from} className={m.from === 'user' ? 'flex justify-end' : 'flex justify-start'}>
                <div
                  className={
                    m.from === 'user'
                      ? 'max-w-[88%] whitespace-pre-wrap rounded-2xl rounded-ee-md bg-gradient-to-br from-indigo-600 to-violet-600 px-4 py-3 text-sm text-white shadow-md shadow-indigo-500/20'
                      : 'max-w-[88%] rounded-2xl rounded-es-md border border-slate-100 bg-white px-4 py-3 text-sm leading-relaxed text-slate-700 shadow-sm'
                  }
                >
                  {m.from === 'bot' ? renderAnswer(m.text) : m.text}
                </div>
              </div>
            );
          })}

          {messages.length === 1 && !asking && (
            <div className="flex flex-wrap gap-1.5 pt-1">
              {SUGGESTIONS.map((s) => (
                <button
                  key={s}
                  onClick={() => void ask(s)}
                  disabled={asking}
                  className="rounded-full border border-indigo-200 bg-white px-3 py-1.5 text-[12px] font-medium text-indigo-700 shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-400 hover:bg-indigo-50 active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {s}
                </button>
              ))}
            </div>
          )}
        </div>

        <div className="shrink-0 border-t border-slate-100 bg-white p-3">
          <div className="flex items-center gap-2">
            <input
              ref={inputRef}
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') submit();
              }}
              disabled={asking}
              placeholder={isAr ? 'اكتب سؤالك عن المنصة...' : 'Ask about your platform...'}
              className="flex-1 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-100 disabled:opacity-60"
            />
            <button
              onClick={submit}
              disabled={asking || !draft.trim()}
              aria-label={isAr ? 'إرسال' : 'Send'}
              className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-600 text-white shadow-md shadow-indigo-500/30 transition hover:scale-105 active:scale-95 disabled:cursor-not-allowed disabled:opacity-40"
            >
              <Send className="h-4 w-4" />
            </button>
          </div>
          <p className="mt-1.5 text-center text-[10px] text-slate-400">
            {isAr ? 'أجيبك بناءً على بيانات منصتك الفعلية فقط — لا أخترع معلومات.' : 'Answers are based only on your real platform data.'}
          </p>
        </div>
      </Card>
    </div>
  );
}
