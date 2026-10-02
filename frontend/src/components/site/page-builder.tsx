'use client';

import { useEffect, useRef, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent } from '@/components/ui/card';
import {
  GripVertical, Plus, Trash2, Copy, Pencil, Smartphone, Monitor, Save, Loader2,
  Type, Image as ImageIcon, PlayCircle, BarChart3, Users2, BookOpen, CreditCard,
  Quote, HelpCircle, Megaphone, Minus, AlignLeft, AlignCenter, Palette, Upload, X, LayoutTemplate,
} from 'lucide-react';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export type Block = {
  id: string;
  type: string;
  data: Record<string, any>;
  bg?: string;
  padding?: string;
  align?: string;
};

const PALETTE: { type: string; label: string; icon: any; hint: string; defaults: Record<string, any> }[] = [
  { type: 'hero', label: 'هيرو (رئيسي)', icon: LayoutTemplate, hint: 'عنوان + زر CTA', defaults: { title: 'عنوان جذاب هنا', subtitle: 'وصف قصير يشرح ماذا تقدم', badge: 'أكاديمية تعليمية', button_text: 'ابدأ الآن' } },
  { type: 'text', label: 'نص', icon: Type, hint: 'عنوان + فقرة', defaults: { heading: 'عنوان القسم', paragraph: 'اكتب نصاً هنا...' } },
  { type: 'image', label: 'صورة', icon: ImageIcon, hint: 'صورة مع تعليق', defaults: { caption: '' } },
  { type: 'video', label: 'فيديو', icon: PlayCircle, hint: 'يوتيوب / فيميو / رابط', defaults: { title: '' } },
  { type: 'stats', label: 'إحصائيات', icon: BarChart3, hint: 'أرقام ثقة', defaults: { stats: [{ value: '+100', label: 'طالب' }, { value: '+20', label: 'كورس' }, { value: '4.9', label: 'تقييم' }, { value: '24/7', label: 'دعم' }] } },
  { type: 'about', label: 'من نحن', icon: Users2, hint: 'تعريف + صورة', defaults: { title: 'من نحن', text: 'نحن نقدم تعليماً عالي الجودة...' } },
  { type: 'courses', label: 'الكورسات', icon: BookOpen, hint: 'كورساتك تلقائياً', defaults: { title: 'كورساتنا' } },
  { type: 'card', label: 'تفعيل البطاقة', icon: CreditCard, hint: 'قسم البطاقات', defaults: { title: 'عندك بطاقة تسجيل؟', text: 'أدخل الرمز وسجّل فوراً' } },
  { type: 'testimonials', label: 'آراء الطلاب', icon: Quote, hint: 'شهادات', defaults: { title: 'ماذا يقول طلابنا', items: [{ name: 'طالب', text: 'تجربة رائعة!' }, { name: 'طالب', text: 'تعلمت الكثير' }, { name: 'طالب', text: 'أنصح به' }] } },
  { type: 'faq', label: 'أسئلة شائعة', icon: HelpCircle, hint: 'سؤال/جواب', defaults: { title: 'الأسئلة الشائعة', items: [{ q: 'كيف أبدأ؟', a: 'سجل حساباً وابدأ فوراً' }] } },
  { type: 'cta', label: 'دعوة ختامية', icon: Megaphone, hint: 'CTA قوي', defaults: { title: 'جاهز تبدأ؟', text: 'سجل الآن وابدأ', button_text: 'سجل الآن' } },
  { type: 'divider', label: 'فاصل', icon: Minus, hint: 'خط فاصل', defaults: { color: '#e2e8f0' } },
];

const BLOCK_LABELS: Record<string, string> = {
  hero: 'هيرو', text: 'نص', image: 'صورة', video: 'فيديو', stats: 'إحصائيات',
  about: 'من نحن', courses: 'الكورسات', card: 'البطاقة', testimonials: 'آراء',
  faq: 'أسئلة', cta: 'دعوة', divider: 'فاصل',
};

export default function PageBuilder({ onSaved }: { onSaved?: () => void }) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [blocks, setBlocks] = useState<Block[]>([]);
  const [selected, setSelected] = useState<string | null>(null);
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [dragOver, setDragOver] = useState<string | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  useEffect(() => {
    fetch(`${API}/teacher/site-design`, { headers: headers() })
      .then(r => r.json()).then(d => {
        const existing = d.site?.site_config?.blocks || [];
        setBlocks(existing.length ? existing : []);
        setSelected(existing[0]?.id || null);
      }).catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  const uid = () => Math.random().toString(36).slice(2, 10);

  const addBlock = (type: string) => {
    const def = PALETTE.find((p) => p.type === type);
    const block: Block = { id: uid(), type, data: { ...(def?.defaults || {}) }, bg: 'white', padding: 'md', align: 'center' };
    setBlocks([...blocks, block]);
    setSelected(block.id);
  };

  const updateBlock = (id: string, patch: Partial<Block>) => {
    setBlocks(blocks.map((b) => b.id === id ? { ...b, ...patch } : b));
  };

  const updateData = (id: string, patch: Record<string, any>) => {
    const b = blocks.find((x) => x.id === id);
    if (!b) return;
    updateBlock(id, { data: { ...b.data, ...patch } });
  };

  const duplicateBlock = (id: string) => {
    const b = blocks.find((x) => x.id === id);
    if (!b) return;
    const copy = { ...b, id: uid(), data: { ...b.data, items: b.data.items ? [...b.data.items] : undefined, stats: b.data.stats ? [...b.data.stats] : undefined } };
    const idx = blocks.indexOf(b);
    const next = [...blocks];
    next.splice(idx + 1, 0, copy);
    setBlocks(next);
    setSelected(copy.id);
  };

  const removeBlock = (id: string) => {
    setBlocks(blocks.filter((b) => b.id !== id));
    if (selected === id) setSelected(null);
  };

  const moveBlock = (id: string, dir: number) => {
    const idx = blocks.findIndex((b) => b.id === id);
    const j = idx + dir;
    if (j < 0 || j >= blocks.length) return;
    const next = [...blocks];
    [next[idx], next[j]] = [next[j], next[idx]];
    setBlocks(next);
  };

  const onDrop = (e: React.DragEvent, targetId?: string) => {
    e.preventDefault();
    setDragOver(null);
    const type = e.dataTransfer.getData('block-type');
    const fromId = e.dataTransfer.getData('block-id');

    if (fromId) {
      const next = blocks.filter((b) => b.id !== fromId);
      const idx = targetId ? next.findIndex((b) => b.id === targetId) : next.length;
      const moved = blocks.find((b) => b.id === fromId)!;
      next.splice(idx, 0, moved);
      setBlocks(next);
      return;
    }

    if (type) {
      const def = PALETTE.find((p) => p.type === type);
      const block: Block = { id: uid(), type, data: { ...(def?.defaults || {}) }, bg: 'white', padding: 'md', align: 'center' };
      const next = [...blocks];
      const idx = targetId ? next.findIndex((b) => b.id === targetId) : next.length;
      next.splice(idx, 0, block);
      setBlocks(next);
      setSelected(block.id);
    }
  };

  const save = async () => {
    setSaving(true);
    try {
      const res = await fetch(`${API}/teacher/site-design`, { method: 'PUT', headers: headers(), body: JSON.stringify({ site_config: { blocks } }) });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(t('تم حفظ الصفحة — شاهدها الآن', 'Page saved — view it now'));
      onSaved?.();
    } catch { toast.error('Failed'); }
    setSaving(false);
  };

  const uploadImage = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file || !selected) return;
    setUploading(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      const res = await fetch(`${API}/upload`, { method: 'POST', headers: { 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' }, body: fd });
      if (!res.ok) throw new Error();
      const d = await res.json();
      updateData(selected, { url: d.url });
    } catch { toast.error('Failed'); }
    setUploading(false);
    if (fileRef.current) fileRef.current.value = '';
  };

  const sel = blocks.find((b) => b.id === selected);

  const BlockSettings = ({ b }: { b: Block }) => {
    const d = b.data;
    return (
      <div className="space-y-4">
        <div>
          <Label>{t('الخلفية', 'Background')}</Label>
          <div className="grid grid-cols-4 gap-1.5 mt-1.5">
            {[['white', 'أبيض'], ['light', 'فاتح'], ['dark', 'داكن'], ['accent', 'مميز']].map(([v, l]: any) => (
              <button key={v} onClick={() => updateBlock(b.id, { bg: v })} className={cn('p-2 rounded-lg border-2 text-xs transition-all', b.bg === v ? 'border-teal-500 bg-teal-50' : 'border-gray-100')}>{locale === 'ar' ? l : l}</button>
            ))}
          </div>
        </div>
        <div>
          <Label>{t('الحشوة', 'Padding')}</Label>
          <div className="grid grid-cols-3 gap-1.5 mt-1.5">
            {[['sm', 'ضيق'], ['md', 'وسط'], ['lg', 'واسع']].map(([v, l]: any) => (
              <button key={v} onClick={() => updateBlock(b.id, { padding: v })} className={cn('p-2 rounded-lg border-2 text-xs transition-all', b.padding === v ? 'border-teal-500 bg-teal-50' : 'border-gray-100')}>{locale === 'ar' ? l : l}</button>
            ))}
          </div>
        </div>
        {(b.type === 'text' || b.type === 'hero') && (
          <div>
            <Label>{t('المحاذاة', 'Alignment')}</Label>
            <div className="flex gap-1.5 mt-1.5">
              <button onClick={() => updateBlock(b.id, { align: 'left' })} className={cn('p-2 rounded-lg border-2', b.align === 'left' ? 'border-teal-500 bg-teal-50' : 'border-gray-100')}><AlignLeft className="h-4 w-4" /></button>
              <button onClick={() => updateBlock(b.id, { align: 'center' })} className={cn('p-2 rounded-lg border-2', b.align === 'center' ? 'border-teal-500 bg-teal-50' : 'border-gray-100')}><AlignCenter className="h-4 w-4" /></button>
            </div>
          </div>
        )}

        {b.type === 'hero' && (
          <>
            <Field label={t('الشعار الصغير', 'Badge')} value={d.badge || ''} onChange={(v) => updateData(b.id, { badge: v })} />
            <Field label={t('العنوان', 'Title')} value={d.title || ''} onChange={(v) => updateData(b.id, { title: v })} />
            <Field label={t('النص الفرعي', 'Subtitle')} value={d.subtitle || ''} onChange={(v) => updateData(b.id, { subtitle: v })} />
            <Field label={t('نص الزر', 'Button text')} value={d.button_text || ''} onChange={(v) => updateData(b.id, { button_text: v })} />
            <ImageField label={t('صورة الخلفية', 'Background image')} url={d.image} onUpload={() => fileRef.current?.click()} onClear={() => updateData(b.id, { image: '' })} />
          </>
        )}

        {b.type === 'text' && (
          <>
            <Field label={t('العنوان', 'Heading')} value={d.heading || ''} onChange={(v) => updateData(b.id, { heading: v })} />
            <div className="space-y-1.5"><Label>{t('الفقرة', 'Paragraph')}</Label><Textarea value={d.paragraph || ''} onChange={(e) => updateData(b.id, { paragraph: e.target.value })} className="min-h-[90px]" /></div>
          </>
        )}

        {b.type === 'image' && (
          <>
            <ImageField label={t('الصورة', 'Image')} url={d.url} onUpload={() => fileRef.current?.click()} onClear={() => updateData(b.id, { url: '' })} />
            <Field label={t('التعليق', 'Caption')} value={d.caption || ''} onChange={(v) => updateData(b.id, { caption: v })} />
            <Field label={t('الارتفاع (بكسل)', 'Height (px)')} value={String(d.height || '420')} onChange={(v) => updateData(b.id, { height: Number(v) || 420 })} />
          </>
        )}

        {b.type === 'video' && (
          <>
            <Field label={t('رابط الفيديو', 'Video URL')} value={d.url || ''} onChange={(v) => updateData(b.id, { url: v })} />
            <Field label={t('العنوان', 'Title')} value={d.title || ''} onChange={(v) => updateData(b.id, { title: v })} />
          </>
        )}

        {b.type === 'stats' && (
          <div className="space-y-2">
            <Label>{t('الأرقام', 'Stats')}</Label>
            {(d.stats || []).map((s: any, i: number) => (
              <div key={i} className="flex gap-1.5">
                <Input value={s.value} onChange={(e) => updateData(b.id, { stats: (d.stats || []).map((x: any, j: number) => j === i ? { ...x, value: e.target.value } : x) })} className="h-9" />
                <Input value={s.label} onChange={(e) => updateData(b.id, { stats: (d.stats || []).map((x: any, j: number) => j === i ? { ...x, label: e.target.value } : x) })} className="h-9" />
                <Button variant="ghost" size="icon" className="h-9 w-9 text-red-400" onClick={() => updateData(b.id, { stats: (d.stats || []).filter((_: any, j: number) => j !== i) })}><Trash2 className="h-3.5 w-3.5" /></Button>
              </div>
            ))}
            <Button variant="outline" size="sm" className="gap-1 rounded-full text-xs" onClick={() => updateData(b.id, { stats: [...(d.stats || []), { value: '+100', label: 'رقم' }] })}><Plus className="h-3.5 w-3.5" />{t('إضافة رقم', 'Add stat')}</Button>
          </div>
        )}

        {b.type === 'about' && (
          <>
            <Field label={t('العنوان', 'Title')} value={d.title || ''} onChange={(v) => updateData(b.id, { title: v })} />
            <div className="space-y-1.5"><Label>{t('النص', 'Text')}</Label><Textarea value={d.text || ''} onChange={(e) => updateData(b.id, { text: e.target.value })} className="min-h-[90px]" /></div>
            <ImageField label={t('الصورة', 'Image')} url={d.image} onUpload={() => fileRef.current?.click()} onClear={() => updateData(b.id, { image: '' })} />
          </>
        )}

        {b.type === 'courses' && <Field label={t('عنوان القسم', 'Section title')} value={d.title || ''} onChange={(v) => updateData(b.id, { title: v })} />}

        {b.type === 'card' && (
          <>
            <Field label={t('العنوان', 'Title')} value={d.title || ''} onChange={(v) => updateData(b.id, { title: v })} />
            <Field label={t('النص', 'Text')} value={d.text || ''} onChange={(v) => updateData(b.id, { text: v })} />
          </>
        )}

        {b.type === 'testimonials' && (
          <div className="space-y-2">
            <Field label={t('العنوان', 'Title')} value={d.title || ''} onChange={(v) => updateData(b.id, { title: v })} />
            {(d.items || []).map((tm: any, i: number) => (
              <div key={i} className="space-y-1.5 p-2.5 rounded-xl border bg-gray-50/60">
                <Input value={tm.name} placeholder={t('الاسم', 'Name')} onChange={(e) => updateData(b.id, { items: (d.items || []).map((x: any, j: number) => j === i ? { ...x, name: e.target.value } : x) })} className="h-9" />
                <Input value={tm.text} placeholder={t('النص', 'Text')} onChange={(e) => updateData(b.id, { items: (d.items || []).map((x: any, j: number) => j === i ? { ...x, text: e.target.value } : x) })} className="h-9" />
              </div>
            ))}
            <Button variant="outline" size="sm" className="gap-1 rounded-full text-xs" onClick={() => updateData(b.id, { items: [...(d.items || []), { name: '', text: '' }] })}><Plus className="h-3.5 w-3.5" />{t('إضافة', 'Add')}</Button>
          </div>
        )}

        {b.type === 'faq' && (
          <div className="space-y-2">
            <Field label={t('العنوان', 'Title')} value={d.title || ''} onChange={(v) => updateData(b.id, { title: v })} />
            {(d.items || []).map((f: any, i: number) => (
              <div key={i} className="space-y-1.5 p-2.5 rounded-xl border bg-gray-50/60">
                <Input value={f.q} placeholder={t('السؤال', 'Question')} onChange={(e) => updateData(b.id, { items: (d.items || []).map((x: any, j: number) => j === i ? { ...x, q: e.target.value } : x) })} className="h-9" />
                <Input value={f.a} placeholder={t('الجواب', 'Answer')} onChange={(e) => updateData(b.id, { items: (d.items || []).map((x: any, j: number) => j === i ? { ...x, a: e.target.value } : x) })} className="h-9" />
              </div>
            ))}
            <Button variant="outline" size="sm" className="gap-1 rounded-full text-xs" onClick={() => updateData(b.id, { items: [...(d.items || []), { q: '', a: '' }] })}><Plus className="h-3.5 w-3.5" />{t('إضافة', 'Add')}</Button>
          </div>
        )}

        {b.type === 'cta' && (
          <>
            <Field label={t('العنوان', 'Title')} value={d.title || ''} onChange={(v) => updateData(b.id, { title: v })} />
            <Field label={t('النص', 'Text')} value={d.text || ''} onChange={(v) => updateData(b.id, { text: v })} />
            <Field label={t('نص الزر', 'Button text')} value={d.button_text || ''} onChange={(v) => updateData(b.id, { button_text: v })} />
          </>
        )}

        {b.type === 'divider' && (
          <div className="flex items-center gap-2"><Label>{t('اللون', 'Color')}</Label><input type="color" value={d.color || '#e2e8f0'} onChange={(e) => updateData(b.id, { color: e.target.value })} className="h-10 w-10 rounded-lg border" /></div>
        )}
      </div>
    );
  };

  const Field = ({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) => (
    <div className="space-y-1.5"><Label>{label}</Label><Input value={value} onChange={(e) => onChange(e.target.value)} className="h-10" /></div>
  );

  const ImageField = ({ label, url, onUpload, onClear }: { label: string; url?: string; onUpload: () => void; onClear: () => void }) => (
    <div className="space-y-1.5">
      <Label>{label}</Label>
      <div className="flex items-center gap-2">
        {url ? <img src={url} className="h-12 w-20 rounded-lg border object-cover" alt="" /> : <div className="flex h-12 w-20 items-center justify-center rounded-lg bg-gray-100 border-2 border-dashed"><ImageIcon className="h-5 w-5 text-gray-300" /></div>}
        <Button variant="outline" size="sm" onClick={onUpload} disabled={uploading} className="gap-1.5 rounded-full text-xs">{uploading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Upload className="h-3.5 w-3.5" />}{t('رفع', 'Upload')}</Button>
        {url && <Button variant="ghost" size="sm" className="text-red-400" onClick={onClear}><X className="h-3.5 w-3.5" /></Button>}
      </div>
    </div>
  );

  if (loading) return <div className="flex justify-center py-16"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>;

  return (
    <div className="space-y-4">
      <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={uploadImage} />

      {/* Toolbar */}
      <div className="flex items-center justify-between p-3 bg-white rounded-2xl border shadow-sm sticky top-20 z-30">
        <div className="flex items-center gap-1.5 p-1 bg-gray-100 rounded-xl">
          <button onClick={() => setDevice('desktop')} className={cn('flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium', device === 'desktop' ? 'bg-white shadow' : 'text-gray-500')}><Monitor className="h-3.5 w-3.5" />{t('حاسوب', 'Desktop')}</button>
          <button onClick={() => setDevice('mobile')} className={cn('flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium', device === 'mobile' ? 'bg-white shadow' : 'text-gray-500')}><Smartphone className="h-3.5 w-3.5" />{t('جوال', 'Mobile')}</button>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-xs text-gray-400">{blocks.length} {t('كتلة', 'blocks')}</span>
          <Button onClick={save} disabled={saving} className="gap-1.5 rounded-full">{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ الصفحة', 'Save page')}</Button>
        </div>
      </div>

      <div className="grid lg:grid-cols-4 gap-4 items-start">
        {/* Palette */}
        <div className="space-y-2">
          <p className="text-xs font-bold text-gray-400">{t('اسحب كتلة إلى الصفحة', 'Drag a block into the page')}</p>
          <div className="space-y-1.5">
            {PALETTE.map((p) => (
              <div key={p.type} draggable onDragStart={(e) => { e.dataTransfer.setData('block-type', p.type); }}
                className="flex items-center gap-3 p-2.5 rounded-xl border bg-white cursor-grab hover:border-teal-400 hover:shadow-sm transition-all">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-50 shrink-0"><p.icon className="h-4 w-4 text-teal-700" /></div>
                <div className="flex-1"><p className="text-sm font-medium">{locale === 'ar' ? p.label : p.label}</p><p className="text-[10px] text-gray-400">{p.hint}</p></div>
                <GripVertical className="h-4 w-4 text-gray-300" />
              </div>
            ))}
          </div>
        </div>

        {/* Canvas */}
        <div className={cn('lg:col-span-2', device === 'mobile' && 'max-w-[400px] mx-auto')}>
          <div className="space-y-2 min-h-[400px]" onDragOver={(e) => e.preventDefault()} onDrop={(e) => onDrop(e)}>
            {blocks.length === 0 && (
              <div className="flex flex-col items-center justify-center py-24 rounded-3xl border-2 border-dashed border-gray-200 text-gray-300">
                <LayoutTemplate className="h-12 w-12 mb-3" />
                <p className="text-sm">{t('اسحب كتل من اليسار لبناء صفحتك', 'Drag blocks from the left to build your page')}</p>
              </div>
            )}
            {blocks.map((b) => (
              <div key={b.id}
                onDragOver={(e) => { e.preventDefault(); setDragOver(b.id); }}
                onDragLeave={() => setDragOver(null)}
                onDrop={(e) => onDrop(e, b.id)}
                draggable
                onDragStart={(e) => { e.dataTransfer.setData('block-id', b.id); }}
                onClick={() => setSelected(b.id)}
                className={cn('group relative rounded-2xl border-2 transition-all', selected === b.id ? 'border-teal-500 shadow-lg shadow-blue-500/10' : dragOver === b.id ? 'border-blue-400 border-dashed' : 'border-gray-100')}>
                <div className="flex items-center justify-between px-3 py-1.5 bg-gray-50 rounded-t-2xl border-b">
                  <span className="flex items-center gap-1.5 text-[10px] font-bold text-gray-500"><GripVertical className="h-3 w-3" />{BLOCK_LABELS[b.type] || b.type}</span>
                  <div className="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button onClick={(e) => { e.stopPropagation(); setSelected(b.id); }} className="p-1 rounded hover:bg-gray-200"><Pencil className="h-3 w-3" /></button>
                    <button onClick={(e) => { e.stopPropagation(); duplicateBlock(b.id); }} className="p-1 rounded hover:bg-gray-200"><Copy className="h-3 w-3" /></button>
                    <button onClick={(e) => { e.stopPropagation(); moveBlock(b.id, -1); }} className="p-1 rounded hover:bg-gray-200">↑</button>
                    <button onClick={(e) => { e.stopPropagation(); moveBlock(b.id, 1); }} className="p-1 rounded hover:bg-gray-200">↓</button>
                    <button onClick={(e) => { e.stopPropagation(); removeBlock(b.id); }} className="p-1 rounded hover:bg-red-100 text-red-500"><Trash2 className="h-3 w-3" /></button>
                  </div>
                </div>
                <div className="pointer-events-none select-none p-1">
                  <BlockThumb b={b} />
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Settings */}
        <div className="space-y-3">
          <p className="text-xs font-bold text-gray-400">{t('إعدادات الكتلة', 'Block settings')}</p>
          {sel ? (
            <Card className="border-0 shadow-sm">
              <CardContent className="p-4">
                <BlockSettings b={sel} />
              </CardContent>
            </Card>
          ) : (
            <div className="p-6 rounded-2xl border border-dashed border-gray-200 text-center text-sm text-gray-300">
              <Pencil className="h-8 w-8 mx-auto mb-2" />
              {t('اضغط على أي كتلة لتعديلها', 'Click a block to edit it')}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function BlockThumb({ b }: { b: Block }) {
  const d = b.data;
  const style: React.CSSProperties = {
    background: b.bg === 'dark' ? '#0f172a' : b.bg === 'light' ? '#f8fafc' : b.bg === 'accent' ? 'linear-gradient(135deg, #0d9488, #38bdf8)' : '#ffffff',
    color: b.bg === 'dark' || b.bg === 'accent' ? '#fff' : '#0f172a',
    padding: b.padding === 'lg' ? '2.5rem' : b.padding === 'sm' ? '0.75rem' : '1.5rem',
    textAlign: (b.align || 'center') as React.CSSProperties['textAlign'],
    minHeight: 48,
  };
  switch (b.type) {
    case 'hero': return <div style={{ ...style, background: '#0f172a', color: '#fff', borderRadius: 8 }}><div className="text-xs font-bold">{d.title || 'Hero'}</div><div className="text-[9px] opacity-60 mt-1">{d.subtitle}</div><div className="inline-block mt-2 px-3 py-1 rounded-full text-[9px] text-white" style={{ background: '#0d9488' }}>{d.button_text || 'ابدأ'}</div></div>;
    case 'text': return <div style={style}><div className="text-xs font-bold">{d.heading}</div><div className="text-[9px] opacity-60 mt-1 line-clamp-2">{d.paragraph}</div></div>;
    case 'image': return <div style={style}><div className="h-10 rounded-lg bg-gray-200 flex items-center justify-center text-[9px] text-gray-400">🖼️ {d.caption}</div></div>;
    case 'video': return <div style={style}><div className="aspect-video rounded-lg bg-slate-800 flex items-center justify-center text-[9px] text-white">▶ {d.title || 'Video'}</div></div>;
    case 'stats': return <div style={style}><div className="grid grid-cols-4 gap-1">{(d.stats || []).slice(0, 4).map((s: any, i: number) => <div key={i} className="text-center"><div className="text-[10px] font-bold">{s.value}</div><div className="text-[8px] opacity-60">{s.label}</div></div>)}</div></div>;
    case 'about': return <div style={style}><div className="text-xs font-bold">{d.title}</div><div className="text-[9px] opacity-60 line-clamp-1">{d.text}</div></div>;
    case 'courses': return <div style={style}><div className="text-xs font-bold">{d.title || 'Courses'}</div><div className="grid grid-cols-3 gap-1 mt-2">{[1, 2, 3].map((i) => <div key={i} className="h-8 rounded-md bg-gray-200" />)}</div></div>;
    case 'card': return <div style={{ ...style, background: '#0f172a', color: '#fff' }}><div className="text-xs font-bold">💳 {d.title}</div></div>;
    case 'testimonials': return <div style={style}><div className="text-xs font-bold">{d.title}</div><div className="grid grid-cols-3 gap-1 mt-2">{(d.items || []).slice(0, 3).map((x: any, i: number) => <div key={i} className="p-1 rounded bg-gray-100 text-[8px] text-gray-500 line-clamp-2">"{x.text}"</div>)}</div></div>;
    case 'faq': return <div style={style}><div className="text-xs font-bold">{d.title}</div><div className="text-[9px] opacity-60">{(d.items || []).length} {d.items?.length ? 'سؤال' : 'questions'}</div></div>;
    case 'cta': return <div style={{ ...style, background: '#0d9488', color: '#fff' }}><div className="text-xs font-bold">{d.title}</div><div className="inline-block mt-1 px-3 py-1 rounded-full bg-white text-teal-700 text-[9px]">{d.button_text || 'CTA'}</div></div>;
    case 'divider': return <div style={style}><div style={{ height: 1, background: d.color || '#e2e8f0' }} /></div>;
    default: return <div style={style}>?</div>;
  }
}
