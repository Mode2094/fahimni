'use client';

import { useRef, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ImagePlus, X, CheckCircle2, Plus, ArrowUp, ArrowDown, FunctionSquare, Atom, Trash2, Loader2 } from 'lucide-react';
import { Latex } from '@/components/exam/latex';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export type QuestionDraft = {
  question_text: string;
  question_type: string;
  points: string;
  exam_id: string;
  section_id: string;
  options: { option_text: string; is_correct: boolean }[];
  image_url: string;
  pairs: { left: string; right: string }[];
  items: string[];
  answers: string[];
  keywords: string;
};

const QUESTION_TYPES = [
  { id: 'multiple_choice', ar: 'اختيار متعدد', en: 'Multiple choice', icon: '☑️' },
  { id: 'true_false', ar: 'صح / خطأ', en: 'True / False', icon: '✅' },
  { id: 'drag_drop', ar: 'سحب وإفلات', en: 'Drag & drop', icon: '🧲' },
  { id: 'matching', ar: 'مطابقة', en: 'Matching', icon: '🔗' },
  { id: 'ordering', ar: 'ترتيب', en: 'Ordering', icon: '🔢' },
  { id: 'fill_blank', ar: 'املأ الفراغ', en: 'Fill the blank', icon: '✍️' },
  { id: 'writing', ar: 'إجابة كتابية', en: 'Written answer', icon: '📝' },
  { id: 'file_upload', ar: 'رفع ملف', en: 'File upload', icon: '📎' },
];

const MATH_SYMBOLS = ['α', 'β', 'γ', 'θ', 'λ', 'π', '√', '∑', '∫', '∞', '≈', '≠', '≤', '≥', '×', '÷', '±', '→', '←', '⇒', '⇔', 'Δ', '∂', '°', '′', '²', '³'];
const CHEM_SYMBOLS = ['H₂O', 'CO₂', 'NaCl', 'O₂', 'N₂', 'H₂', '→', '⇌', '↑', '↓', 'Δ', '°C', '(aq)', '(s)', '(l)', '(g)', '⁻', '⁺', '₂', '₃', '₄', '+', 'e⁻'];

export function QuestionBuilder({ value, onChange, sections, exams }: {
  value: QuestionDraft;
  onChange: (d: QuestionDraft) => void;
  sections: { id: string; title: string }[];
  exams: { id: string; title: string }[];
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [symTab, setSymTab] = useState<'math' | 'chem'>('math');
  const [uploading, setUploading] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const textRef = useRef<HTMLTextAreaElement>(null);

  const set = (patch: Partial<QuestionDraft>) => onChange({ ...value, ...patch });

  const insertSymbol = (sym: string) => {
    const el = textRef.current;
    const text = value.question_text;
    if (!el) { set({ question_text: text + sym }); return; }
    const start = el.selectionStart ?? text.length;
    const end = el.selectionEnd ?? text.length;
    const next = text.slice(0, start) + sym + text.slice(end);
    set({ question_text: next });
    requestAnimationFrame(() => { el.focus(); el.setSelectionRange(start + sym.length, start + sym.length); });
  };

  const uploadImage = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      const res = await fetch(`${API}/upload`, { method: 'POST', headers: { 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' }, body: fd });
      if (!res.ok) throw new Error();
      const d = await res.json();
      set({ image_url: d.url });
    } catch { /* */ }
    setUploading(false);
    if (fileRef.current) fileRef.current.value = '';
  };

  const symPalette = (
    <div className="rounded-xl border p-3 space-y-2 bg-white">
      <div className="flex items-center justify-between">
        <div className="flex gap-1.5">
          <button onClick={() => setSymTab('math')} className={`px-3 py-1 rounded-full text-xs font-medium ${symTab === 'math' ? 'bg-teal-600 text-white' : 'bg-gray-100 text-gray-500'}`}><FunctionSquare className="h-3 w-3 inline me-1" />{t('رموز رياضية', 'Math')}</button>
          <button onClick={() => setSymTab('chem')} className={`px-3 py-1 rounded-full text-xs font-medium ${symTab === 'chem' ? 'bg-teal-600 text-white' : 'bg-gray-100 text-gray-500'}`}><Atom className="h-3 w-3 inline me-1" />{t('رموز كيميائية', 'Chemistry')}</button>
        </div>
        <span className="text-[10px] text-gray-400">{t('اضغط للإدراج في نص السؤال', 'Click to insert')}</span>
      </div>
      <div className="flex flex-wrap gap-1.5">
        {(symTab === 'math' ? MATH_SYMBOLS : CHEM_SYMBOLS).map((s) => (
          <button key={s} onClick={() => insertSymbol(s)} className="min-w-8 h-8 px-1.5 rounded-lg border text-sm hover:bg-teal-50 hover:border-teal-300 transition-all" dir="ltr">{s}</button>
        ))}
      </div>
    </div>
  );

  const latexInsert = (wrap: 'inline' | 'display') => {
    const open = wrap === 'inline' ? '\\(' : '$$';
    const close = wrap === 'inline' ? '\\)' : '$$';
    const el = textRef.current;
    const text = value.question_text;
    if (!el) { set({ question_text: text + open + close }); return; }
    const start = el.selectionStart ?? text.length;
    const end = el.selectionEnd ?? text.length;
    const sel = text.slice(start, end) || 'x^2 + y^2';
    set({ question_text: text.slice(0, start) + open + sel + close + text.slice(end) });
  };

  return (
    <div className="space-y-4">
      {/* Type selector */}
      <div className="space-y-2">
        <Label>{t('نمط السؤال', 'Question type')}</Label>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
          {QUESTION_TYPES.map((qt) => (
            <button key={qt.id} onClick={() => set({ question_type: qt.id, options: qt.id === 'multiple_choice' ? [{ option_text: '', is_correct: true }, { option_text: '', is_correct: false }] : qt.id === 'true_false' ? [{ option_text: 'True', is_correct: true }, { option_text: 'False', is_correct: false }] : value.options, pairs: qt.id === 'drag_drop' || qt.id === 'matching' ? [{ left: '', right: '' }, { left: '', right: '' }] : value.pairs, items: qt.id === 'ordering' ? ['', '', ''] : value.items, answers: qt.id === 'fill_blank' ? [''] : value.answers })}
              className={`p-2.5 rounded-xl border-2 text-center transition-all ${value.question_type === qt.id ? 'border-teal-500 bg-teal-50' : 'border-gray-100 hover:border-gray-300'}`}>
              <div className="text-xl mb-0.5">{qt.icon}</div>
              <p className="text-[11px] font-bold">{locale === 'ar' ? qt.ar : qt.en}</p>
            </button>
          ))}
        </div>
      </div>

      {/* Question text + symbols */}
      <div className="space-y-2">
        <Label>{t('نص السؤال', 'Question text')} * <span className="text-[10px] text-gray-400">({t('يدعم الصيغ', 'supports')}: \(x^2\) $$E=mc^2$$)</span></Label>
        <Textarea ref={textRef} value={value.question_text} onChange={(e) => set({ question_text: e.target.value })} className="min-h-[80px] font-mono" dir="auto" />
        {symPalette}
        <div className="flex gap-2">
          <Button variant="outline" size="sm" className="gap-1.5 rounded-full text-xs" onClick={() => latexInsert('inline')}><FunctionSquare className="h-3.5 w-3.5" />{t('معادلة داخل النص', 'Inline formula')} \(x^2\)</Button>
          <Button variant="outline" size="sm" className="gap-1.5 rounded-full text-xs" onClick={() => latexInsert('display')}><FunctionSquare className="h-3.5 w-3.5" />{t('معادلة منفصلة', 'Display formula')} $$…$$</Button>
        </div>
        {/\\\(|\\\[|\$\$/.test(value.question_text) && (
          <div className="p-3 rounded-xl bg-slate-900">
            <p className="text-[10px] text-white/40 mb-1">{t('معاينة الصيغة', 'Formula preview')}</p>
            <Latex text={value.question_text} className="text-white" />
          </div>
        )}
      </div>

      {/* Image */}
      <div className="space-y-2">
        <Label>{t('صورة للسؤال (اختياري)', 'Question image (optional)')}</Label>
        <div className="flex items-center gap-3">
          {value.image_url ? (
            <div className="relative">
              <img src={value.image_url} className="h-20 w-32 rounded-xl border object-cover" alt="" />
              <button onClick={() => set({ image_url: '' })} className="absolute -top-2 -end-2 flex h-6 w-6 items-center justify-center rounded-full bg-red-500 text-white shadow"><X className="h-3.5 w-3.5" /></button>
            </div>
          ) : <div className="flex h-20 w-32 items-center justify-center rounded-xl bg-gray-100 border-2 border-dashed"><ImagePlus className="h-6 w-6 text-gray-300" /></div>}
          <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={uploadImage} />
          <Button variant="outline" size="sm" onClick={() => fileRef.current?.click()} disabled={uploading} className="gap-1.5 rounded-full">{uploading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <ImagePlus className="h-3.5 w-3.5" />}{t('رفع صورة', 'Upload image')}</Button>
        </div>
      </div>

      {/* Type-specific editors */}
      {value.question_type === 'multiple_choice' && (
        <div className="space-y-2">
          <Label>{t('الخيارات — اضغط الدائرة للإجابة الصحيحة', 'Options — tap the circle for the correct one')}</Label>
          {value.options.map((o, i) => (
            <div key={i} className="flex items-center gap-2">
              <button onClick={() => set({ options: value.options.map((x, j) => ({ ...x, is_correct: j === i })) })}
                className={`h-5 w-5 rounded-full border-2 flex items-center justify-center shrink-0 ${o.is_correct ? 'border-green-500 bg-green-500' : 'border-gray-300'}`}>
                {o.is_correct && <CheckCircle2 className="h-3.5 w-3.5 text-white" />}
              </button>
              <Input value={o.option_text} onChange={(e) => set({ options: value.options.map((x, j) => j === i ? { ...x, option_text: e.target.value } : x) })} className="h-10" placeholder={`${t('خيار', 'Option')} ${i + 1}`} />
              {value.options.length > 2 && <button onClick={() => set({ options: value.options.filter((_, j) => j !== i) })} className="text-red-400"><Trash2 className="h-4 w-4" /></button>}
            </div>
          ))}
          {value.options.length < 6 && <Button variant="outline" size="sm" className="gap-1 rounded-full" onClick={() => set({ options: [...value.options, { option_text: '', is_correct: false }] })}><Plus className="h-3.5 w-3.5" />{t('إضافة خيار', 'Add option')}</Button>}
        </div>
      )}

      {value.question_type === 'true_false' && (
        <div className="space-y-2">
          <Label>{t('الإجابة الصحيحة — اختر «صح» أو «خطأ»', 'Correct answer — pick True or False')}</Label>
          <div className="flex gap-2">
            {['True', 'False'].map((opt) => {
              const isCorrect = (value.options || []).find((o) => o.option_text === opt)?.is_correct === true;
              return (
                <button key={opt} onClick={() => set({ options: [
                  { option_text: 'True', is_correct: opt === 'True' },
                  { option_text: 'False', is_correct: opt === 'False' },
                ] })}
                  className={`flex-1 rounded-xl border-2 px-4 py-3 text-sm font-bold transition ${isCorrect ? 'border-green-500 bg-green-50 text-green-700' : 'border-gray-200 text-gray-500 hover:border-green-300'}`}>
                  {opt === 'True' ? (locale === 'ar' ? 'صح ✓' : 'True ✓') : (locale === 'ar' ? 'خطأ ✗' : 'False ✗')}
                  {isCorrect && <span className="block text-[10px] font-normal text-green-600">{t('الإجابة الصحيحة', 'Correct')}</span>}
                </button>
              );
            })}
          </div>
        </div>
      )}

      {(value.question_type === 'drag_drop' || value.question_type === 'matching') && (
        <div className="space-y-2">
          <Label>{value.question_type === 'drag_drop' ? t('أزواج السحب والإفلات — العمود الأيسر يُسحب للعمود الأيمن', 'Drag pairs — left column items are dragged to right targets') : t('أزواج المطابقة — اربط اليسار باليمين', 'Matching pairs — link left to right')}</Label>
          {value.pairs.map((p, i) => (
            <div key={i} className="flex items-center gap-2">
              <span className="text-xs text-gray-400 w-5 shrink-0">{i + 1}</span>
              <Input value={p.left} onChange={(e) => set({ pairs: value.pairs.map((x, j) => j === i ? { ...x, left: e.target.value } : x) })} className="h-10" placeholder={t('العنصر (يسار)', 'Item (left)')} />
              <span className="text-teal-400">⇄</span>
              <Input value={p.right} onChange={(e) => set({ pairs: value.pairs.map((x, j) => j === i ? { ...x, right: e.target.value } : x) })} className="h-10" placeholder={t('الهدف (يمين)', 'Target (right)')} />
              {value.pairs.length > 2 && <button onClick={() => set({ pairs: value.pairs.filter((_, j) => j !== i) })} className="text-red-400"><Trash2 className="h-4 w-4" /></button>}
            </div>
          ))}
          <Button variant="outline" size="sm" className="gap-1 rounded-full" onClick={() => set({ pairs: [...value.pairs, { left: '', right: '' }] })}><Plus className="h-3.5 w-3.5" />{t('إضافة زوج', 'Add pair')}</Button>
        </div>
      )}

      {value.question_type === 'ordering' && (
        <div className="space-y-2">
          <Label>{t('الترتيب الصحيح من الأعلى للأسفل', 'Correct order — top to bottom')}</Label>
          {value.items.map((it, i) => (
            <div key={i} className="flex items-center gap-2">
              <Input value={it} onChange={(e) => set({ items: value.items.map((x, j) => j === i ? e.target.value : x) })} className="h-10" placeholder={`${t('عنصر', 'Item')} ${i + 1}`} />
              <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => { const a = [...value.items]; if (i > 0) { [a[i], a[i - 1]] = [a[i - 1], a[i]]; set({ items: a }); } }} disabled={i === 0}><ArrowUp className="h-3.5 w-3.5" /></Button>
              <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => { const a = [...value.items]; if (i < a.length - 1) { [a[i], a[i + 1]] = [a[i + 1], a[i]]; set({ items: a }); } }} disabled={i === value.items.length - 1}><ArrowDown className="h-3.5 w-3.5" /></Button>
            </div>
          ))}
        </div>
      )}

      {value.question_type === 'fill_blank' && (
        <div className="space-y-2">
          <Label>{t('الإجابات الصحيحة للفراغات — بالترتيب', 'Correct answers for the blanks — in order')}</Label>
          {value.answers.map((a, i) => (
            <div key={i} className="flex items-center gap-2">
              <Input value={a} onChange={(e) => set({ answers: value.answers.map((x, j) => j === i ? e.target.value : x) })} className="h-10" placeholder={`${t('فراغ', 'Blank')} ${i + 1}`} />
              {value.answers.length > 1 && <button onClick={() => set({ answers: value.answers.filter((_, j) => j !== i) })} className="text-red-400"><Trash2 className="h-4 w-4" /></button>}
            </div>
          ))}
          <Button variant="outline" size="sm" className="gap-1 rounded-full" onClick={() => set({ answers: [...value.answers, ''] })}><Plus className="h-3.5 w-3.5" />{t('إضافة فراغ', 'Add blank')}</Button>
        </div>
      )}

      {value.question_type === 'file_upload' && (
        <div className="p-4 rounded-xl bg-teal-50 border border-teal-100 text-sm text-teal-700">
          📎 {t('الطالب سيرفع ملف إجابته (صورة/PDF/ملف) — تُصحح تلقائياً عند رفع أي ملف', 'Student uploads their answer file (image/PDF/file) — graded automatically when a file is uploaded')}
        </div>
      )}

      {value.question_type === 'writing' && (
        <div className="space-y-2">
          <Label>{t('كلمات مفتاحية للتصحيح التلقائي (مفصولة بفاصلة)', 'Keywords for auto-grading (comma separated)')}</Label>
          <Input value={value.keywords} onChange={(e) => set({ keywords: e.target.value })} className="h-10" dir="ltr" placeholder="mitochondria, ATP, الطاقة" />
          <p className="text-[11px] text-gray-400">{t('إذا كتب الطالب أي كلمة منها تُصحح إجابته صحيحة', 'If the student writes any of them, the answer is graded correct')}</p>
        </div>
      )}

      {/* Attachments */}
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label>{t('ربط بقسم', 'Attach to section')}</Label>
          <select value={value.section_id} onChange={(e) => set({ section_id: e.target.value })} className="h-10 border border-gray-200 rounded-lg px-3 w-full text-sm bg-white">
            <option value="">{t('بدون قسم', 'No section')}</option>
            {sections.map((s) => <option key={s.id} value={s.id}>{s.title}</option>)}
          </select>
        </div>
        <div className="space-y-2">
          <Label>{t('ربط باختبار', 'Attach to exam')}</Label>
          <select value={value.exam_id} onChange={(e) => set({ exam_id: e.target.value })} className="h-10 border border-gray-200 rounded-lg px-3 w-full text-sm bg-white">
            <option value="">{t('بدون ربط', 'No exam')}</option>
            {exams.map((e) => <option key={e.id} value={e.id}>{e.title}</option>)}
          </select>
        </div>
      </div>

      <div className="space-y-2 max-w-[140px]">
        <Label>{t('النقاط', 'Points')}</Label>
        <Input type="number" value={value.points} onChange={(e) => set({ points: e.target.value })} className="h-10" />
      </div>
    </div>
  );
}
