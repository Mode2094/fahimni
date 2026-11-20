'use client';

import { useRef } from 'react';
import { useLocale } from '@/store/locale';
import { AlertTriangle } from 'lucide-react';

export type CodeLanguage = 'html' | 'css' | 'js';

const CLASSES: Record<string, string> = {
  'tk-comment': 'text-slate-500 italic',
  'tk-string': 'text-emerald-300',
  'tk-number': 'text-amber-300',
  'tk-keyword': 'text-violet-300 font-semibold',
  'tk-tag': 'text-rose-300',
  'tk-atrule': 'text-violet-300',
  'tk-prop': 'text-sky-300',
};

// One master alternation per language; class[i] maps to capture group i+1.
const PATTERNS: Record<CodeLanguage, { re: RegExp; classes: string[] }> = {
  js: {
    re: /(\/\/[^\n]*|\/\*[\s\S]*?\*\/)|('(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|`(?:\\.|[^`\\])*`)|\b(\d+(?:\.\d+)?)\b|\b(function|return|if|else|for|while|var|let|const|new|this|true|false|null|undefined|typeof|instanceof|in|of|break|continue|switch|case|default|try|catch|finally|throw|class|extends|await|async|do|delete|void|yield)\b/g,
    classes: ['tk-comment', 'tk-string', 'tk-number', 'tk-keyword'],
  },
  html: {
    re: /(<!--[\s\S]*?-->)|(<\/?[a-zA-Z][^>]*?>)/g,
    classes: ['tk-comment', 'tk-tag'],
  },
  css: {
    re: /(\/\*[\s\S]*?\*\/)|('(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*")|(@[a-zA-Z-]+)|([a-zA-Z-]+(?=\s*:))|\b(\d+(?:\.\d+)?(?:px|em|rem|%|vh|vw|s|ms|deg|fr)?)\b/g,
    classes: ['tk-comment', 'tk-string', 'tk-atrule', 'tk-prop', 'tk-number'],
  },
};

/** Highlighting is skipped above this size to keep the editor responsive. */
const HIGHLIGHT_LIMIT = 20_000;

type Token = { text: string; cls: string };

function tokenize(code: string, language: CodeLanguage): Token[] {
  if (code.length > HIGHLIGHT_LIMIT) {
    return [{ text: code, cls: '' }];
  }

  const { re, classes } = PATTERNS[language];
  const tokens: Token[] = [];
  let last = 0;
  re.lastIndex = 0;

  let m: RegExpExecArray | null;
  while ((m = re.exec(code)) !== null) {
    if (m.index > last) tokens.push({ text: code.slice(last, m.index), cls: '' });

    let cls = '';
    for (let i = 1; i < m.length; i++) {
      if (m[i] !== undefined) {
        cls = classes[i - 1] ?? '';
        break;
      }
    }
    tokens.push({ text: m[0], cls });
    last = m.index + m[0].length;
    if (m[0].length === 0) re.lastIndex++;
  }

  if (last < code.length) tokens.push({ text: code.slice(last), cls: '' });

  return tokens;
}

/**
 * A dependency-free code editor with syntax highlighting and inline error
 * display. Highlighting is rendered as escaped React spans (never raw HTML), so
 * teacher input can never inject markup into the editor page.
 */
export function CodeEditor({
  value,
  onChange,
  language,
  maxLength,
  placeholder,
  minHeight = 150,
  dir = 'ltr',
  onPasteText,
}: {
  value: string;
  onChange: (v: string) => void;
  language: CodeLanguage;
  maxLength?: number;
  placeholder?: string;
  minHeight?: number;
  dir?: 'ltr' | 'rtl';
  /**
   * Optional paste interceptor. Return `true` to claim the paste (the parent
   * handled the text, e.g. by splitting a full document across tabs) and stop
   * the default insertion.
   */
  onPasteText?: (text: string) => boolean;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const taRef = useRef<HTMLTextAreaElement>(null);
  const preRef = useRef<HTMLPreElement>(null);

  const sync = () => {
    const ta = taRef.current;
    const pre = preRef.current;
    if (ta && pre) {
      pre.scrollTop = ta.scrollTop;
      pre.scrollLeft = ta.scrollLeft;
    }
  };

  const code = String(value ?? '');
  const tokens = tokenize(code, language);
  const tooLong = maxLength !== undefined && code.length > maxLength;
  const lines = code === '' ? 1 : code.split('\n').length;

  return (
    <div className="space-y-1.5">
      <div
        className="relative overflow-hidden rounded-xl border border-slate-800 bg-slate-950"
        style={{ minHeight }}
      >
        <pre
          ref={preRef}
          aria-hidden
          className="pointer-events-none absolute inset-0 m-0 overflow-hidden whitespace-pre-wrap break-words p-3 font-mono text-[12.5px] leading-[1.55] text-slate-100"
          dir={dir}
        >
          {tokens.map((tk, i) =>
            tk.cls ? (
              <span key={i} className={CLASSES[tk.cls]}>
                {tk.text}
              </span>
            ) : (
              <span key={i}>{tk.text}</span>
            ),
          )}
          {'\n'}
        </pre>
        <textarea
          ref={taRef}
          value={code}
          onChange={(e) => onChange(e.target.value)}
          onPaste={(e) => {
            if (!onPasteText) return;
            const text = e.clipboardData?.getData('text/plain') ?? '';
            if (text && onPasteText(text)) e.preventDefault();
          }}
          onScroll={sync}
          spellCheck={false}
          dir={dir}
          placeholder={placeholder}
          className="relative block w-full resize-none bg-transparent p-3 font-mono text-[12.5px] leading-[1.55] text-transparent caret-white outline-none placeholder:text-slate-500 selection:bg-sky-500/40"
          style={{ minHeight }}
        />
      </div>
      <div className="flex items-center justify-between text-[11px]">
        <span className="text-gray-400">
          {lines} {t('سطر', 'lines')}
        </span>
        <span className={tooLong ? 'text-amber-600 font-medium' : 'text-gray-400'}>
          {code.length}
          {maxLength !== undefined ? ` / ${maxLength}` : ''} {t('حرف', 'chars')}
        </span>
      </div>
      {tooLong && (
        <p className="flex items-center gap-1.5 text-[11px] text-amber-600">
          <AlertTriangle className="h-3.5 w-3.5" />
          {t('الكود أطول من الحد المسموح، سيُقتطع عند الحفظ.', 'The code exceeds the limit and will be rejected on save.')}
        </p>
      )}
    </div>
  );
}
