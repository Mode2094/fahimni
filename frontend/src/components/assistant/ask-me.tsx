'use client';

import { useEffect, useRef, useState } from 'react';
import { Bot, Send, Sparkles, X, RotateCcw } from 'lucide-react';
import { useAskMe, SUGGESTIONS } from '@/store/askme';
import { useLocale } from '@/store/locale';
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

export default function AskMe() {
  const { open, seen, messages, asking, toggle, closePanel, ask, restart } = useAskMe();
  const { locale } = useLocale();
  const isAr = locale === 'ar';
  const listRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const [draft, setDraft] = useState('');

  useEffect(() => {
    const el = listRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [messages, open]);

  useEffect(() => {
    if (open) inputRef.current?.focus();
  }, [open]);

  const submit = () => {
    const q = draft.trim();
    if (!q) return;
    setDraft('');
    void ask(q);
  };

  return (
    <>
      {open && (
        <div className="assistant-pop fixed bottom-24 start-6 z-50 flex h-[min(580px,calc(100dvh-9rem))] w-[390px] max-w-[calc(100vw-1.5rem)] flex-col overflow-hidden rounded-[1.5rem] bg-white shadow-2xl shadow-slate-900/20 ring-1 ring-slate-900/5">
          {/* الترويسة */}
          <div className="relative shrink-0 bg-gradient-to-l from-indigo-700 via-indigo-600 to-violet-600 px-4 py-3.5 text-white">
            <div className="absolute -start-8 -top-10 h-28 w-28 rounded-full bg-white/10 blur-2xl" />
            <div className="relative flex items-center gap-3">
              <div className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-white/20 ring-1 ring-white/30 backdrop-blur">
                <Bot className="h-5 w-5" />
              </div>
              <div className="flex-1 min-w-0">
                <p className="truncate text-sm font-bold">{isAr ? 'اسألني' : 'Ask Me'}</p>
                <p className="flex items-center gap-1.5 text-[11px] text-white/80">
                  <span className="relative flex h-2 w-2">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-300 opacity-75" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-300" />
                  </span>
                  {isAr ? 'مساعدك في منصة Tadreesly' : 'Your Tadreesly assistant'}
                </p>
              </div>
              <button
                onClick={() => {
                  restart();
                }}
                className="grid h-8 w-8 place-items-center rounded-full text-white/80 transition hover:bg-white/15 hover:text-white"
                aria-label={isAr ? 'بدء محادثة جديدة' : 'New conversation'}
              >
                <RotateCcw className="h-4 w-4" />
              </button>
              <button onClick={closePanel} className="grid h-8 w-8 place-items-center rounded-full text-white/80 transition hover:bg-white/15 hover:text-white" aria-label={isAr ? 'إغلاق' : 'Close'}>
                <X className="h-4 w-4" />
              </button>
            </div>
          </div>

          {/* الرسائل */}
          <div ref={listRef} className="flex-1 space-y-3 overflow-y-auto bg-slate-50 px-4 py-4">
            {messages.map((m) => {
              if ('typing' in m) {
                return <TypingIndicator key={m.id} text={isAr ? 'يفكّر في منصتك...' : 'thinking...'} />;
              }

              return (
                <div key={m.id} className={m.from === 'user' ? 'flex justify-end' : 'flex justify-start'}>
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

          {/* الإدخال */}
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
        </div>
      )}

      {/* الزر العائم */}
      <div className="fixed bottom-6 start-6 z-50 flex flex-col items-start gap-2">
        {!open && !seen && (
          <button
            onClick={() => useAskMe.getState().openPanel()}
            className="assistant-pop flex items-center gap-2 rounded-2xl rounded-es-md bg-white px-4 py-2.5 text-[13px] font-bold text-slate-700 shadow-xl shadow-slate-900/10 ring-1 ring-slate-900/5 transition hover:scale-105"
          >
            <Sparkles className="h-4 w-4 text-violet-500" />
            {isAr ? 'اسألني عن منصتك ✨' : 'Ask me about your platform ✨'}
          </button>
        )}

        <button
          onClick={toggle}
          aria-label={isAr ? 'اسألني' : 'Ask Me'}
          className={`group relative grid h-14 w-14 place-items-center rounded-full bg-gradient-to-tr from-indigo-600 via-indigo-500 to-violet-600 text-white shadow-xl shadow-indigo-500/40 transition-all duration-300 hover:scale-110 hover:shadow-2xl hover:shadow-indigo-500/50 active:scale-95 ${open ? 'rotate-90' : ''}`}
        >
          {!open && (
            <span className="absolute inset-0 -z-10 animate-ping rounded-full bg-gradient-to-tr from-indigo-600 to-violet-600 opacity-25" style={{ animationDuration: '2.5s' }} />
          )}
          {open ? <X className="h-6 w-6 transition-transform duration-300 group-hover:rotate-90" /> : <Sparkles className="h-6 w-6 transition-transform duration-300 group-hover:rotate-12" />}
        </button>
      </div>
    </>
  );
}
