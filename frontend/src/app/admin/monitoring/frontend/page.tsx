'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Bug, Loader2 } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminFrontendErrorsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [errors, setErrors] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  const headers = () => ({ 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' });

  useEffect(() => {
    if (!check()) return;
    setLoading(true);
    fetch(`${API}/admin/monitoring/frontend-errors?limit=50`, { headers: headers() })
      .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
      .then((d) => setErrors(d.errors || []))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold flex items-center gap-2">
        <Bug className="h-5 w-5" /> {t('أخطاء الواجهة', 'Frontend Errors')}
      </h1>

      {loading ? (
        <div className="flex justify-center py-16"><Loader2 className="h-8 w-8 animate-spin text-muted-foreground" /></div>
      ) : errors.length === 0 ? (
        <Card><CardContent className="text-sm text-muted-foreground">لا توجد أخطاء مسجلة.</CardContent></Card>
      ) : (
        <div className="space-y-2">
          {errors.map((e, i) => (
            <Card key={e.id ?? i}>
              <CardContent className="flex flex-wrap items-start gap-3">
                <Badge variant={e.status && e.status >= 500 ? 'default' : 'secondary'}>{e.type}</Badge>
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium break-words">{e.message || '(no message)'}</p>
                  <p className="text-xs text-muted-foreground truncate mt-0.5" dir="ltr">{e.url || ''}</p>
                </div>
                {e.status && <Badge variant="outline">{e.status}</Badge>}
                <span className="text-xs text-muted-foreground">{e.created_at ? new Date(e.created_at).toLocaleString() : ''}</span>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}