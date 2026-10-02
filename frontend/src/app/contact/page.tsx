'use client';

import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { useState } from 'react';
import { ArrowRight, ArrowLeft, Mail, Phone, MessageCircle, Clock, ShieldCheck, Send, Loader2, CheckCircle2, MapPin } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function ContactPage() {
  const { locale } = useLocale();
  const { settings } = useSettings();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const platform = settings.platform_name || 'Tadreesly';

  const [form, setForm] = useState({ name: '', email: '', subject: '', message: '' });
  const [load, setLoad] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState('');

  const submit = async () => {
    setError('');
    if (!form.name.trim() || !form.email.trim() || !form.message.trim()) {
      setError(t('يرجى تعبئة الاسم والبريد والرسالة', 'Please fill in name, email and message'));
      return;
    }
    setLoad(true);
    try {
      const res = await fetch(`${API}/public/contact`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(form),
      });
      const d = await res.json();
      if (!res.ok) throw new Error(d.message || 'error');
      setSent(true);
    } catch (e: any) {
      setError(e.message || t('تعذر إرسال الرسالة، حاول لاحقًا', 'Failed to send message, try again later'));
    } finally {
      setLoad(false);
    }
  };

  const email = settings.contact_email || 'support@tadreesly.com';
  const whatsapp = settings.contact_whatsapp || '';

  return (
    <div className="min-h-screen bg-slate-50" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
      <header className="bg-slate-900 text-white">
        <div className="max-w-3xl mx-auto px-4 py-12">
          <a href="/" className="inline-flex items-center gap-1.5 text-sm text-white/60 hover:text-white transition-colors mb-6">
            {locale === 'ar' ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />}
            {t('العودة للرئيسية', 'Back to home')}
          </a>
          <div className="flex items-center gap-4">
            <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-600 shrink-0"><Mail className="h-6 w-6 text-white" /></span>
            <div>
              <h1 className="text-2xl sm:text-3xl font-bold">{t('اتصل بنا', 'Contact Us')}</h1>
              <p className="text-white/60 text-sm mt-1">{t('نحن هنا لمساعدتك — فريق الدعم يرد خلال ساعة', 'We are here to help — support replies within an hour')}</p>
            </div>
          </div>
        </div>
      </header>

      <main className="max-w-3xl mx-auto px-4 py-10">
        <div className="grid sm:grid-cols-3 gap-4 mb-8">
          <div className="bg-white rounded-2xl border border-slate-200 p-5 text-center">
            <Mail className="h-6 w-6 text-teal-600 mx-auto mb-2" />
            <p className="text-xs text-slate-400">{t('البريد الإلكتروني', 'Email')}</p>
            <p dir="ltr" className="text-sm font-semibold text-slate-800 mt-1">{email}</p>
          </div>
          <div className="bg-white rounded-2xl border border-slate-200 p-5 text-center">
            <Clock className="h-6 w-6 text-teal-600 mx-auto mb-2" />
            <p className="text-xs text-slate-400">{t('ساعات العمل', 'Working hours')}</p>
            <p className="text-sm font-semibold text-slate-800 mt-1">{t('24/7 دعم فني', '24/7 support')}</p>
          </div>
          <div className="bg-white rounded-2xl border border-slate-200 p-5 text-center">
            <ShieldCheck className="h-6 w-6 text-emerald-600 mx-auto mb-2" />
            <p className="text-xs text-slate-400">{t('مدة الرد', 'Response time')}</p>
            <p className="text-sm font-semibold text-slate-800 mt-1">{t('خلال ساعة', 'Within an hour')}</p>
          </div>
        </div>

        <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
          <div className="p-6 sm:p-8">
            <h2 className="text-lg font-bold mb-5">{t('أرسل رسالتك', 'Send us a message')}</h2>

            {sent ? (
              <div className="text-center py-10">
                <CheckCircle2 className="h-14 w-14 text-emerald-500 mx-auto mb-4" />
                <p className="text-lg font-bold text-slate-800">{t('تم إرسال رسالتك بنجاح', 'Your message was sent successfully')}</p>
                <p className="text-sm text-slate-500 mt-2">{t('سنتواصل معك عبر بريدك الإلكتروني في أقرب وقت', 'We will contact you via your email as soon as possible')}</p>
                <button onClick={() => { setSent(false); setForm({ name: '', email: '', subject: '', message: '' }); }} className="mt-6 text-sm font-semibold text-teal-700 hover:text-teal-800">
                  {t('إرسال رسالة أخرى', 'Send another message')}
                </button>
              </div>
            ) : (
              <div className="space-y-4">
                <div className="grid sm:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1.5">{t('الاسم', 'Name')} *</label>
                    <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="w-full h-11 px-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-teal-600" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1.5">{t('البريد الإلكتروني', 'Email')} *</label>
                    <input dir="ltr" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="w-full h-11 px-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-teal-600" />
                  </div>
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">{t('الموضوع', 'Subject')}</label>
                  <input value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} className="w-full h-11 px-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-teal-600" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">{t('رسالتك', 'Your message')} *</label>
                  <textarea rows={6} value={form.message} onChange={(e) => setForm({ ...form, message: e.target.value })} className="w-full px-3 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-teal-600" />
                </div>
                {error && <p className="text-sm text-red-600">{error}</p>}
                <button onClick={submit} disabled={load} className="inline-flex items-center gap-2 h-11 px-6 rounded-xl text-sm font-semibold text-white bg-teal-600 hover:bg-teal-700 disabled:opacity-50 transition-colors">
                  {load ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                  {load ? t('جاري الإرسال...', 'Sending...') : t('إرسال الرسالة', 'Send message')}
                </button>
              </div>
            )}
          </div>
        </div>

        {whatsapp && (
          <div className="mt-6 bg-white rounded-2xl border border-slate-200 p-5 flex items-center justify-between gap-4">
            <div className="flex items-center gap-3">
              <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-green-50"><MessageCircle className="h-5 w-5 text-green-600" /></span>
              <div>
                <p className="text-sm font-semibold text-slate-800">{t('تواصل مباشر عبر واتساب', 'Chat directly on WhatsApp')}</p>
                <p className="text-xs text-slate-400">{t('الرد السريع على استفساراتك', 'Quick replies to your questions')}</p>
              </div>
            </div>
            <a href={`https://wa.me/${whatsapp}`} className="inline-flex items-center gap-2 h-10 px-4 rounded-xl text-sm font-semibold text-white bg-green-600 hover:bg-green-700 transition-colors"><MessageCircle className="h-4 w-4" />{t('فتح المحادثة', 'Open chat')}</a>
          </div>
        )}

        <p className="text-center text-xs text-slate-400 mt-8">
          © {new Date().getFullYear()} {platform} · {t('جميع الحقوق محفوظة', 'All rights reserved')}
        </p>
      </main>
    </div>
  );
}