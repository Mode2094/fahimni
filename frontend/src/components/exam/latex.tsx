'use client';

import { useEffect, useRef } from 'react';

declare global {
  interface Window {
    katex?: any;
    renderMathInElement?: any;
  }
}

const KATEX_URL = 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist';

function loadKaTeX(): Promise<void> {
  return new Promise((resolve) => {
    if (window.katex && window.renderMathInElement) return resolve();
    if (!document.querySelector('link[data-katex-css]')) {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = `${KATEX_URL}/katex.min.css`;
      link.dataset.katexCss = 'true';
      document.head.appendChild(link);
    }
    const loadScript = (src: string): Promise<void> =>
      new Promise((res) => {
        if (document.querySelector(`script[data-katex-src="${src}"]`)) return res();
        const s = document.createElement('script');
        s.src = src;
        s.async = true;
        s.dataset.katexSrc = src;
        s.onload = () => res();
        document.head.appendChild(s);
      });
    Promise.all([loadScript(`${KATEX_URL}/katex.min.js`), loadScript(`${KATEX_URL}/contrib/auto-render.min.js`)]).then(() => resolve());
  });
}

export function Latex({ text, className }: { text: string; className?: string }) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    let cancelled = false;
    const tryRender = () => {
      if (window.renderMathInElement && window.katex) {
        window.renderMathInElement(el, {
          delimiters: [
            { left: '$$', right: '$$', display: true },
            { left: '\\(', right: '\\)', display: false },
            { left: '\\[', right: '\\]', display: true },
          ],
          throwOnError: false,
        });
        return true;
      }
      return false;
    };
    if (tryRender()) return;
    loadKaTeX().then(() => {
      if (!cancelled) tryRender();
    });
    return () => { cancelled = true; };
  }, [text]);

  const hasLatex = /(\\\(|\\\[|\$\$)/.test(text || '');
  if (!hasLatex) return <span className={className}>{text}</span>;

  return <div ref={ref} className={`inline-block ${className || ''}`}>{text}</div>;
}
