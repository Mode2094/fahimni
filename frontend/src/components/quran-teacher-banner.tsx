'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { BookOpen, CheckCircle2, ChevronDown, Loader2, MessageCircle, Search } from 'lucide-react';
import { toast } from 'sonner';
import { buildCountries } from '@/lib/countries';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const WHATSAPP = '962793519944';

const TAJWEED_LEVELS = [
  { value: 'beginner', ar: 'مبتدئ', en: 'Beginner' },
  { value: 'intermediate', ar: 'متوسط', en: 'Intermediate' },
  { value: 'advanced', ar: 'متقدم', en: 'Advanced' },
  { value: 'proficient', ar: 'مجيد', en: 'Proficient' },
  { value: 'ijazah', ar: 'مجاز بإجازة', en: 'Certified (Ijazah)' },
];

export default function QuranTeacherBanner() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [showForm, setShowForm] = useState(false);
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const [form, setForm] = useState({
    name: '', email: '', phone: '', city: '', country: '', memorized_juz: '', tajweed_level: '', message: '',
  });

  const juzOptions = useMemo(() => Array.from({ length: 30 }, (_, i) => i + 1), []);

  const handleSubmit = async () => {
    if (!form.name || !form.email || !form.country) {
      toast.error(t('يرجى تعبئة الاسم والبريد والدولة', 'Please fill in name, email and country'));
      return;
    }
    setLoading(true);
    try {
      const res = await fetch(`${API}/quran-teacher-requests`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(form),
      });
      const data = await res.json();
      if (res.ok) {
        setSuccess(true);
        toast.success(data.message);
      } else {
        toast.error(data.message || 'حدث خطأ');
      }
    } catch {
      toast.error('حدث خطأ في الاتصال');
    } finally {
      setLoading(false);
    }
  };

  return (
    <section className="py-16 sm:py-20" style={{ background: 'linear-gradient(135deg, #064e3b 0%, #0d9488 100%)' }}>
      <div className="max-w-4xl mx-auto px-4 sm:px-6">
        <Card className="bg-white/10 backdrop-blur-sm border-white/20 shadow-2xl">
          <CardContent className="p-8 sm:p-10">
            <div className="text-center mb-8">
              <div className="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-white/15 mb-5">
                <BookOpen className="h-8 w-8 text-white" />
              </div>
              <h2 className="text-2xl sm:text-3xl font-black text-white mb-3">
                {t('المنصة مجانية لمدرسي القرءان الكريم', 'Free for Quran Teachers')}
              </h2>
              <p className="text-white/70 text-sm sm:text-base max-w-xl mx-auto">
                {t(
                  'نقدم حساباً مجانياً كاملاً لمدرسي القرءان الكريم. سجّل الآن وابدأ رحلتك التعليمية.',
                  'We offer a fully free account for Quran teachers and memorizers. Sign up now and start your teaching journey.'
                )}
              </p>
            </div>

            {success ? (
              <div className="text-center py-8">
                <CheckCircle2 className="h-16 w-16 text-emerald-300 mx-auto mb-4" />
                <h3 className="text-xl font-bold text-white mb-2">
                  {t('تم استلام طلبك بنجاح', 'Your request has been submitted')}
                </h3>
                <p className="text-white/70 text-sm max-w-md mx-auto">
                  {t(
                    'سنراجع طلبك ونتواصل معك عبر البريد الإلكتروني خلال أيام قليلة.',
                    'We will review your request and contact you via email within a few days.'
                  )}
                </p>
              </div>
            ) : !showForm ? (
              <div className="text-center">
                <Button
                  onClick={() => setShowForm(true)}
                  className="bg-white text-emerald-700 hover:bg-white/90 font-bold px-8 py-3 rounded-2xl text-base"
                >
                  {t('قدم طلبك الآن', 'Apply Now')}
                </Button>
              </div>
            ) : (
              <div className="space-y-4 max-w-lg mx-auto">
                <div className="grid sm:grid-cols-2 gap-4">
                  <div>
                    <label className="text-xs font-bold text-white/60 mb-1 block">{t('الاسم الكامل *', 'Full Name *')}</label>
                    <Input
                      value={form.name}
                      onChange={(e) => setForm({ ...form, name: e.target.value })}
                      placeholder={t('محمد أحمد', 'Mohammed Ahmad')}
                      className="bg-white/10 border-white/20 text-white placeholder:text-white/40"
                    />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-white/60 mb-1 block">{t('البريد الإلكتروني *', 'Email *')}</label>
                    <Input
                      type="email"
                      value={form.email}
                      onChange={(e) => setForm({ ...form, email: e.target.value })}
                      placeholder="example@email.com"
                      className="bg-white/10 border-white/20 text-white placeholder:text-white/40"
                    />
                  </div>
                </div>
                <div className="grid sm:grid-cols-2 gap-4">
                  <div>
                    <label className="text-xs font-bold text-white/60 mb-1 block">{t('رقم الهاتف', 'Phone')}</label>
                    <Input
                      value={form.phone}
                      onChange={(e) => setForm({ ...form, phone: e.target.value })}
                      placeholder="+966..."
                      className="bg-white/10 border-white/20 text-white placeholder:text-white/40"
                    />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-white/60 mb-1 block">{t('المدينة', 'City')}</label>
                    <Input
                      value={form.city}
                      onChange={(e) => setForm({ ...form, city: e.target.value })}
                      placeholder={t('الرياض', 'Riyadh')}
                      className="bg-white/10 border-white/20 text-white placeholder:text-white/40"
                    />
                  </div>
                </div>
                <div>
                  <label className="text-xs font-bold text-white/60 mb-1 block">{t('البلد *', 'Country *')}</label>
                  <CountrySelect
                    value={form.country}
                    onChange={(v) => setForm({ ...form, country: v })}
                    locale={locale === 'ar' ? 'ar' : 'en'}
                  />
                </div>
                <div className="grid sm:grid-cols-2 gap-4">
                  <div>
                    <label className="text-xs font-bold text-white/60 mb-1 block">
                      {t('كم تحفظ من كتاب الله؟ (١–٣٠ جزء)', 'How much of the Quran do you memorize? (1–30 juz)')}
                    </label>
                    <select
                      value={form.memorized_juz}
                      onChange={(e) => setForm({ ...form, memorized_juz: e.target.value })}
                      className="w-full bg-white/10 border border-white/20 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:ring-2 focus:ring-white/30 [&>option]:bg-white [&>option]:text-slate-900"
                    >
                      <option value="">{t('اختر عدد الأجزاء', 'Select number of juz')}</option>
                      {juzOptions.map((n) => (
                        <option key={n} value={n}>
                          {t(`${n} جزء`, `${n} juz`)}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="text-xs font-bold text-white/60 mb-1 block">{t('مستواك في التجويد', 'Your Tajweed Level')}</label>
                    <select
                      value={form.tajweed_level}
                      onChange={(e) => setForm({ ...form, tajweed_level: e.target.value })}
                      className="w-full bg-white/10 border border-white/20 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:ring-2 focus:ring-white/30 [&>option]:bg-white [&>option]:text-slate-900"
                    >
                      <option value="">{t('اختر المستوى', 'Select level')}</option>
                      {TAJWEED_LEVELS.map((lvl) => (
                        <option key={lvl.value} value={lvl.value}>
                          {t(lvl.ar, lvl.en)}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
                <div>
                  <label className="text-xs font-bold text-white/60 mb-1 block">{t('رسالتك (اختياري)', 'Your Message (optional)')}</label>
                  <textarea
                    value={form.message}
                    onChange={(e) => setForm({ ...form, message: e.target.value })}
                    rows={3}
                    placeholder={t('أخبرنا عن خبرتك في تعليم القرءان...', 'Tell us about your Quran teaching experience...')}
                    className="w-full bg-white/10 border border-white/20 rounded-xl px-3 py-2 text-sm text-white placeholder:text-white/40 resize-none focus:outline-none focus:ring-2 focus:ring-white/30"
                  />
                </div>
                <div className="flex gap-3 pt-2">
                  <Button
                    onClick={handleSubmit}
                    disabled={loading}
                    className="flex-1 bg-white text-emerald-700 hover:bg-white/90 font-bold"
                  >
                    {loading ? <Loader2 className="h-4 w-4 animate-spin mx-auto" /> : t('إرسال الطلب', 'Submit Request')}
                  </Button>
                  <Button
                    variant="outline"
                    onClick={() => setShowForm(false)}
                    className="text-white border-white/25 hover:bg-white/10"
                  >
                    {t('إلغاء', 'Cancel')}
                  </Button>
                </div>
                <a
                  href={`https://wa.me/${WHATSAPP}?text=${encodeURIComponent(t('مرحباً، أرغب بالتسجيل كمعلم قرءان في المنصة', 'Hello, I would like to register as a Quran teacher on the platform'))}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center justify-center gap-2 rounded-xl bg-green-600 hover:bg-green-700 text-white font-bold px-4 py-2.5 text-sm transition-colors"
                >
                  <MessageCircle className="h-4 w-4" />
                  {t('تواصل معنا عبر واتساب', 'Contact us on WhatsApp')}
                </a>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </section>
  );
}

function CountrySelect({
  value, onChange, locale,
}: { value: string; onChange: (v: string) => void; locale: 'ar' | 'en' }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const ref = useRef<HTMLDivElement>(null);

  const countries = useMemo(() => buildCountries(locale), [locale]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return countries;
    return countries.filter((c) => c.search.includes(q));
  }, [countries, query]);

  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  const selected = countries.find((c) => c.code === value);

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="w-full flex items-center justify-between gap-2 rounded-xl bg-white/10 border border-white/20 px-3 py-2 text-sm text-white focus:outline-none focus:ring-2 focus:ring-white/30"
      >
        <span className={selected ? '' : 'text-white/40'}>
          {selected ? selected.name : locale === 'ar' ? 'اختر الدولة...' : 'Select country...'}
        </span>
        <ChevronDown className={`h-4 w-4 text-white/50 transition-transform shrink-0 ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <div className="absolute z-40 mt-1.5 w-full rounded-xl bg-white shadow-2xl border border-slate-200 overflow-hidden" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
          <div className="p-2 border-b border-slate-100">
            <div className="relative">
              <Search className="absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
              <input
                autoFocus
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder={locale === 'ar' ? 'ابحث عن الدولة...' : 'Search country...'}
                className="w-full rounded-lg border border-slate-200 py-1.5 pr-9 pl-3 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500/40"
              />
            </div>
          </div>
          <ul className="max-h-56 overflow-y-auto py-1">
            {filtered.map((c) => (
              <li key={c.code}>
                <button
                  type="button"
                  onClick={() => { onChange(c.code); setOpen(false); setQuery(''); }}
                  className={`w-full text-start px-3 py-1.5 text-sm hover:bg-emerald-50 ${c.code === value ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-slate-800'}`}
                >
                  {c.name}
                </button>
              </li>
            ))}
            {filtered.length === 0 && (
              <li className="px-3 py-2 text-sm text-slate-400">
                {locale === 'ar' ? 'لا توجد نتائج' : 'No results'}
              </li>
            )}
          </ul>
        </div>
      )}
    </div>
  );
}