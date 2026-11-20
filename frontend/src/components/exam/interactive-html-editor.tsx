'use client';

import { useEffect, useMemo, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Monitor, Smartphone, Sparkles, AlertTriangle, Eye } from 'lucide-react';
import { CodeEditor, type CodeLanguage } from '@/components/exam/code-editor';
import { InteractiveHtmlQuestion } from '@/components/exam/interactive-html-question';
import { IQ_LIMITS } from '@/lib/interactive-question';
import { INTERACTIVE_EXAMPLES } from '@/lib/interactive-examples';

/** Flat draft fields the interactive editor owns (see `QuestionDraft`). */
export interface InteractiveHtmlDraft {
  interactive_html: string;
  interactive_css: string;
  interactive_js: string;
  interactive_height: string;
  interactive_auto_capture: boolean;
  grading_mode: 'auto' | 'manual';
  grading_rule: string;
  grading_key: string;
  grading_tolerance: string;
}

const RULES: { id: string; ar: string; en: string; keyHintAr: string; keyHintEn: string }[] = [
  { id: 'single_choice', ar: 'قيمة واحدة', en: 'Single value', keyHintAr: 'مثال: A أو 3', keyHintEn: 'e.g. A or 3' },
  { id: 'numeric', ar: 'رقم مع هامش', en: 'Numeric + tolerance', keyHintAr: 'مثال: 3.14', keyHintEn: 'e.g. 3.14' },
  { id: 'exact_set', ar: 'مجموعة بدون ترتيب', en: 'Set (any order)', keyHintAr: 'مثال: ["x","y","z"]', keyHintEn: 'e.g. ["x","y","z"]' },
  { id: 'ordered', ar: 'قائمة بترتيب', en: 'Ordered list', keyHintAr: 'مثال: ["1","2","3"]', keyHintEn: 'e.g. ["1","2","3"]' },
  { id: 'json_equals', ar: 'JSON مطابق', en: 'Exact JSON', keyHintAr: 'مثال: {"x":2,"y":5}', keyHintEn: 'e.g. {"x":2,"y":5}' },
  { id: 'contains_all', ar: 'يحتوي كل العناصر', en: 'Contains all', keyHintAr: 'مثال: ["green","blue"]', keyHintEn: 'e.g. ["green","blue"]' },
];

/** Debounce a value so the live preview is not remounted on every keystroke. */
function useDebounced<T>(value: T, delay = 700): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delay);
    return () => window.clearTimeout(timer);
  }, [value, delay]);
  return debounced;
}

function renderTabsFor(): { id: CodeLanguage; ar: string; en: string }[] {
  return [
    { id: 'html', ar: 'HTML', en: 'HTML' },
    { id: 'css', ar: 'CSS', en: 'CSS' },
    { id: 'js', ar: 'JavaScript', en: 'JavaScript' },
  ];
}

/**
 * Teacher authoring UI for `interactive_html` questions: three code fields with
 * syntax highlighting, an isolated live preview (desktop/phone), grading rule
 * configuration, and ready-to-use examples.
 */
export function InteractiveHtmlEditor({ value, onChange }: {
  value: InteractiveHtmlDraft;
  onChange: (patch: Partial<InteractiveHtmlDraft>) => void;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [tab, setTab] = useState<CodeLanguage>('html');
  const [previewSize, setPreviewSize] = useState<'desktop' | 'phone'>('desktop');
  const [previewValue, setPreviewValue] = useState('');
  const [showPreview, setShowPreview] = useState(true);

  const codeKey = useDebounced(
    `${value.interactive_html}\u0000${value.interactive_css}\u0000${value.interactive_js}\u0000${value.interactive_height}\u0000${value.interactive_auto_capture}`,
    700,
  );

  const rule = useMemo(() => RULES.find((r) => r.id === value.grading_rule) || RULES[0], [value.grading_rule]);
  const tooLong =
    value.interactive_html.length > IQ_LIMITS.html ||
    value.interactive_css.length > IQ_LIMITS.css ||
    value.interactive_js.length > IQ_LIMITS.js;

  const loadExample = (index: number) => {
    const ex = INTERACTIVE_EXAMPLES[index];
    onChange({
      interactive_html: ex.html,
      interactive_css: ex.css,
      interactive_js: ex.js,
      interactive_height: String(ex.height),
      interactive_auto_capture: ex.autoCapture,
      grading_mode: ex.gradingMode,
      grading_rule: ex.gradingRule,
      grading_key: ex.gradingKey,
      grading_tolerance: ex.tolerance,
    });
  };

  const editorProps: Record<CodeLanguage, { val: string; key: keyof InteractiveHtmlDraft; max: number; ph: string }> = {
    html: { val: value.interactive_html, key: 'interactive_html', max: IQ_LIMITS.html, ph: t('الصق وسم HTML هنا…', 'Paste your HTML here…') },
    css: { val: value.interactive_css, key: 'interactive_css', max: IQ_LIMITS.css, ph: t('أضف تنسيقات CSS…', 'Add CSS…') },
    js: { val: value.interactive_js, key: 'interactive_js', max: IQ_LIMITS.js, ph: t('اكتب JavaScript… استخدم FahimniQuestion.setAnswer(value)', 'Write JavaScript… use FahimniQuestion.setAnswer(value)') },
  };
  const active = editorProps[tab];

  return (
    <div className="space-y-4">
      {/* Examples */}
      <div className="rounded-xl border border-teal-100 bg-teal-50/50 p-3 space-y-2">
        <p className="flex items-center gap-1.5 text-xs font-bold text-teal-700">
          <Sparkles className="h-3.5 w-3.5" />
          {t('أمثلة جاهزة (اضغط للتحميل)', 'Ready-made examples (click to load)')}
        </p>
        <div className="grid gap-2">
          {INTERACTIVE_EXAMPLES.map((ex, i) => (
            <button
              key={ex.id}
              type="button"
              onClick={() => loadExample(i)}
              className="text-start rounded-lg border border-teal-200 bg-white px-3 py-2 text-xs text-teal-800 hover:bg-teal-50 transition"
            >
              {locale === 'ar' ? ex.titleAr : ex.titleEn}
            </button>
          ))}
        </div>
      </div>

      {/* Code editors */}
      <div className="space-y-2">
        <div className="flex items-center justify-between">
          <Label>{t('كود السؤال', 'Question code')}</Label>
          <div className="flex gap-1.5">
            {renderTabsFor().map((tb) => (
              <button
                key={tb.id}
                type="button"
                onClick={() => setTab(tb.id)}
                className={`px-3 py-1 rounded-full text-xs font-medium ${tab === tb.id ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-500'}`}
              >
                {locale === 'ar' ? tb.ar : tb.en}
              </button>
            ))}
          </div>
        </div>
        <CodeEditor
          value={active.val}
          onChange={(v) => onChange({ [active.key]: v } as Partial<InteractiveHtmlDraft>)}
          language={tab}
          maxLength={active.max}
          placeholder={active.ph}
          minHeight={180}
        />
        <p className="text-[11px] text-gray-400">
          {t(
            'لا يعمل وسم <script> داخل HTML — ضع كل الأكواد في تبويب JavaScript. استخدم FahimniQuestion.setAnswer() لإرسال الإجابة.',
            '<script> tags inside the HTML never run — put all logic in the JavaScript tab. Use FahimniQuestion.setAnswer() to report the answer.',
          )}
        </p>
      </div>

      {/* Behaviour */}
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label>{t('ارتفاع الإطار (بكسل)', 'Frame height (px)')}</Label>
          <Input
            type="number"
            min={IQ_LIMITS.heightMin}
            max={IQ_LIMITS.heightMax}
            value={value.interactive_height}
            onChange={(e) => onChange({ interactive_height: e.target.value })}
            className="h-10"
          />
        </div>
        <div className="flex items-end">
          <div className="flex w-full items-center gap-2 p-3 rounded-xl border">
            <Switch
              checked={value.interactive_auto_capture}
              onCheckedChange={(v) => onChange({ interactive_auto_capture: v })}
              label={t('التقاط حقول النموذج تلقائياً', 'Auto-capture form fields')}
            />
          </div>
        </div>
      </div>

      {/* Grading */}
      <div className="space-y-2 rounded-xl border p-3">
        <Label>{t('طريقة التصحيح', 'Grading method')}</Label>
        <div className="flex gap-2">
          {(['auto', 'manual'] as const).map((mode) => (
            <button
              key={mode}
              type="button"
              onClick={() => onChange({ grading_mode: mode })}
              className={`flex-1 rounded-xl border-2 px-3 py-2 text-sm font-bold transition ${value.grading_mode === mode ? 'border-teal-500 bg-teal-50 text-teal-700' : 'border-gray-200 text-gray-500 hover:border-teal-300'}`}
            >
              {mode === 'auto' ? t('تلقائي (مفتاح مخفي)', 'Auto (hidden key)') : t('مراجعة يدوية', 'Manual review')}
            </button>
          ))}
        </div>

        {value.grading_mode === 'auto' ? (
          <div className="space-y-3 pt-1">
            <div className="space-y-2">
              <Label>{t('قاعدة المقارنة', 'Comparison rule')}</Label>
              <select
                value={value.grading_rule}
                onChange={(e) => onChange({ grading_rule: e.target.value })}
                className="h-10 border border-gray-200 rounded-lg px-3 w-full text-sm bg-white"
              >
                {RULES.map((r) => (
                  <option key={r.id} value={r.id}>{locale === 'ar' ? r.ar : r.en}</option>
                ))}
              </select>
            </div>
            <div className="space-y-2">
              <Label>{t('مفتاح الإجابة الصحيحة (لا يصل للطالب أبداً)', 'Correct answer key (never sent to students)')}</Label>
              <Input
                value={value.grading_key}
                onChange={(e) => onChange({ grading_key: e.target.value })}
                className="h-10 font-mono"
                dir="ltr"
                placeholder={locale === 'ar' ? rule.keyHintAr : rule.keyHintEn}
              />
              <p className="text-[11px] text-gray-400">{locale === 'ar' ? rule.keyHintAr : rule.keyHintEn}</p>
            </div>
            {value.grading_rule === 'numeric' && (
              <div className="space-y-2 max-w-[180px]">
                <Label>{t('هامش التسامح', 'Tolerance')}</Label>
                <Input
                  type="number"
                  step="any"
                  min={0}
                  value={value.grading_tolerance}
                  onChange={(e) => onChange({ grading_tolerance: e.target.value })}
                  className="h-10"
                />
              </div>
            )}
          </div>
        ) : (
          <p className="text-[11px] text-amber-600 flex items-start gap-1.5 pt-1">
            <AlertTriangle className="h-3.5 w-3.5 mt-0.5 shrink-0" />
            {t('تُحفظ إجابات الطلاب وتُدرج في قائمة المراجعة، ولا تُحتسب صحيحة إلا بعد تصحيحك.', 'Student answers are saved and queued for review — never auto-marked correct.')}
          </p>
        )}
      </div>

      {/* Live preview */}
      <div className="space-y-2 rounded-xl border p-3">
        <div className="flex items-center justify-between flex-wrap gap-2">
          <Label className="flex items-center gap-1.5"><Eye className="h-3.5 w-3.5" />{t('معاينة حية', 'Live preview')}</Label>
          <div className="flex items-center gap-2">
            {tooLong && (
              <span className="flex items-center gap-1 text-[11px] text-amber-600 font-medium">
                <AlertTriangle className="h-3.5 w-3.5" />{t('الكود يتجاوز الحد', 'Code exceeds limit')}
              </span>
            )}
            <div className="flex gap-1">
              <button type="button" onClick={() => setPreviewSize('desktop')} aria-label={t('سطح المكتب', 'Desktop')} className={`h-8 w-8 rounded-lg flex items-center justify-center ${previewSize === 'desktop' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-500'}`}><Monitor className="h-4 w-4" /></button>
              <button type="button" onClick={() => setPreviewSize('phone')} aria-label={t('هاتف', 'Phone')} className={`h-8 w-8 rounded-lg flex items-center justify-center ${previewSize === 'phone' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-500'}`}><Smartphone className="h-4 w-4" /></button>
            </div>
            <Button type="button" variant="outline" size="sm" onClick={() => setShowPreview((s) => !s)} className="rounded-full">
              {showPreview ? t('إخفاء', 'Hide') : t('إظهار', 'Show')}
            </Button>
          </div>
        </div>
        {showPreview && (
          <>
            <div className={`mx-auto transition-all ${previewSize === 'phone' ? 'w-[375px] max-w-full' : 'w-full'}`}>
              <InteractiveHtmlQuestion
                key={codeKey}
                questionId="preview"
                html={value.interactive_html}
                css={value.interactive_css}
                js={value.interactive_js}
                height={Number(value.interactive_height) || IQ_LIMITS.heightDefault}
                autoCapture={value.interactive_auto_capture}
                value={previewValue}
                onChange={setPreviewValue}
              />
            </div>
            <p className="text-[11px] text-gray-400">
              {t('القيمة التي يرسلها الكود الآن', 'Value the code reports now')}:{' '}
              <code className="rounded bg-gray-100 px-1.5 py-0.5 font-mono" dir="ltr">{previewValue || t('— لا شيء —', '— none —')}</code>
            </p>
          </>
        )}
      </div>
    </div>
  );
}
