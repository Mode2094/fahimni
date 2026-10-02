'use client';

import { useEffect, useState } from 'react';
import { use } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { BookOpen, Clock, Loader2, ArrowLeft } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function TeacherCoursesPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = use(params);
  const { locale } = useLocale();
  const [courses, setCourses] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetch(`${API}/public/${slug}/courses`).then(r => r.json()).then(d => setCourses(d.courses || [])).catch(() => {}).finally(() => setLoading(false));
  }, [slug]);

  return (
    <div className="min-h-screen bg-gradient-to-b from-slate-50 to-white">
      <div className="max-w-7xl mx-auto px-4 py-12">
        <a href={`/t/${slug}`} className="inline-flex items-center gap-1 text-sm text-gray-500 mb-8"><ArrowLeft className="h-4 w-4" /> {locale === 'ar' ? 'الرئيسية' : 'Home'}</a>
        <h1 className="text-3xl font-bold mb-8">{locale === 'ar' ? 'جميع الكورسات' : 'All Courses'}</h1>
        {loading ? <div className="flex justify-center py-16"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div> :
          courses.length === 0 ? (
            <Card><CardContent className="text-center py-12 text-gray-400"><BookOpen className="h-12 w-12 mx-auto mb-4 opacity-40" /><p>{locale === 'ar' ? 'لا توجد كورسات' : 'No courses'}</p></CardContent></Card>
          ) : (
            <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
              {courses.map((c) => (
                <Card key={c.id} className="border-0 shadow-sm overflow-hidden card-hover">
                  <div className="h-40 bg-gradient-to-br from-teal-500/10 to-teal-500/5 flex items-center justify-center"><BookOpen className="h-14 w-14 text-teal-400/50" /></div>
                  <CardContent className="p-5">
                    <h3 className="font-semibold">{c.title}</h3>
                    <p className="text-sm text-gray-500 mt-1 line-clamp-2">{c.short_description || c.description}</p>
                    <div className="flex items-center gap-4 mt-3 text-xs text-gray-500"><span className="flex items-center gap-1"><Clock className="h-3 w-3" /> {c.duration_hours || 0} {locale === 'ar' ? 'س' : 'h'}</span></div>
                    <a href={`/t/${slug}/register?course=${c.id}`}><Button className="w-full rounded-full mt-4" style={{ background: '#0d9488' }}>{locale === 'ar' ? 'سجل الآن' : 'Enroll'}</Button></a>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
      </div>
    </div>
  );
}
