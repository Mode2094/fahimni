'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/empty-state';
import { TableSkeleton } from '@/components/ui/skeleton';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { HelpCircle, Plus, Trash2, Loader2, CheckCircle, XCircle } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function QuestionsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const [exams, setExams] = useState<any[]>([]);
  const [selectedExam, setSelectedExam] = useState('');
  const [questions, setQuestions] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ question_text: '', question_type: 'multiple_choice', points: '10', options: [{ option_text: '', is_correct: true }, { option_text: '', is_correct: false }, { option_text: '', is_correct: false }], correct_answer: 'true' });
  const [removingId, setRemovingId] = useState('');

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  useEffect(() => {
    if (!check()) return;
    fetch(`${API}/exams`, { headers: headers() }).then(r => r.json()).then(d => setExams(d.exams || [])).catch(() => {});
  }, [check()]);

  const loadQuestions = (examId: string) => {
    if (!examId) { setQuestions([]); return; }
    setLoading(true);
    fetch(`${API}/exams/${examId}/questions`, { headers: headers() })
      .then(r => r.json()).then(d => setQuestions(d.questions || [])).catch(() => setQuestions([]))
      .finally(() => setLoading(false));
  };

  const saveQuestion = async () => {
    if (!selectedExam) { toast.error(locale === 'ar' ? 'اختر اختباراً' : 'Select exam'); return; }
    try {
      const payload: any = { exam_id: selectedExam, question_text: form.question_text, question_type: form.question_type, points: Number(form.points) };
      if (form.question_type === 'true_false') payload.correct_answer = form.correct_answer;
      else payload.options = form.options.map((o: any) => ({ option_text: o.option_text, is_correct: !!o.is_correct }));
      const res = await fetch(`${API}/questions`, { method: 'POST', headers: headers(), body: JSON.stringify(payload) });
      if (!res.ok) { const d = await res.json(); toast.error(d.message || 'Failed'); return; }
      toast.success(locale === 'ar' ? 'تم إضافة السؤال' : 'Question added');
      setShowForm(false); loadQuestions(selectedExam);
    } catch { toast.error('Failed'); }
  };

  const removeQuestion = async (id: string) => {
    if (!window.confirm(locale === 'ar' ? 'حذف هذا السؤال؟' : 'Delete this question?')) return;
    setRemovingId(id);
    try {
      await fetch(`${API}/questions/${id}`, { method: 'DELETE', headers: headers() });
      toast.success(locale === 'ar' ? 'تم الحذف' : 'Deleted');
      loadQuestions(selectedExam);
    } catch { toast.error('Failed'); }
    setRemovingId('');
  };

  const setCorrect = (idx: number) => { const opts = form.options.map((o: any, i: number) => ({ ...o, is_correct: i === idx })); setForm({ ...form, options: opts }); };

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <HelpCircle className="h-6 w-6 text-teal-600" />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{locale === 'ar' ? 'أسئلة الاختبارات' : 'Exam Questions'}</h1>
            <p className="text-muted-foreground mt-1">{locale === 'ar' ? 'أدر أسئلة اختباراتك' : 'Manage exam questions'}</p>
          </div>
        </div>
        <Button onClick={() => setShowForm(true)} disabled={!selectedExam} className="gap-2 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}><Plus className="h-4 w-4" /> {locale === 'ar' ? 'سؤال جديد' : 'New Question'}</Button>
      </div>
      <div className="max-w-md">
        <Label className="text-xs font-semibold text-muted-foreground">{locale === 'ar' ? 'اختر الاختبار' : 'Select exam'}</Label>
        <Select value={selectedExam} onChange={(e) => { setSelectedExam(e.target.value); loadQuestions(e.target.value); }} className="h-11 w-full mt-1.5 border-teal-100 shadow-sm">
          <option value="">{locale === 'ar' ? '— اختر —' : '— Select —'}</option>
          {exams.map((e: any) => <option key={e.id} value={e.id}>{e.title}</option>)}
        </Select>
      </div>
      {loading ? <TableSkeleton rows={4} cols={3} /> :
        questions.length === 0 ? (
          <EmptyState icon={HelpCircle} title={locale === 'ar' ? 'لا توجد أسئلة' : 'No questions'} description={locale === 'ar' ? 'أضف أول سؤال لهذا الاختبار' : 'Add the first question for this exam'} />
        ) : (
          <Card><div className="divide-y">
            {questions.map((q, i) => (
              <div key={q.exam_question_id || q.id} className="p-4">
                <div className="flex items-start gap-3">
                  <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-50 text-teal-700 font-bold shrink-0">{i + 1}</div>
                  <div className="flex-1">
                    <p className="font-medium">{q.question_text}</p>
                    <div className="mt-2 space-y-1">{(q.options || []).map((o: any) => (
                      <div key={o.id} className="flex items-center gap-2 text-sm text-gray-600">
                        {o.is_correct ? <CheckCircle className="h-3.5 w-3.5 text-green-500" /> : <XCircle className="h-3.5 w-3.5 text-gray-300" />}{o.option_text}
                      </div>
                    ))}</div>
                    <div className="flex gap-2 mt-2"><Badge variant="outline" className="text-[10px]">{q.question_type}</Badge><span className="text-xs text-gray-400">{q.points} pts</span></div>
                  </div>
                  <Button variant="ghost" size="icon" className="text-red-500" onClick={() => removeQuestion(q.id)} disabled={removingId === q.id} title={locale === 'ar' ? 'حذف' : 'Delete'} aria-label={locale === 'ar' ? 'حذف' : 'Delete'}><Trash2 className="h-4 w-4" /></Button>
                </div>
              </div>
            ))}
          </div></Card>
        )}
      <Dialog open={showForm} onOpenChange={setShowForm}>
        <DialogContent className="max-h-[85vh] overflow-y-auto"><DialogHeader><DialogTitle>{locale === 'ar' ? 'سؤال جديد' : 'New Question'}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2"><Label>{locale === 'ar' ? 'النوع' : 'Type'}</Label>
                <Select value={form.question_type} onChange={(e) => setForm({ ...form, question_type: e.target.value })} className="h-11">
                  <option value="multiple_choice">{locale === 'ar' ? 'اختيار من متعدد' : 'MCQ'}</option><option value="true_false">{locale === 'ar' ? 'صح/خطأ' : 'True/False'}</option>
                </Select>
              </div>
              <div className="space-y-2"><Label>{locale === 'ar' ? 'النقاط' : 'Points'}</Label><Input type="number" value={form.points} onChange={(e) => setForm({ ...form, points: e.target.value })} className="h-11" /></div>
            </div>
            <div className="space-y-2"><Label>{locale === 'ar' ? 'نص السؤال' : 'Question text'}</Label><Input value={form.question_text} onChange={(e) => setForm({ ...form, question_text: e.target.value })} className="h-11" /></div>
            {form.question_type === 'true_false' ? (
              <div className="space-y-2"><Label>{locale === 'ar' ? 'الإجابة الصحيحة' : 'Correct answer'}</Label>
                <div className="flex gap-2">{['true', 'false'].map(v => (
                  <button key={v} onClick={() => setForm({ ...form, correct_answer: v })} className={`flex-1 py-2.5 rounded-lg border text-sm ${form.correct_answer === v ? 'bg-green-500 text-white border-green-500' : 'bg-white text-gray-600'}`}>{v === 'true' ? (locale === 'ar' ? 'صح' : 'True') : (locale === 'ar' ? 'خطأ' : 'False')}</button>
                ))}</div>
              </div>
            ) : (
              <div className="space-y-3"><Label>{locale === 'ar' ? 'الخيارات' : 'Options'}</Label>
                {form.options.map((o: any, i: number) => (
                  <div key={i} className="flex items-center gap-2">
                    <button onClick={() => setCorrect(i)} aria-label={`${locale === 'ar' ? 'الخيار الصحيح' : 'Correct option'} ${i + 1}`} className={`h-5 w-5 rounded-full border-2 shrink-0 ${o.is_correct ? 'border-green-500 bg-green-500' : 'border-gray-300'}`} />
                    <Input value={o.option_text} onChange={(e) => { const opts = [...form.options]; opts[i] = { ...opts[i], option_text: e.target.value }; setForm({ ...form, options: opts }); }} className="h-10" />
                  </div>
                ))}
                <Button variant="outline" size="sm" onClick={() => setForm({ ...form, options: [...form.options, { option_text: '', is_correct: false }] })} className="gap-1 rounded-full"><Plus className="h-3.5 w-3.5" /> {locale === 'ar' ? 'خيار' : 'Option'}</Button>
              </div>
            )}
          </div>
          <DialogFooter><Button variant="outline" onClick={() => setShowForm(false)}>{locale === 'ar' ? 'إلغاء' : 'Cancel'}</Button><Button onClick={saveQuestion}>{locale === 'ar' ? 'حفظ' : 'Save'}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
