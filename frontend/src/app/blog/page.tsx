'use client';

import { useEffect, useRef, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select } from '@/components/ui/select';
import { CardSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Newspaper, Plus, Loader2, Pencil, Trash2, Eye, EyeOff, Upload, X, Calendar } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function BlogPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [posts, setPosts] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<any>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<any>({ title: '', excerpt: '', content: '', cover_image: '', status: 'draft' });
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [deletingId, setDeletingId] = useState<string | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = () => {
    fetch(`${API}/teacher/blog`, { headers: headers() })
      .then(r => r.json()).then(d => setPosts(d.posts || [])).catch(() => {})
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (check()) load(); }, [check()]);

  const openAdd = () => { setEditing(null); setForm({ title: '', excerpt: '', content: '', cover_image: '', status: 'draft' }); setShowForm(true); };
  const openEdit = (p: any) => { setEditing(p); setForm({ title: p.title || '', excerpt: p.excerpt || '', content: p.content || '', cover_image: p.cover_image || '', status: p.status || 'draft' }); setShowForm(true); };

  const save = async () => {
    if (!form.title.trim() || !form.content.trim()) { toast.error(t('أدخل العنوان والمحتوى', 'Enter title and content')); return; }
    setSaving(true);
    try {
      const res = await fetch(`${API}/teacher/blog${editing ? `/${editing.id}` : ''}`, {
        method: editing ? 'PUT' : 'POST', headers: headers(),
        body: JSON.stringify(form),
      });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(editing ? t('تم تحديث المقال', 'Post updated') : t('تم إنشاء المقال', 'Post created'));
      setShowForm(false); load();
    } catch { toast.error('Failed'); }
    setSaving(false);
  };

  const togglePublish = async (p: any) => {
    try {
      const res = await fetch(`${API}/teacher/blog/${p.id}/${p.status === 'published' ? 'unpublish' : 'publish'}`, { method: 'POST', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(p.status === 'published' ? t('تم الإخفاء', 'Unpublished') : t('تم النشر — يظهر في صفحتك', 'Published — live on your page'));
      load();
    } catch { toast.error('Failed'); }
  };

  const remove = async (p: any) => {
    if (!confirm(t('حذف هذا المقال نهائياً؟', 'Delete this post permanently?'))) return;
    setDeletingId(p.id);
    try {
      const res = await fetch(`${API}/teacher/blog/${p.id}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم الحذف', 'Deleted')); load();
    } catch { toast.error('Failed'); }
    setDeletingId(null);
  };

  const uploadCover = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      const res = await fetch(`${API}/upload`, { method: 'POST', headers: { 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' }, body: fd });
      if (!res.ok) throw new Error();
      const d = await res.json();
      setForm({ ...form, cover_image: d.url });
    } catch { toast.error('Failed'); }
    setUploading(false);
    if (fileRef.current) fileRef.current.value = '';
  };

  const siteUrl = '';

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <Newspaper className="h-6 w-6 text-teal-600" />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('المدونة', 'Blog')}</h1>
            <p className="text-muted-foreground mt-1">{t('مقالاتك تظهر في موقعك العام وتقوّي علامتك', 'Your posts appear on your public site and build your brand')}</p>
          </div>
        </div>
        <Button onClick={openAdd} className="gap-2 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}><Plus className="h-4 w-4" /> {t('مقال جديد', 'New post')}</Button>
      </div>

      {loading ? <CardSkeleton /> :
        posts.length === 0 ? (
          <EmptyState icon={Newspaper} title={t('لا توجد مقالات بعد', 'No posts yet')} description={t('اكتب أول مقال يظهر في مدونتك', 'Write your first post for your blog')} action={<Button onClick={openAdd} className="mt-2 gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('مقال جديد', 'New post')}</Button>} />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {posts.map((p) => (
              <Card key={p.id} className="border-0 shadow-sm card-hover overflow-hidden flex flex-col">
                {p.cover_image ? <img src={p.cover_image} className="h-36 w-full object-cover" alt="" /> :
                  <div className="h-36 bg-gradient-to-br from-teal-500/15 to-teal-500/10 flex items-center justify-center"><Newspaper className="h-10 w-10 text-teal-400/50" /></div>}
                <CardContent className="p-5 flex-1 flex flex-col">
                  <div className="flex items-center gap-2 mb-2">
                    <Badge className={p.status === 'published' ? 'bg-green-100 text-green-700 border-0 text-[10px]' : 'bg-gray-100 text-gray-500 border-0 text-[10px]'}>{p.status === 'published' ? t('منشور', 'Published') : t('مسودة', 'Draft')}</Badge>
                    {p.category && <Badge variant="outline" className="text-[10px]">{p.category}</Badge>}
                  </div>
                  <h3 className="font-semibold line-clamp-2">{p.title}</h3>
                  <p className="text-xs text-gray-400 mt-1 line-clamp-2 flex-1">{p.excerpt || p.content.slice(0, 80)}</p>
                  <div className="flex items-center justify-between gap-2 mt-4 pt-3 border-t flex-wrap">
                    <span className="flex items-center gap-1 text-[11px] text-gray-400"><Calendar className="h-3 w-3" />{p.published_at || p.created_at ? new Date(p.published_at || p.created_at).toLocaleDateString() : ''}</span>
                    <div className="flex gap-1">
                      <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => togglePublish(p)} disabled={!!deletingId} title={p.status === 'published' ? t('إخفاء', 'Unpublish') : t('نشر', 'Publish')}>
                        {p.status === 'published' ? <EyeOff className="h-3.5 w-3.5 text-gray-400" /> : <Eye className="h-3.5 w-3.5 text-green-600" />}
                      </Button>
                      <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => openEdit(p)} disabled={!!deletingId} aria-label={t('تعديل', 'Edit')} title={t('تعديل', 'Edit')}><Pencil className="h-3.5 w-3.5 text-teal-700" /></Button>
                      <Button variant="ghost" size="icon" className="h-8 w-8 text-red-500" onClick={() => remove(p)} disabled={deletingId === p.id} aria-label={t('حذف', 'Delete')} title={t('حذف', 'Delete')}>
                        {deletingId === p.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Trash2 className="h-3.5 w-3.5" />}
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

      <Dialog open={showForm} onOpenChange={setShowForm} className="max-w-2xl">
        <DialogContent><DialogHeader><DialogTitle>{editing ? t('تعديل المقال', 'Edit post') : t('مقال جديد', 'New post')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2"><Label>{t('العنوان', 'Title')} *</Label><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className="h-11" /></div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-2"><Label>{t('التصنيف', 'Category')}</Label><Input value={form.category || ''} onChange={(e) => setForm({ ...form, category: e.target.value })} className="h-11" placeholder={t('مثال: برمجة', 'e.g. Programming')} /></div>
              <div className="space-y-2"><Label>{t('الحالة', 'Status')}</Label>
                <Select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} className="h-11 border-gray-200">
                  <option value="draft">{t('مسودة', 'Draft')}</option>
                  <option value="published">{t('منشور فوراً', 'Publish now')}</option>
                </Select>
              </div>
            </div>
            <div className="space-y-2"><Label>{t('ملخص قصير', 'Excerpt')}</Label><Input value={form.excerpt} onChange={(e) => setForm({ ...form, excerpt: e.target.value })} className="h-11" /></div>
            <div className="space-y-2">
              <Label>{t('صورة الغلاف', 'Cover image')}</Label>
              <div className="flex items-center gap-3">
                {form.cover_image ? <img src={form.cover_image} className="h-14 w-24 rounded-xl border object-cover" alt="" /> : <div className="flex h-14 w-24 items-center justify-center rounded-xl bg-gray-100 border-2 border-dashed"><Upload className="h-5 w-5 text-gray-300" /></div>}
                <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={uploadCover} />
                <Button variant="outline" size="sm" onClick={() => fileRef.current?.click()} disabled={uploading} className="gap-1.5 rounded-full">{uploading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Upload className="h-3.5 w-3.5" />}{t('رفع', 'Upload')}</Button>
                {form.cover_image && <Button variant="ghost" size="sm" className="text-red-400" onClick={() => setForm({ ...form, cover_image: '' })}><X className="h-4 w-4" /></Button>}
              </div>
            </div>
            <div className="space-y-2"><Label>{t('المحتوى', 'Content')} *</Label><Textarea value={form.content} onChange={(e) => setForm({ ...form, content: e.target.value })} className="min-h-[180px]" /></div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowForm(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={save} disabled={saving} className="gap-2 rounded-full">{saving && <Loader2 className="h-4 w-4 animate-spin" />}{editing ? t('حفظ', 'Save') : t('إنشاء', 'Create')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
