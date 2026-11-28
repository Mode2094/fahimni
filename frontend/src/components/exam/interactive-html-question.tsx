'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { RotateCcw } from 'lucide-react';
import { SANDBOX_PATH } from '@/lib/csp';
import {
  IQ_CHANNEL,
  answerToSubmitValue,
  buildInitMessage,
  buildSandboxUrl,
  clampHeight,
  createMountToken,
  decideIqChildMessage,
  sanitizeAnswer,
} from '@/lib/interactive-question';

/** Convert the stored submit value back into a JS value for the sandbox. */
function parseStoredValue(value: string | undefined): unknown {
  if (value === undefined || value === '') return null;
  const trimmed = value.trim();
  if (trimmed.startsWith('{') || trimmed.startsWith('[') || trimmed.startsWith('"')) {
    try {
      return JSON.parse(trimmed);
    } catch {
      return value;
    }
  }
  return value;
}

/**
 * Renders a teacher-authored interactive question inside an isolated sandbox
 * iframe and relays the student's answer back through a validated postMessage
 * protocol.
 *
 * The frame is bound to this mount by a random token carried in the iframe URL:
 * the harness echoes it in its `ready` handshake, so a frame that self-navigated
 * to another document (which cannot know the token) is refused. The host never
 * re-sends `init` or the student's answer once the frame leaves our sandbox
 * document, and it only ever configures the frame once per mount.
 */
export function InteractiveHtmlQuestion({
  questionId,
  html,
  css,
  js,
  height,
  autoCapture = true,
  value = '',
  onChange,
  onDone,
  locked = false,
}: {
  questionId: string;
  html: string;
  css?: string;
  js?: string;
  height?: number;
  autoCapture?: boolean;
  value?: string;
  onChange?: (v: string) => void;
  /** Fired when the exercise reports itself finished (ungraded practice). */
  onDone?: () => void;
  locked?: boolean;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const token = useMemo(() => createMountToken(), []);
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const initSentRef = useRef(false);
  const readyRef = useRef(false);
  // Set once the frame loads a document other than the one we created. After
  // that nothing is sent to it: no re-init, no restore of the saved answer.
  const navigatedRef = useRef(false);
  const loadCountRef = useRef(0);
  // The last value the sandbox itself reported. Used so a value the student
  // just produced is never echoed back as a "restore" (which would fight the
  // student's typing/caret inside the frame).
  const lastFromSandboxRef = useRef<string | null>(null);

  const [ready, setReady] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [frameHeight, setFrameHeight] = useState(clampHeight(height));
  const [fallback, setFallback] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  const sendInit = useCallback(() => {
    const frame = iframeRef.current;
    if (!frame || !frame.contentWindow) return;

    frame.contentWindow.postMessage(
      buildInitMessage(token, questionId, {
        html,
        css: css ?? '',
        js: js ?? '',
        height: clampHeight(height),
        autoCapture,
        value: parseStoredValue(value),
        locked,
      }),
      '*',
    );
  }, [token, questionId, html, css, js, height, autoCapture, locked, value]);

  // Reset per-frame state whenever the frame is remounted (new element, new
  // WindowProxy, fresh mount token handshake).
  useEffect(() => {
    initSentRef.current = false;
    readyRef.current = false;
    navigatedRef.current = false;
    loadCountRef.current = 0;
    lastFromSandboxRef.current = null;
    setReady(false);
    setError(null);
  }, [reloadKey]);

  // The only channel data enters the host through. The trust decision lives in
  // the pure `decideIqChildMessage`; this effect only applies its verb.
  useEffect(() => {
    const onMessage = (event: MessageEvent) => {
      const frame = iframeRef.current;
      if (!frame || event.source !== frame.contentWindow) return;

      const decision = decideIqChildMessage(event.data, {
        token,
        initialized: initSentRef.current,
        navigated: navigatedRef.current,
      });

      switch (decision.kind) {
        case 'send-init':
          initSentRef.current = true;
          sendInit();
          break;
        case 'ready':
          readyRef.current = true;
          setReady(true);
          setFallback(false);
          break;
        case 'answer': {
          const cleaned = sanitizeAnswer(decision.value);
          if (cleaned.ok) {
            const next = answerToSubmitValue(cleaned.value);
            // Remember what the frame produced so the parent's state update
            // does not bounce back as a `restore` and fight the student's input.
            lastFromSandboxRef.current = next;
            onChange?.(next);
          }
          break;
        }
        case 'resize':
          setFrameHeight(clampHeight(decision.height));
          break;
        case 'done':
          onDone?.();
          break;
        case 'error':
          setError(decision.message.slice(0, 300));
          break;
        default:
          break;
      }
    };

    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [sendInit, token, onChange, onDone]);

  // Restore the stored answer when navigating back to an answered question.
  // Skips a value the frame itself just produced, and never fires once the
  // frame has navigated away from our sandbox document.
  useEffect(() => {
    if (!readyRef.current || navigatedRef.current) return;
    if ((value ?? '') === (lastFromSandboxRef.current ?? '')) return;
    iframeRef.current?.contentWindow?.postMessage(
      { channel: IQ_CHANNEL, type: 'restore', token, payload: { value: parseStoredValue(value) } },
      '*',
    );
  }, [value, token]);

  // Lock the frame once the exam has been submitted.
  useEffect(() => {
    if (!readyRef.current || navigatedRef.current) return;
    iframeRef.current?.contentWindow?.postMessage(
      { channel: IQ_CHANNEL, type: 'lock', token, payload: { locked } },
      '*',
    );
  }, [locked, token, ready]);

  // If the frame never becomes ready (blocked/misconfigured), do not dead-end:
  // the student can still type a plain-text answer.
  useEffect(() => {
    const timer = window.setTimeout(() => {
      if (!readyRef.current) setFallback(true);
    }, 5000);
    return () => window.clearTimeout(timer);
  }, [reloadKey]);

  const retry = () => {
    setFallback(false);
    setReloadKey((k) => k + 1);
  };

  if (fallback) {
    return (
      <div className="space-y-2">
        <p className="text-xs text-amber-600">
          {t(
            'تعذّر تحميل السؤال التفاعلي — يمكنك كتابة إجابتك نصياً.',
            'The interactive question could not load — you can type your answer as text.',
          )}
        </p>
        <textarea
          value={typeof value === 'string' ? value : ''}
          onChange={(e) => onChange?.(e.target.value)}
          disabled={locked}
          className="w-full min-h-[120px] rounded-xl border p-4 text-sm focus:outline-none focus:ring-2 focus:ring-teal-600"
          dir="auto"
          placeholder={t('اكتب إجابتك…', 'Write your answer…')}
        />
        <Button variant="outline" size="sm" onClick={retry} className="gap-1.5">
          <RotateCcw className="h-3.5 w-3.5" />
          {t('إعادة المحاولة', 'Retry')}
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <iframe
        key={reloadKey}
        ref={iframeRef}
        title={t('سؤال تفاعلي', 'Interactive question')}
        src={buildSandboxUrl(SANDBOX_PATH, token)}
        // No allow-same-origin: the frame gets an opaque origin and can never
        // touch app cookies, storage, or the host DOM.
        sandbox="allow-scripts"
        referrerPolicy="no-referrer"
        // Marks this frame so the exam's anti-cheat can tell "focus moved into
        // the interactive question" (expected) from "student left the exam".
        data-fahimni-interactive="true"
        onLoad={() => {
          // We never init from here. A second load means the frame navigated
          // away from our sandbox document, so it becomes off-limits for good.
          loadCountRef.current += 1;
          if (loadCountRef.current > 1) {
            navigatedRef.current = true;
            readyRef.current = false;
            setReady(false);
          }
        }}
        className="w-full rounded-xl border bg-white"
        style={{ height: frameHeight }}
      />
      {!ready && (
        <p className="text-xs text-gray-400">{t('جارٍ تحميل السؤال التفاعلي…', 'Loading interactive question…')}</p>
      )}
      {error && (
        <p className="text-xs text-amber-600">
          {t('تنبيه من كود السؤال', 'Note from the question code')}: {error}
        </p>
      )}
    </div>
  );
}
