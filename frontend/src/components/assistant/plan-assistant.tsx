'use client';

import { useEffect, useRef } from 'react';
import { Bot, Sparkles, X } from 'lucide-react';
import { useAssistant } from '@/store/assistant';
import { useLocale } from '@/store/locale';
import TypingIndicator from './typing-indicator';
import PlanResultCard from './plan-result-card';

export default function PlanAssistant() {
  const { open, seen, messages, asking, toggle, closePanel } = useAssistant();
  const { locale } = useLocale();
  const isAr = locale === 'ar';
  const listRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const el = listRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [messages, open]);

  const qCount = messages.filter((m) => m.kind === 'question').length;

  return (
    <>
      {/* ─── اللوحة ─────────────────────────────────── */}
      {open && (
        <div className="assistant-pop fixed bottom-24 start-6 z-50 flex h-[min(560px,calc(100dvh-9rem))] w-[390px] max-w-[calc(100vw-1.5rem)] flex-col overflow-hidden rounded-[1.5rem] bg-white shadow-2xl shadow-slate-900/20 ring-1 ring-slate-900/5">
          {/* الترويسة */}
          <div className="relative shrink-0 bg-gradient-to-l from-teal-600 via-teal-500 to-emerald-500 px-4 py-3.5 text-white">
            <div className="absolute -start-8 -top-10 h-28 w-28 rounded-full bg-white/10 blur-2xl" />
            <div className="relative flex items-center gap-3">
              <div className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-white/20 ring-1 ring-white/30 backdrop-blur">
                <Bot className="h-5 w-5" />
              </div>
              <div className="flex-1 min-w-0">
                <p className="truncate text-sm font-bold">{isAr ? 'مساعد اختيار الخطة' : 'Plan Assistant'}</p>
                <p className="flex items-center gap-1.5 text-[11px] text-white/80">
                  <span className="relative flex h-2 w-2">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-300 opacity-75" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-300" />
                  </span>
                  {isAr ? 'متصل · رد فوري' : 'Online · instant reply'}
                </p>
              </div>
              <button onClick={closePanel} className="grid h-8 w-8 place-items-center rounded-full text-white/80 transition hover:bg-white/15 hover:text-white" aria-label={isAr ? 'إغلاق' : 'Close'}>
                <X className="h-4 w-4" />
              </button>
            </div>
          </div>

          {/* الرسائل */}
          <div ref={listRef} className="flex-1 space-y-3 overflow-y-auto bg-slate-50 px-4 py-4">
            {messages.map((m) => {
              if (m.kind === 'typing') return <TypingIndicator key={m.id} text={isAr ? 'يكتب...' : 'typing...'} />;

              if (m.kind === 'text') {
                return (
                  <div key={m.id} className={m.from === 'user' ? 'flex justify-end' : 'flex justify-start'}>
                    <div
                      className={
                        m.from === 'user'
                          ? 'max-w-[85%] rounded-2xl rounded-ee-md bg-gradient-to-br from-teal-600 to-teal-500 px-4 py-3 text-sm text-white shadow-md shadow-teal-500/20'
                          : 'max-w-[85%] rounded-2xl rounded-es-md border border-slate-100 bg-white px-4 py-3 text-sm leading-relaxed text-slate-700 shadow-sm'
                      }
                    >
                      {m.text}
                    </div>
                  </div>
                );
              }

              if (m.kind === 'question') {
                let seen = 0;
                for (const x of messages) {
                  if (x.id === m.id) break;
                  if (x.kind === 'question') seen++;
                }
                return (
                  <div key={m.id} className="flex flex-col items-start gap-2">
                    <div className="max-w-[85%] rounded-2xl rounded-es-md border border-slate-100 bg-white px-4 py-3 text-sm leading-relaxed text-slate-700 shadow-sm">
                      <span className="mb-1 inline-block rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-bold text-violet-700">
                        {isAr ? `سؤال ${seen + 1} من ${qCount}` : `Q${seen + 1} of ${qCount}`}
                      </span>
                      <p>{isAr ? m.question.question.ar : m.question.question.en}</p>
                    </div>
                    <div className="flex max-w-[90%] flex-wrap gap-1.5">
                      {m.question.options.map((o) => (
                        <button
                          key={o.key}
                          onClick={() => useAssistant.getState().answer(o.key)}
                          disabled={asking}
                          className="rounded-full border border-violet-200 bg-white px-3.5 py-1.5 text-[13px] font-medium text-slate-700 shadow-sm transition hover:-translate-y-0.5 hover:border-violet-400 hover:bg-violet-50 hover:text-violet-700 active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                          {isAr ? o.label.ar : o.label.en}
                        </button>
                      ))}
                    </div>
                  </div>
                );
              }

              if (m.kind === 'result') {
                return (
                  <div key={m.id} className="flex justify-start">
                    <PlanResultCard result={m.result} />
                  </div>
                );
              }

              return null;
            })}
          </div>

          {/* التذييل */}
          <div className="shrink-0 border-t border-slate-100 bg-white px-4 py-2 text-center text-[10px] text-slate-400">
            {isAr ? 'إجاباتك تُستخدم فقط لاختيار الخطة الأنسب لك ⚡' : 'Your answers are used only to pick your best plan ⚡'}
          </div>
        </div>
      )}

      {/* ─── الزر العائم ───────────────────────────── */}
      <div className="fixed bottom-6 start-6 z-50 flex flex-col items-start gap-2">
        {!open && !seen && (
          <button
            onClick={() => useAssistant.getState().openPanel()}
            className="assistant-pop flex items-center gap-2 rounded-2xl rounded-es-md bg-white px-4 py-2.5 text-[13px] font-bold text-slate-700 shadow-xl shadow-slate-900/10 ring-1 ring-slate-900/5 transition hover:scale-105"
          >
            <Sparkles className="h-4 w-4 text-violet-500" />
            {isAr ? 'اكتشف خطتك المثالية ✨' : 'Find your perfect plan ✨'}
          </button>
        )}

        <button
          onClick={toggle}
          aria-label={isAr ? 'مساعد اختيار الخطة' : 'Plan assistant'}
          className={`group relative grid h-14 w-14 place-items-center rounded-full bg-gradient-to-tr from-teal-600 via-teal-500 to-emerald-500 text-white shadow-xl shadow-teal-500/40 transition-all duration-300 hover:scale-110 hover:shadow-2xl hover:shadow-teal-500/50 active:scale-95 ${open ? 'rotate-90' : ''}`}
        >
          {!open && (
            <span className="absolute inset-0 -z-10 animate-ping rounded-full bg-gradient-to-tr from-teal-600 to-teal-500 opacity-25" style={{ animationDuration: '2.5s' }} />
          )}
          {open ? <X className="h-6 w-6 transition-transform duration-300 group-hover:rotate-90" /> : <Sparkles className="h-6 w-6 transition-transform duration-300 group-hover:rotate-12" />}
        </button>
      </div>
    </>
  );
}
