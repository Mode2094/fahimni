'use client';

import { useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { CardSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Trophy, Medal, Star, ArrowLeft, Coins, Crown } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';


export default function LeaderboardPage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [rows, setRows] = useState<any[]>([]);
  const [myRank, setMyRank] = useState<number | null>(null);
  const [mode, setMode] = useState<string>('named');
  const [enabled, setEnabled] = useState<boolean>(true);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const tid = localStorage.getItem('tenant_id');
    const h = { Accept: 'application/json', ...(tid ? { 'X-Tenant-Id': tid } : {}) };

    fetch(`${API}/student/leaderboard`, { headers: h })
      .then(r => r.json())
      .then((lb) => {
        setRows(lb.leaderboard || []);
        setMyRank(lb.my_rank);
        // Older servers omit these fields: default to the classic behaviour.
        setMode(lb.mode || 'named');
        setEnabled(lb.enabled !== false);
      })
      .catch(() => {}).finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="px-4 py-8"><CardSkeleton count={5} /></div>;

  const anonymous = mode === 'anonymous';

  if (!enabled) {
    return (
      <div className="space-y-6 max-w-4xl mx-auto">
        <a href="/my-learning" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-teal-700 transition-colors"><ArrowLeft className="h-4 w-4" /> {t('رجوع لتعليمي', 'Back to my learning')}</a>
        <EmptyState icon={Trophy} title={t('ترتيب الطلاب مُعطّل حالياً', 'The leaderboard is currently disabled')} description={t('تواصل مع معلّمك إن أردت تفعيله.', 'Contact your teacher to enable it.')} />
      </div>
    );
  }

  const top3 = rows.slice(0, 3);
  const podiumOrder = [top3[1], top3[0], top3[2]].filter(Boolean);
  const rest = rows.slice(3);

  const rankStyle = (r: number) =>
    r === 1 ? 'bg-amber-100 text-amber-700' : r === 2 ? 'bg-gray-200 text-gray-600' : r === 3 ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-500';

  return (
    <div className="space-y-6 max-w-4xl mx-auto">
      <a href="/my-learning" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-teal-700 transition-colors"><ArrowLeft className="h-4 w-4" /> {t('رجوع لتعليمي', 'Back to my learning')}</a>

      <div className="space-y-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <Trophy className="h-6 w-6 text-teal-600" />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('ترتيب الطلاب', 'Student Leaderboard')}</h1>
            <p className="text-muted-foreground mt-1">{t('نقاطك تكسبك مركزاً متقدماً — أكمل الدروس والاختبارات', 'Earn points by completing lessons and exams')}</p>
          </div>
        </div>
        {myRank && <Badge className="bg-teal-100 text-teal-700 border-0 px-4 py-1.5 gap-1.5 rounded-full"><Crown className="h-3.5 w-3.5" />{t('ترتيبك', 'Your rank')}: #{myRank}</Badge>}
        {anonymous && (
          <p className="text-xs text-muted-foreground">{t('يظهر الترتيب بأسماء مخفية حفاظاً على خصوصية الطلاب.', 'Other students appear anonymously to protect privacy.')}</p>
        )}
      </div>

      {rows.length === 0 ? (
        <EmptyState icon={Trophy} title={t('لا يوجد ترتيب بعد — كن أول من يكسب النقاط!', 'No ranking yet — be the first to earn points!')} />
      ) : (
        <>
          {/* Podium */}
          <div className="grid grid-cols-3 gap-3 items-end">
            {podiumOrder.map((l: any) => (
              <div key={l.rank} className={`text-center ${l.rank === 1 ? 'order-2' : l.rank === 2 ? 'order-1' : 'order-3'}`}>
                <div className={`relative mx-auto flex h-16 w-16 sm:h-20 sm:w-20 items-center justify-center rounded-full text-white font-bold text-xl ${l.rank === 1 ? 'bg-gradient-to-br from-amber-400 to-orange-500 h-20 w-20 sm:h-24 sm:w-24' : l.rank === 2 ? 'bg-gradient-to-br from-gray-300 to-gray-400' : 'bg-gradient-to-br from-orange-300 to-orange-500'}`}>
                  {l.name?.[0]?.toUpperCase()}
                  {l.rank === 1 && <Star className="absolute -top-2 -end-2 h-6 w-6 text-amber-400 fill-amber-400" />}
                </div>
                <div className={`mt-2 rounded-xl p-2 border ${l.is_me ? 'border-teal-300 bg-teal-50' : ''}`}>
                  <p className="text-xs sm:text-sm font-bold truncate">{l.name}</p>
                  <p className="text-[11px] text-gray-500 flex items-center justify-center gap-1"><Coins className="h-3 w-3 text-amber-500" />{l.points}</p>
                </div>
                <div className={`mx-auto -mt-0.5 h-6 w-full rounded-t-lg ${l.rank === 1 ? 'bg-amber-400/80' : l.rank === 2 ? 'bg-gray-300/80' : 'bg-orange-400/80'}`} />
              </div>
            ))}
          </div>

          {/* Table */}
          <Card className="border-0 shadow-sm"><CardContent className="p-4">
            <div className="space-y-1.5">
              {rest.map((l: any) => (
                <div key={l.rank} className={`flex items-center gap-3 p-3 rounded-xl ${l.is_me ? 'bg-teal-50 border border-teal-200' : 'hover:bg-gray-50'}`}>
                  <span className={`flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold shrink-0 ${rankStyle(l.rank)}`}>{l.rank}</span>
                  <div className="flex-1 flex items-center gap-2 min-w-0">
                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-teal-500 to-emerald-400 text-white text-xs font-bold shrink-0">{l.name?.[0]?.toUpperCase()}</div>
                    <div className="min-w-0">
                      <p className="text-sm font-medium truncate">{l.name}{l.is_me && <span className="text-teal-700"> ({t('أنا', 'me')})</span>}</p>
                      <p className="text-[11px] text-gray-400">{l.courses_count} {t('كورس', 'courses')} · {l.courses_completed} {t('مكتمل', 'completed')}</p>
                    </div>
                  </div>
                  <span className="flex items-center gap-1 text-sm font-bold text-amber-600 shrink-0"><Coins className="h-4 w-4" />{l.points}</span>
                </div>
              ))}
              {rows.length <= 3 && <p className="text-center text-sm text-gray-400 py-6">{t('مزيد من الطلاب سينضمون للترتيب قريباً', 'More students will join the ranking soon')}</p>}
            </div>
          </CardContent></Card>
        </>
      )}

      <Card className="border-0 shadow-sm bg-gradient-to-l from-teal-600 to-teal-500"><CardContent className="p-5 text-white">
        <div className="flex items-center gap-3">
          <Medal className="h-8 w-8 shrink-0" />
          <div>
            <h3 className="font-bold">{t('كيف تكسب النقاط؟', 'How to earn points?')}</h3>
            <ul className="text-sm text-white/80 mt-1 grid sm:grid-cols-2 gap-x-6 gap-y-0.5 list-disc list-inside">
              <li>{t('تسجيل دخول يومي', 'Daily login')}: +5</li>
              <li>{t('إكمال درس', 'Complete a lesson')}: +10</li>
              <li>{t('اجتياز اختبار', 'Pass an exam')}: +50</li>
              <li>{t('إكمال كورس', 'Complete a course')}: +100</li>
            </ul>
          </div>
        </div>
      </CardContent></Card>
    </div>
  );
}
