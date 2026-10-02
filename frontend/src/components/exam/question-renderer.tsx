'use client';

import { useState, useRef } from 'react';
import { useLocale } from '@/store/locale';
import { CheckCircle, GripVertical, ArrowUp, ArrowDown, Upload, FileText, Loader2, X } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';
import { Latex } from '@/components/exam/latex';

export type AnswerValue = string | string[] | { left: string; right: string }[];

export function QuestionRenderer({ q, value, onChange }: {
  q: any;
  value: AnswerValue;
  onChange: (v: AnswerValue) => void;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const type = q.question_type;
  const md = q.metadata || {};
  // Hoisted state: React requires a stable hook order across renders, and
  // exam questions change type as the student navigates.
  const [selected, setSelected] = useState<string | null>(null);

  const text = (v: any) => <Latex text={v} />;

  const asPairs = (v: any): { left: string; right: string }[] => {
    if (Array.isArray(v)) return v as { left: string; right: string }[];
    if (typeof v === 'string') { try { return JSON.parse(v); } catch { return []; } }
    return [];
  };

  if (type === 'multiple_choice') {
    return (
      <div className="space-y-3">
        {(q.options || []).map((opt: any, i: number) => {
          const selected = value === opt.id;
          return (
            <button key={opt.id} onClick={() => onChange(opt.id)}
              className={`w-full flex items-center gap-3 rounded-xl border p-4 text-start transition-all ${selected ? 'border-teal-500 bg-teal-50' : 'hover:bg-gray-50'}`}>
              <span className={`flex h-6 w-6 items-center justify-center rounded-full border text-xs shrink-0 ${selected ? 'bg-teal-500 text-white border-teal-500' : 'border-gray-300'}`}>{String.fromCharCode(65 + i)}</span>
              <span className="flex-1">{text(opt.option_text)}</span>
              {selected && <CheckCircle className="h-4 w-4 text-teal-600" />}
            </button>
          );
        })}
      </div>
    );
  }

  if (type === 'true_false') {
    const selectedValue = value as string;
    return (
      <div className="grid grid-cols-2 gap-3 max-w-md">
        {['true', 'false'].map((v) => (
          <button key={v} onClick={() => onChange(v)}
            className={`p-6 rounded-2xl border-2 text-center font-bold text-lg transition-all ${selectedValue === v ? 'border-green-500 bg-green-50 text-green-700' : 'border-gray-100 hover:border-gray-300'}`}>
            {v === 'true' ? (locale === 'ar' ? 'صح ✓' : 'True ✓') : (locale === 'ar' ? 'خطأ ✗' : 'False ✗')}
          </button>
        ))}
      </div>
    );
  }

  if (type === 'drag_drop' || type === 'matching') {
    const pairs = asPairs(value);
    const correct = md.pairs || [];
    const placed = (left: string) => pairs.find((p) => p.left === left);
    const occupiedRight = (right: string) => pairs.find((p) => p.right === right);

    const clickItem = (left: string) => {
      if (placed(left)) {
        onChange(pairs.filter((p) => p.left !== left));
        setSelected(null);
        return;
      }
      setSelected(selected === left ? null : left);
    };

    const clickTarget = (right: string) => {
      const occ = occupiedRight(right);
      if (occ) {
        onChange(pairs.filter((p) => p.right !== right));
        return;
      }
      if (!selected) return;
      onChange([...pairs.filter((p) => p.left !== selected), { left: selected, right }]);
      setSelected(null);
    };

    return (
      <div className="space-y-3">
        <p className="text-sm text-gray-400">{t('اضغط العنصر ثم اضغط الهدف لتثبيته — اضغط مجدداً لإلغائه', 'Tap an item, then tap its target to place it — tap again to remove')}</p>
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <p className="text-xs font-bold text-gray-400">{t('العناصر', 'Items')}</p>
            {(correct as any[]).map((pair: any, i: number) => {
              const placedThis = placed(pair.left);
              return (
                <div key={i} onClick={() => clickItem(pair.left)}
                  className={`p-3 rounded-xl border text-sm transition-all ${placedThis ? 'bg-gray-50 text-gray-300 border-gray-100' : selected === pair.left ? 'bg-teal-600 text-white border-teal-600 shadow-lg' : 'bg-teal-50 border-teal-200 cursor-pointer hover:bg-teal-100'}`}>
                  {text(pair.left)}
                </div>
              );
            })}
          </div>
          <div className="space-y-2">
            <p className="text-xs font-bold text-gray-400">{t('الأهداف', 'Targets')}</p>
            {(correct as any[]).map((pair: any, i: number) => {
              const occ = occupiedRight(pair.right);
              return (
                <div key={i} onClick={() => clickTarget(pair.right)}
                  className={`min-h-11 p-3 rounded-xl border text-sm flex items-center justify-center text-center cursor-pointer transition-all ${occ ? 'bg-green-50 border-green-200' : 'border-dashed border-gray-300 hover:border-teal-400'}`}>
                  {occ ? text(occ.left) : text(pair.right)}
                </div>
              );
            })}
          </div>
        </div>
      </div>
    );
  }

  if (type === 'ordering') {
    const items: string[] = Array.isArray(value) ? (value as string[]) : [];
    const correct = md.items || [];
    const move = (i: number, dir: number) => {
      const next = [...items];
      const j = i + dir;
      if (j < 0 || j >= next.length) return;
      [next[i], next[j]] = [next[j], next[i]];
      onChange(next);
    };
    return (
      <div className="space-y-2">
        <p className="text-sm text-gray-400">{t('رتب بالأسهم — الترتيب من الأعلى للأسفل', 'Arrange with arrows — top to bottom')}</p>
        {(items.length ? items : (correct as any[]).map((i: any) => typeof i === 'string' ? i : i.text)).map((it: string, i: number) => (
          <div key={i} className="flex items-center gap-3 p-3 rounded-xl border bg-gray-50/50">
            <GripVertical className="h-4 w-4 text-gray-300 shrink-0" />
            <span className="flex-1 text-sm">{text(it)}</span>
            <ArrowUp className="h-4 w-4 cursor-pointer text-gray-400 hover:text-teal-600" onClick={() => move(i, -1)} />
            <ArrowDown className="h-4 w-4 cursor-pointer text-gray-400 hover:text-teal-600" onClick={() => move(i, 1)} />
          </div>
        ))}
      </div>
    );
  }

  if (type === 'fill_blank') {
    const answers: string[] = Array.isArray(value) ? (value as string[]) : [];
    const blanks = md.blanks_count || 0;
    return (
      <div className="space-y-3">
        <p className="text-sm text-gray-400">{t('املأ الفراغات بالترتيب', 'Fill the blanks in order')}</p>
        {Array.from({ length: Math.max(blanks, answers.length) }).map((_, i) => (
          <input key={i} value={answers[i] || ''} onChange={(e) => { const next = [...answers]; next[i] = e.target.value; onChange(next); }}
            className="w-full h-11 rounded-xl border p-3 text-sm focus:outline-none focus:ring-2 focus:ring-teal-600" dir="auto" placeholder={`${t('فراغ', 'Blank')} ${i + 1}`} />
        ))}
      </div>
    );
  }

  if (type === 'writing') {
    return (
      <textarea value={typeof value === 'string' ? value : ''} onChange={(e) => onChange(e.target.value)}
        className="w-full min-h-[120px] rounded-xl border p-4 text-sm focus:outline-none focus:ring-2 focus:ring-teal-600" dir="auto"
        placeholder={t('اكتب إجابتك هنا...', 'Write your answer here...')} />
    );
  }

  if (type === 'file_upload') {
    return <FileUploadAnswer value={typeof value === 'string' ? value : ''} onChange={onChange} />;
  }

  return null;
}


function FileUploadAnswer({ value, onChange }: { value: string; onChange: (v: AnswerValue) => void }) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [uploading, setUploading] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  const upload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      const res = await fetch(`${API}/upload`, { method: 'POST', headers: { Accept: 'application/json' }, body: fd });
      if (!res.ok) throw new Error();
      const d = await res.json();
      onChange(d.url);
    } catch { /* */ }
    setUploading(false);
    if (fileRef.current) fileRef.current.value = '';
  };

  return (
    <div className="space-y-3">
      <p className="text-sm text-gray-400">{t('ارفع ملف إجابتك (صورة / PDF / ملف)', 'Upload your answer file (image / PDF / file)')}</p>
      {value ? (
        <div className="flex items-center gap-3 p-4 rounded-xl bg-green-50 border border-green-200">
          <FileText className="h-6 w-6 text-green-600 shrink-0" />
          <a href={value} target="_blank" className="flex-1 text-sm text-green-700 font-medium truncate" dir="ltr">{value.split('/').pop()}</a>
          <button onClick={() => onChange('')} aria-label={t('إزالة الملف', 'Remove file')} className="text-green-600 hover:text-red-500"><X className="h-4 w-4" /></button>
        </div>
      ) : (
        <button onClick={() => fileRef.current?.click()} disabled={uploading}
          className="w-full p-8 rounded-2xl border-2 border-dashed border-gray-300 hover:border-teal-400 hover:bg-teal-50/50 transition-all flex flex-col items-center gap-2">
          {uploading ? <Loader2 className="h-8 w-8 animate-spin text-teal-600" /> : <Upload className="h-8 w-8 text-gray-400" />}
          <span className="text-sm font-medium text-gray-500">{uploading ? t('جاري الرفع...', 'Uploading...') : t('اضغط لرفع الملف', 'Click to upload file')}</span>
        </button>
      )}
      <input ref={fileRef} type="file" accept="image/*,application/pdf,.zip,.doc,.docx,.txt" className="hidden" onChange={upload} />
    </div>
  );
}
