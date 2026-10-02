'use client';

import { useLocale } from '@/store/locale';
import { Check, Star, Layers, Sparkles, BookOpen } from 'lucide-react';
import { cn } from '@/lib/utils';

export type TemplateId = 'modern-dark' | 'bright-modern' | 'luxury' | 'academic';

export const TEMPLATES: Record<TemplateId, {
  name: string;
  desc: string;
  font: string;
  fontLabel: string;
  headingFont: string;
  bodyFont: string;
  hero: 'slider-dark' | 'gradient-light' | 'luxury-dark' | 'clean-light';
  primary: string;
  secondary: string;
  accent: string;
  radius: string;
}> = {
  'modern-dark': {
    name: 'مودرن داكن',
    desc: 'هيرو داكن بسلايدر متحرك — أنيق وتقني',
    font: 'Tajawal',
    fontLabel: 'تجوال',
    headingFont: "'Tajawal', sans-serif",
    bodyFont: "'Inter', 'Tajawal', sans-serif",
    hero: 'slider-dark',
    primary: '#0d9488',
    secondary: '#0f172a',
    accent: '#38bdf8',
    radius: '1rem',
  },
  'bright-modern': {
    name: 'مشرق وحديث',
    desc: 'ألوان مبهجة وتدرجات ناعمة — ودود وجذاب',
    font: 'Cairo',
    fontLabel: 'القاهرة',
    headingFont: "'Cairo', sans-serif",
    bodyFont: "'Cairo', 'Inter', sans-serif",
    hero: 'gradient-light',
    primary: '#0ea5e9',
    secondary: '#0f172a',
    accent: '#f97316',
    radius: '1.5rem',
  },
  'luxury': {
    name: 'فاخر ذهبي',
    desc: 'داكن فاخر بلمسات ذهبية — راقٍ وفخم',
    font: 'Amiri',
    fontLabel: 'أميري',
    headingFont: "'Amiri', serif",
    bodyFont: "'Tajawal', sans-serif",
    hero: 'luxury-dark',
    primary: '#d4a017',
    secondary: '#0c0a09',
    accent: '#f5d97a',
    radius: '0.75rem',
  },
  'academic': {
    name: 'أكاديمي كلاسيكي',
    desc: 'نظيف وواضح بألوان هادئة — ثقة واحترافية',
    font: 'Changa',
    fontLabel: 'تشانغا',
    headingFont: "'Changa', sans-serif",
    bodyFont: "'Tajawal', sans-serif",
    hero: 'clean-light',
    primary: '#0f766e',
    secondary: '#134e4a',
    accent: '#2dd4bf',
    radius: '1.25rem',
  },
};

export const FONTS = [
  { id: 'tajawal', label: 'تجوال', heading: "'Tajawal', sans-serif", body: "'Inter', 'Tajawal', sans-serif" },
  { id: 'cairo', label: 'القاهرة', heading: "'Cairo', sans-serif", body: "'Cairo', 'Inter', sans-serif" },
  { id: 'amiri', label: 'أميري (فاخر)', heading: "'Amiri', serif", body: "'Tajawal', sans-serif" },
  { id: 'changa', label: 'تشانغا (عصري)', heading: "'Changa', sans-serif", body: "'Tajawal', sans-serif" },
];

export const SECTION_OPTIONS = [
  { id: 'stats', labelAr: 'شريط الإحصائيات', labelEn: 'Stats bar' },
  { id: 'about', labelAr: 'من نحن', labelEn: 'About' },
  { id: 'courses', labelAr: 'الكورسات', labelEn: 'Courses' },
  { id: 'card', labelAr: 'تفعيل البطاقة', labelEn: 'Card activation' },
  { id: 'testimonials', labelAr: 'آراء الطلاب', labelEn: 'Testimonials' },
  { id: 'faq', labelAr: 'الأسئلة الشائعة', labelEn: 'FAQ' },
  { id: 'cta', labelAr: 'الدعوة الختامية', labelEn: 'Final CTA' },
];

export function TemplateMini({ id, selected, onPick }: { id: TemplateId; selected: boolean; onPick: () => void }) {
  const { locale } = useLocale();
  const tpl = TEMPLATES[id];
  const isDark = tpl.hero === 'slider-dark' || tpl.hero === 'luxury-dark';

  return (
    <button onClick={onPick} className={cn('group relative rounded-2xl overflow-hidden border-2 text-left transition-all', selected ? 'border-teal-500 shadow-lg shadow-teal-500/20 scale-[1.02]' : 'border-gray-200 hover:border-teal-300 hover:shadow-md')}>
      <div className="h-32 relative overflow-hidden" style={{ background: isDark ? tpl.secondary : `linear-gradient(135deg, #f8fafc, ${tpl.primary}15)` }}>
        {/* mini hero mock */}
        <div className="absolute inset-0" style={{ background: isDark ? `radial-gradient(300px 120px at 60% 20%, ${tpl.primary}30, transparent)` : `radial-gradient(300px 120px at 40% 20%, ${tpl.accent}22, transparent)` }} />
        <div className="relative pt-4 px-4">
          <div className="flex items-center gap-1.5 mb-3">
            <div className="h-4 w-4 rounded-full" style={{ background: tpl.primary }} />
            <div className="h-1.5 w-16 rounded-full" style={{ background: isDark ? 'rgba(255,255,255,0.6)' : '#94a3b8' }} />
          </div>
          <div className="h-2.5 w-3/4 rounded-full mb-1.5" style={{ background: tpl.primary }} />
          <div className="h-1.5 w-1/2 rounded-full mb-2.5" style={{ background: isDark ? 'rgba(255,255,255,0.35)' : '#cbd5e1' }} />
          <div className="flex gap-1.5">
            <div className="h-5 w-14 rounded-full" style={{ background: tpl.primary }} />
            <div className="h-5 w-14 rounded-full border" style={{ borderColor: isDark ? 'rgba(255,255,255,0.4)' : '#cbd5e1' }} />
          </div>
        </div>
        <div className="absolute bottom-2 right-2 left-2 grid grid-cols-3 gap-1">
          <div className="h-5 rounded-md" style={{ background: isDark ? 'rgba(255,255,255,0.12)' : 'white', boxShadow: '0 1px 2px rgba(0,0,0,0.05)' }} />
          <div className="h-5 rounded-md" style={{ background: isDark ? 'rgba(255,255,255,0.12)' : 'white', boxShadow: '0 1px 2px rgba(0,0,0,0.05)' }} />
          <div className="h-5 rounded-md" style={{ background: isDark ? 'rgba(255,255,255,0.12)' : 'white', boxShadow: '0 1px 2px rgba(0,0,0,0.05)' }} />
        </div>
      </div>
      <div className="p-3 flex items-center justify-between bg-white">
        <div>
          <p className="text-sm font-bold">{locale === 'ar' ? tpl.name : tpl.name}</p>
          <p className="text-[10px] text-gray-400">{locale === 'ar' ? tpl.desc : tpl.desc}</p>
        </div>
        {selected && <div className="flex h-6 w-6 items-center justify-center rounded-full bg-teal-500 text-white"><Check className="h-3.5 w-3.5" /></div>}
      </div>
    </button>
  );
}
