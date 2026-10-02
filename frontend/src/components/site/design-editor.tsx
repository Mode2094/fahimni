'use client';

import { useEffect, useRef, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Save, Loader2, Plus, Trash2, Upload, Image as ImageIcon, Palette, GripVertical, Eye, ArrowUp, ArrowDown, LayoutTemplate } from 'lucide-react';
import { toast } from 'sonner';
import { TEMPLATES, TemplateMini, FONTS, SECTION_OPTIONS, type TemplateId } from '@/components/site/templates';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const EMPTY_SLIDE = { title: '', subtitle: '', image: null as string | null, button_text: 'ابدأ الآن' };

export default function SiteDesignEditor({ onPreview }: { onPreview?: () => void }) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [site, setSite] = useState<any>(null);
  const [cfg, setCfg] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  useEffect(() => {
    fetch(`${API}/teacher/site-design`, { headers: headers() })
      .then(r => r.json()).then(d => { setSite(d.site || null); setCfg(d.site?.site_config || null); }).catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  const upload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      const res = await fetch(`${API}/upload`, { method: 'POST', headers: { 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' }, body: fd });
      if (!res.ok) throw new Error();
      const d = await res.json();
      setSite({ ...site, logo_url: d.url });
      toast.success(t('تم رفع الشعار', 'Logo uploaded'));
    } catch { toast.error('Failed'); }
    setUploading(false);
    if (fileRef.current) fileRef.current.value = '';
  };

  const save = async () => {
    setSaving(true);
    try {
      const res = await fetch(`${API}/teacher/site-design`, {
        method: 'PUT', headers: headers(),
        body: JSON.stringify({
          academy_name: site?.name,
          logo_url: site?.logo_url,
          primary_color: site?.primary_color,
          secondary_color: site?.secondary_color,
          accent_color: site?.accent_color,
          site_config: cfg,
        }),
      });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(t('تم حفظ التصميم — صفحتك محدثة فوراً', 'Design saved — your page is live now'));
    } catch { toast.error('Failed'); }
    setSaving(false);
  };

  if (loading) return <div className="flex justify-center py-16"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>;
  if (!site || !cfg) return <p className="text-center text-gray-400 py-10">{t('تعذر تحميل الإعدادات', 'Could not load settings')}</p>;

  const setSlide = (i: number, patch: any) => {
    const slides = [...(cfg.hero_slides || [])];
    slides[i] = { ...slides[i], ...patch };
    setCfg({ ...cfg, hero_slides: slides });
  };
  const addSlide = () => setCfg({ ...cfg, hero_slides: [...(cfg.hero_slides || []), { ...EMPTY_SLIDE }] });
  const removeSlide = (i: number) => setCfg({ ...cfg, hero_slides: (cfg.hero_slides || []).filter((_: any, j: number) => j !== i) });

  const setStat = (i: number, patch: any) => {
    const stats = [...(cfg.stats || [])];
    stats[i] = { ...stats[i], ...patch };
    setCfg({ ...cfg, stats });
  };

  const setItem = (key: string, i: number, patch: any) => {
    const items = [...(cfg[key] || [])];
    items[i] = { ...items[i], ...patch };
    setCfg({ ...cfg, [key]: items });
  };

  const pickTemplate = (id: TemplateId) => {
    const tpl = TEMPLATES[id];
    setCfg({ ...cfg, template: id });
    setSite({ ...site, primary_color: site?.primary_color === TEMPLATES[(cfg.template || 'modern-dark') as TemplateId]?.primary || !site?.primary_color ? tpl.primary : site.primary_color, secondary_color: site?.secondary_color === TEMPLATES[(cfg.template || 'modern-dark') as TemplateId]?.secondary || !site?.secondary_color ? tpl.secondary : site.secondary_color, accent_color: site?.accent_color === TEMPLATES[(cfg.template || 'modern-dark') as TemplateId]?.accent || !site?.accent_color ? tpl.accent : site.accent_color });
    const f = FONTS.find((x) => x.label === tpl.fontLabel) || FONTS[0];
    setCfg({ ...cfg, template: id, font: f.id });
  };

  const moveSection = (i: number, dir: number) => {
    const list = [...(cfg.sections || SECTION_OPTIONS.map((s) => s.id))];
    const j = i + dir;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j], list[i]];
    setCfg({ ...cfg, sections: list });
  };

  const Section = ({ children, title, hint }: { children: React.ReactNode; title: string; hint?: string }) => (
    <Card className="border-0 shadow-sm">
      <CardContent className="p-6 space-y-4">
        <div><h3 className="font-semibold">{title}</h3>{hint && <p className="text-xs text-gray-400 mt-0.5">{hint}</p>}</div>
        {children}
      </CardContent>
    </Card>
  );

  const Toggle = ({ on, onClick }: { on: boolean; onClick: () => void }) => (
    <button onClick={onClick} type="button" className="shrink-0">
      <div className={`relative w-11 h-6 rounded-full transition-all ${on ? 'bg-teal-500' : 'bg-gray-200'}`}>
        <div className={`absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all ${on ? 'start-5' : 'start-0.5'}`} />
      </div>
    </button>
  );

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-bold">{t('مصمم صفحتك', 'Your page designer')}</h2>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" className="gap-1.5 rounded-full" onClick={onPreview}><Eye className="h-3.5 w-3.5" />{t('معاينة', 'Preview')}</Button>
          <Button size="sm" className="gap-1.5 rounded-full" onClick={save} disabled={saving}>{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ التصميم', 'Save design')}</Button>
        </div>
      </div>

      {/* Template picker */}
      <Card className="border-0 shadow-sm ring-2 ring-teal-100">
        <CardContent className="p-6 space-y-4">
          <div className="flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-50"><LayoutTemplate className="h-5 w-5 text-teal-700" /></div>
            <div>
              <h3 className="font-semibold text-lg">{t('اختر قالب منصتك', 'Choose your site template')}</h3>
              <p className="text-xs text-gray-400">{t('4 تصاميم احترافية — اختر قالباً ثم عدّل عليه كما تريد', '4 professional designs — pick one then customize freely')}</p>
            </div>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {(Object.keys(TEMPLATES) as TemplateId[]).map((id) => (
              <TemplateMini key={id} id={id} selected={(cfg.template || 'modern-dark') === id} onPick={() => pickTemplate(id)} />
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Fonts + section order */}
      <div className="grid lg:grid-cols-2 gap-6">
        <Card className="border-0 shadow-sm">
          <CardContent className="p-6 space-y-4">
            <div className="flex items-center gap-2"><Palette className="h-4 w-4 text-violet-500" /><h3 className="font-semibold">{t('الخطوط', 'Typography')}</h3></div>
            <div className="grid grid-cols-2 gap-2.5">
              {FONTS.map((f) => (
                <button key={f.id} onClick={() => setCfg({ ...cfg, font: f.id })}
                  className={`p-3 rounded-xl border-2 text-right transition-all ${(cfg.font || 'tajawal') === f.id ? 'border-teal-500 bg-teal-50' : 'border-gray-100 hover:border-gray-300'}`}>
                  <p className="font-bold text-lg mb-1" style={{ fontFamily: f.heading }}>{t('عنوان جميل', 'Beautiful heading')}</p>
                  <p className="text-xs text-gray-400" style={{ fontFamily: f.body }}>{f.label} · {t('نص تجريبي', 'sample body')}</p>
                </button>
              ))}
            </div>
          </CardContent>
        </Card>

        <Card className="border-0 shadow-sm">
          <CardContent className="p-6 space-y-4">
            <div className="flex items-center gap-2"><GripVertical className="h-4 w-4 text-teal-600" /><h3 className="font-semibold">{t('ترتيب الأقسام', 'Section order')}</h3></div>
            <p className="text-xs text-gray-400 -mt-2">{t('رتب أقسام صفحتك حسب ما يناسبك', 'Arrange your page sections your way')}</p>
            <div className="space-y-1.5">
              {(cfg.sections || SECTION_OPTIONS.map((s) => s.id)).map((sid: string, i: number) => {
                const opt = SECTION_OPTIONS.find((s) => s.id === sid);
                const on = sid === 'courses' || sid === 'card' || (sid === 'stats' && !!cfg.show_stats) || (sid === 'about' && !!cfg.show_about) || (sid === 'testimonials' && !!cfg.show_testimonials) || (sid === 'faq' && !!cfg.show_faq) || sid === 'cta';
                if (sid !== 'courses' && sid !== 'card' && sid !== 'cta' && !on) return null;
                return (
                  <div key={sid} className="flex items-center gap-2 p-2.5 rounded-xl border bg-gray-50/50">
                    <GripVertical className="h-4 w-4 text-gray-300" />
                    <div className="flex-1">
                      <p className="text-sm font-medium">{locale === 'ar' ? opt?.labelAr : opt?.labelEn}</p>
                      <div className="flex gap-1.5 mt-0.5">
                        {(sid === 'stats' || sid === 'about' || sid === 'testimonials' || sid === 'faq') && (
                          <button onClick={() => setCfg({ ...cfg, [sid === 'stats' ? 'show_stats' : sid === 'about' ? 'show_about' : sid === 'testimonials' ? 'show_testimonials' : 'show_faq']: !cfg[sid === 'stats' ? 'show_stats' : sid === 'about' ? 'show_about' : sid === 'testimonials' ? 'show_testimonials' : 'show_faq'] })} className={`text-[10px] px-2 py-0.5 rounded-full ${on ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400'}`}>{on ? t('مفعل', 'On') : t('موقوف', 'Off')}</button>
                        )}
                      </div>
                    </div>
                    <div className="flex gap-0.5">
                      <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => moveSection(i, -1)} disabled={i === 0}><ArrowUp className="h-3.5 w-3.5" /></Button>
                      <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => moveSection(i, 1)} disabled={i === (cfg.sections || SECTION_OPTIONS.map((s) => s.id)).length - 1}><ArrowDown className="h-3.5 w-3.5" /></Button>
                    </div>
                  </div>
                );
              })}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Branding */}
      <Section title={t('الهوية والعلامة', 'Branding')}>
        <div className="grid sm:grid-cols-2 gap-5">
          <div className="space-y-2"><Label>{t('اسم المنصة', 'Platform name')}</Label><Input value={site.name || ''} onChange={(e) => setSite({ ...site, name: e.target.value })} className="h-11" /></div>
          <div className="space-y-2">
            <Label>{t('الشعار', 'Logo')}</Label>
            <div className="flex items-center gap-3">
              {site.logo_url ? <img src={site.logo_url} className="h-11 w-11 rounded-xl border object-cover bg-gray-50" alt="logo" /> : <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gray-100 border-2 border-dashed"><ImageIcon className="h-5 w-5 text-gray-300" /></div>}
              <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp" className="hidden" onChange={upload} />
              <Button variant="outline" size="sm" onClick={() => fileRef.current?.click()} disabled={uploading} className="gap-1.5 rounded-full">{uploading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Upload className="h-3.5 w-3.5" />}{t('رفع', 'Upload')}</Button>
              {site.logo_url && <Button variant="ghost" size="sm" className="text-red-500" onClick={() => setSite({ ...site, logo_url: null })}>{t('إزالة', 'Remove')}</Button>}
            </div>
          </div>
        </div>
        <div className="grid grid-cols-3 gap-4">
          {[['primary_color', t('اللون الأساسي', 'Primary')], ['secondary_color', t('اللون الداكن', 'Dark')], ['accent_color', t('لون التمييز', 'Accent')]].map(([key, label]: any) => (
            <div key={key} className="space-y-2">
              <Label>{label}</Label>
              <div className="flex items-center gap-2">
                <input type="color" value={site[key] || '#0d9488'} onChange={(e) => setSite({ ...site, [key]: e.target.value })} className="h-10 w-10 rounded-lg border shrink-0" />
                <Input value={site[key] || ''} onChange={(e) => setSite({ ...site, [key]: e.target.value })} className="h-10" />
              </div>
            </div>
          ))}
        </div>
      </Section>

      {/* Hero slides */}
      <Section title={t('السلايدات (الهيرو)', 'Hero slides')} hint={t('تظهر كسلايدر متحرك في أعلى صفحتك — أضف ما تريد', 'Shown as an auto-rotating slider at the top of your page')}>
        <div className="space-y-4">
          {(cfg.hero_slides || []).map((slide: any, i: number) => (
            <div key={i} className="p-4 rounded-2xl border bg-gray-50/50 space-y-3">
              <div className="flex items-center justify-between">
                <span className="flex items-center gap-2 text-sm font-medium text-gray-500"><GripVertical className="h-4 w-4" />{t('سلايد', 'Slide')} {i + 1}</span>
                <Button variant="ghost" size="icon" className="h-8 w-8 text-red-500" onClick={() => removeSlide(i)}><Trash2 className="h-4 w-4" /></Button>
              </div>
              <div className="grid sm:grid-cols-2 gap-3">
                <div className="space-y-1.5"><Label className="text-xs">{t('العنوان الرئيسي', 'Main title')}</Label><Input value={slide.title || ''} onChange={(e) => setSlide(i, { title: e.target.value })} className="h-10" /></div>
                <div className="space-y-1.5"><Label className="text-xs">{t('النص الفرعي', 'Subtitle')}</Label><Input value={slide.subtitle || ''} onChange={(e) => setSlide(i, { subtitle: e.target.value })} className="h-10" /></div>
              </div>
              <div className="grid sm:grid-cols-2 gap-3">
                <div className="space-y-1.5">
                  <Label className="text-xs">{t('صورة الخلفية (رابط)', 'Background image (URL)')}</Label>
                  <div className="flex gap-2">
                    <Input value={slide.image || ''} onChange={(e) => setSlide(i, { image: e.target.value })} className="h-10" dir="ltr" placeholder="https://..." />
                    {slide.image && <img src={slide.image} className="h-10 w-14 rounded-lg object-cover border shrink-0" alt="" />}
                  </div>
                </div>
                <div className="space-y-1.5"><Label className="text-xs">{t('نص الزر', 'Button text')}</Label><Input value={slide.button_text || 'ابدأ الآن'} onChange={(e) => setSlide(i, { button_text: e.target.value })} className="h-10" /></div>
              </div>
            </div>
          ))}
          <Button variant="outline" size="sm" className="gap-1.5 rounded-full" onClick={addSlide}><Plus className="h-4 w-4" />{t('إضافة سلايد', 'Add slide')}</Button>
        </div>
      </Section>

      {/* Stats */}
      <Section title={t('شريط الإحصائيات', 'Stats bar')}>
        <div className="flex items-center justify-between p-3 rounded-xl border">
          <div><p className="text-sm font-medium">{t('إظهار شريط الأرقام', 'Show stats bar')}</p><p className="text-xs text-gray-400">{t('أرقام الثقة ترفع التحويل', 'Trust numbers boost conversion')}</p></div>
          <Toggle on={!!cfg.show_stats} onClick={() => setCfg({ ...cfg, show_stats: !cfg.show_stats })} />
        </div>
        {cfg.show_stats && (
          <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
            {(cfg.stats || []).slice(0, 4).map((s: any, i: number) => (
              <div key={i} className="space-y-1.5 p-3 rounded-xl border">
                <Input value={s.value || ''} onChange={(e) => setStat(i, { value: e.target.value })} className="h-10 font-bold" />
                <Input value={s.label || ''} onChange={(e) => setStat(i, { label: e.target.value })} className="h-10 text-xs" />
              </div>
            ))}
          </div>
        )}
      </Section>

      {/* About */}
      <Section title={t('قسم "من نحن"', 'About section')}>
        <div className="flex items-center justify-between p-3 rounded-xl border">
          <p className="text-sm font-medium">{t('تفعيل القسم', 'Enable section')}</p>
          <Toggle on={!!cfg.show_about} onClick={() => setCfg({ ...cfg, show_about: !cfg.show_about })} />
        </div>
        {cfg.show_about && (
          <div className="space-y-3">
            <div className="space-y-1.5"><Label className="text-xs">{t('العنوان', 'Title')}</Label><Input value={cfg.about_title || ''} onChange={(e) => setCfg({ ...cfg, about_title: e.target.value })} className="h-10" /></div>
            <div className="space-y-1.5"><Label className="text-xs">{t('النص', 'Text')}</Label><Textarea value={cfg.about_text || ''} onChange={(e) => setCfg({ ...cfg, about_text: e.target.value })} className="min-h-[80px]" /></div>
            <div className="space-y-1.5"><Label className="text-xs">{t('صورة (رابط)', 'Image (URL)')}</Label><Input value={cfg.about_image || ''} onChange={(e) => setCfg({ ...cfg, about_image: e.target.value })} className="h-10" dir="ltr" /></div>
          </div>
        )}
      </Section>

      {/* Testimonials */}
      <Section title={t('آراء الطلاب', 'Testimonials')}>
        <div className="flex items-center justify-between p-3 rounded-xl border">
          <p className="text-sm font-medium">{t('تفعيل الآراء', 'Enable testimonials')}</p>
          <Toggle on={!!cfg.show_testimonials} onClick={() => setCfg({ ...cfg, show_testimonials: !cfg.show_testimonials })} />
        </div>
        {cfg.show_testimonials && (
          <div className="space-y-3">
            {(cfg.testimonials || []).map((tm: any, i: number) => (
              <div key={i} className="grid sm:grid-cols-2 gap-3 p-3 rounded-xl border bg-gray-50/50">
                <Input value={tm.name || ''} placeholder={t('الاسم', 'Name')} onChange={(e) => setItem('testimonials', i, { name: e.target.value })} className="h-10" />
                <Input value={tm.text || ''} placeholder={t('النص', 'Text')} onChange={(e) => setItem('testimonials', i, { text: e.target.value })} className="h-10" />
              </div>
            ))}
            <Button variant="outline" size="sm" className="gap-1.5 rounded-full" onClick={() => setCfg({ ...cfg, testimonials: [...(cfg.testimonials || []), { name: '', text: '' }] })}><Plus className="h-4 w-4" />{t('إضافة رأي', 'Add testimonial')}</Button>
          </div>
        )}
      </Section>

      {/* FAQ */}
      <Section title={t('الأسئلة الشائعة', 'FAQ')}>
        <div className="flex items-center justify-between p-3 rounded-xl border">
          <p className="text-sm font-medium">{t('تفعيل الأسئلة', 'Enable FAQ')}</p>
          <Toggle on={!!cfg.show_faq} onClick={() => setCfg({ ...cfg, show_faq: !cfg.show_faq })} />
        </div>
        {cfg.show_faq && (
          <div className="space-y-3">
            {(cfg.faq || []).map((f: any, i: number) => (
              <div key={i} className="space-y-2 p-3 rounded-xl border bg-gray-50/50">
                <Input value={f.q || ''} placeholder={t('السؤال', 'Question')} onChange={(e) => setItem('faq', i, { q: e.target.value })} className="h-10" />
                <Input value={f.a || ''} placeholder={t('الجواب', 'Answer')} onChange={(e) => setItem('faq', i, { a: e.target.value })} className="h-10" />
              </div>
            ))}
            <Button variant="outline" size="sm" className="gap-1.5 rounded-full" onClick={() => setCfg({ ...cfg, faq: [...(cfg.faq || []), { q: '', a: '' }] })}><Plus className="h-4 w-4" />{t('إضافة سؤال', 'Add question')}</Button>
          </div>
        )}
      </Section>

      {/* CTA + contact */}
      <Section title={t('الدعوة الختامية والتواصل', 'Final CTA & contact')}>
        <div className="grid sm:grid-cols-2 gap-4">
          <div className="space-y-1.5"><Label className="text-xs">{t('عنوان الدعوة', 'CTA title')}</Label><Input value={cfg.cta_title || ''} onChange={(e) => setCfg({ ...cfg, cta_title: e.target.value })} className="h-10" /></div>
          <div className="space-y-1.5"><Label className="text-xs">{t('نص الدعوة', 'CTA text')}</Label><Input value={cfg.cta_text || ''} onChange={(e) => setCfg({ ...cfg, cta_text: e.target.value })} className="h-10" /></div>
          <div className="space-y-1.5"><Label className="text-xs">WhatsApp</Label><Input value={cfg.contact_whatsapp || ''} onChange={(e) => setCfg({ ...cfg, contact_whatsapp: e.target.value })} className="h-10" dir="ltr" /></div>
          <div className="space-y-1.5"><Label className="text-xs">{t('الهاتف', 'Phone')}</Label><Input value={cfg.contact_phone || ''} onChange={(e) => setCfg({ ...cfg, contact_phone: e.target.value })} className="h-10" dir="ltr" /></div>
        </div>
      </Section>

      <div className="flex justify-end">
        <Button className="gap-2 rounded-full" onClick={save} disabled={saving}>{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ التصميم', 'Save design')}</Button>
      </div>
    </div>
  );
}
