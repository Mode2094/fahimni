'use client';

import { useEffect, useState, useRef, useCallback } from 'react';
import { useLocale } from '@/store/locale';
import { useAuth } from '@/store';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { MessageSquare, Send, Loader2, Users, BellRing } from 'lucide-react';
import { toast } from 'sonner';
import { chatApi } from '@/services/endpoints';

export default function MessagesPage() {
  const { locale } = useLocale();
  const { user } = useAuth();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const isTeacher = user?.roles?.includes('Teacher');
  const isStudent = !user?.is_super_admin && !isTeacher;

  const [conversations, setConversations] = useState<any[]>([]);
  const [active, setActive] = useState<any>(null);
  const [messages, setMessages] = useState<any[]>([]);
  const [contacts, setContacts] = useState<any[]>([]);
  const [draft, setDraft] = useState('');
  const [loading, setLoading] = useState(true);
  const [sending, setSending] = useState(false);
  const [contactsOpen, setContactsOpen] = useState(false);
  const [starting, setStarting] = useState(false);
  const [unreadTotal, setUnreadTotal] = useState(0);
  const bottomRef = useRef<HTMLDivElement>(null);

  const loadConversations = useCallback(() => {
    chatApi.conversations().then((list) => {
      setConversations(list);
      const total = list.reduce((s, c) => s + (c.unread_count || 0), 0);
      setUnreadTotal(total);
    }).catch(() => {});
  }, []);

  useEffect(() => {
    loadConversations();
    chatApi.contacts().then(setContacts).catch(() => {});
    setLoading(false);
  }, [loadConversations]);

  useEffect(() => {
    const interval = setInterval(() => {
      loadConversations();
      if (active) {
        chatApi.messages(active.id).then((ms) => setMessages([...ms].reverse())).catch(() => {});
      }
    }, 5000);
    return () => clearInterval(interval);
  }, [active, loadConversations]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages.length, active?.id]);

  const openConversation = async (conv: any) => {
    setActive(conv);
    try {
      const ms = await chatApi.messages(conv.id);
      setMessages([...ms].reverse());
      if (conv.unread_count > 0) {
        await chatApi.markRead(conv.id);
        loadConversations();
      }
    } catch { /* */ }
  };

  const startConversation = async (userId: string) => {
    setStarting(true);
    try {
      await chatApi.start(userId);
      setContactsOpen(false);
      setContacts((prev) => prev.filter((c) => c.id !== userId));
      const list = await chatApi.conversations();
      setConversations(list);
      const conv = list.find((c: any) => c.other_user?.id === userId) || list[0];
      if (conv) await openConversation(conv);
      toast.success(t('بدأت المحادثة', 'Conversation started'));
    } catch (e: any) {
      toast.error(e?.response?.data?.message || t('فشل بدء المحادثة', 'Could not start conversation'));
    }
    setStarting(false);
  };

  const sendMessage = async () => {
    if (!active || !draft.trim() || sending) return;
    const body = draft.trim();
    setDraft('');
    setSending(true);
    const optimistic = { id: `tmp-${Date.now()}`, conversation_id: active.id, sender_id: 'me', body, created_at: new Date().toISOString() };
    setMessages((prev) => [...prev, optimistic]);
    try {
      const sent = await chatApi.send(active.id, body);
      setMessages((prev) => prev.map((m) => (m.id === optimistic.id ? sent : m)));
      loadConversations();
    } catch {
      toast.error(t('فشل الإرسال', 'Send failed'));
      setMessages((prev) => prev.filter((m) => m.id !== optimistic.id));
    }
    setSending(false);
  };

  const isMine = (m: any) => m.sender_id === 'me' || m.sender_id === JSON.parse(localStorage.getItem('user') || '{}')?.id;

  return (
    <div className="space-y-6 max-w-5xl mx-auto">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-violet-500/10"><MessageSquare className="h-5 w-5 text-violet-500" /></div>
          <div>
            <h1 className="text-xl sm:text-2xl font-bold">{t('الرسائل', 'Messages')}</h1>
            <p className="text-gray-500 text-sm">
              {isTeacher ? t('تواصل مباشر مع طلابك', 'Chat directly with your students')
                : isStudent ? t('تواصل مباشر مع معلمك', 'Chat directly with your teacher')
                : t('تواصل مباشر', 'Direct messaging')}
            </p>
          </div>
        </div>
        <Dialog open={contactsOpen} onOpenChange={setContactsOpen}>
          <DialogTrigger asChild>
            <Button className="rounded-full text-white shadow-lg" style={{ background: 'linear-gradient(135deg, #7c3aed, #8b5cf6)' }}><Users className="h-4 w-4 ms-1" />{t('محادثة جديدة', 'New chat')}</Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{t('اختر جهة اتصال', 'Choose a contact')}</DialogTitle>
            </DialogHeader>
            <div className="space-y-2 max-h-80 overflow-y-auto">
              {contacts.length === 0 && <p className="text-sm text-gray-400 py-6 text-center">{t('لا توجد جهات اتصال متاحة', 'No available contacts')}</p>}
              {contacts.map((c) => (
                <button key={c.id} onClick={() => startConversation(c.id)} disabled={starting} className="w-full flex items-center gap-3 p-3 rounded-xl border border-gray-200 hover:border-violet-300 hover:bg-violet-50/50 transition-colors text-start">
                  <div className="flex h-10 w-10 items-center justify-center rounded-full bg-violet-100 text-violet-700 font-bold">{c.name?.[0] || '?'}</div>
                  <div className="flex-1 min-w-0">
                    <p className="font-semibold text-sm truncate">{c.name}</p>
                    <p className="text-xs text-gray-400">{c.role === 'student' ? t('طالب', 'Student') : c.role === 'teacher' ? t('معلم', 'Teacher') : t('إدارة', 'Admin')}</p>
                  </div>
                </button>
              ))}
            </div>
          </DialogContent>
        </Dialog>
      </div>

      <Card className="overflow-hidden">
        <div className="flex flex-col md:flex-row" style={{ minHeight: '560px' }}>
          <div className={`md:w-72 shrink-0 border-b md:border-b-0 md:border-l border-gray-100 bg-gray-50/50 ${active ? 'hidden md:block' : ''}`}>
            <div className="p-3 border-b border-gray-100 flex items-center justify-between">
              <span className="text-sm font-semibold text-gray-600">{t('المحادثات', 'Conversations')}</span>
              {unreadTotal > 0 && <Badge className="bg-violet-500">{unreadTotal}</Badge>}
            </div>
            <div className="overflow-y-auto" style={{ maxHeight: '500px' }}>
              {loading ? (
                <div className="flex justify-center py-10"><Loader2 className="h-5 w-5 animate-spin text-gray-300" /></div>
              ) : conversations.length === 0 ? (
                <div className="text-center py-10 text-gray-400 text-sm">
                  <BellRing className="h-8 w-8 mx-auto mb-2 opacity-40" />
                  <p>
                    {isTeacher ? t('ابدأ محادثة مع طلابك', 'Start a chat with your students')
                      : isStudent ? t('ابدأ محادثة مع معلمك', 'Start a chat with your teacher')
                      : t('ابدأ محادثة', 'Start a conversation')}
                  </p>
                </div>
              ) : (
                conversations.map((c) => (
                  <button key={c.id} onClick={() => openConversation(c)} className={`w-full text-start p-3 border-b border-gray-50 transition-all flex items-start gap-3 relative ${active?.id === c.id ? 'bg-white shadow-sm border-s-2 border-s-teal-500' : 'hover:bg-white'}`}>
                    <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-white font-bold text-sm ${active?.id === c.id ? 'bg-gradient-to-br from-teal-600 to-emerald-500' : 'bg-gradient-to-br from-teal-500 to-teal-600'}`}>{c.other_user?.name?.[0] || '?'}</div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between gap-2">
                        <p className="font-semibold text-sm truncate">{c.other_user?.name || t('مستخدم', 'User')}</p>
                        {c.last_message_at && <span className="text-[10px] text-gray-400 shrink-0" dir="ltr">{new Date(c.last_message_at).toLocaleTimeString(locale === 'ar' ? 'ar' : 'en', { hour: '2-digit', minute: '2-digit' })}</span>}
                      </div>
                      <p className="text-xs text-gray-500 truncate mt-0.5">{c.last_message?.body || ''}</p>
                    </div>
                    {c.unread_count > 0 && <Badge className="bg-violet-500 shrink-0 rounded-full">{c.unread_count}</Badge>}
                  </button>
                ))
              )}
            </div>
          </div>

          <div className={`flex-1 flex flex-col ${active ? '' : 'hidden md:flex'}`} style={{ minHeight: '560px' }}>
            {!active ? (
              <div className="flex-1 flex flex-col items-center justify-center text-gray-300 gap-2">
                <MessageSquare className="h-12 w-12" />
                <p className="text-sm">{t('اختر محادثة للبدء', 'Select a conversation to start')}</p>
              </div>
            ) : (
              <>
                <div className="px-4 py-3 border-b border-gray-100 bg-white flex items-center gap-3">
                  <div className="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-teal-500 to-teal-600 text-white font-bold text-xs">{active.other_user?.name?.[0] || '?'}</div>
                  <div>
                    <p className="font-semibold text-sm">{active.other_user?.name}</p>
                    <p className="text-xs text-gray-400">{t('محادثة مباشرة', 'Direct conversation')}</p>
                  </div>
                </div>
                <div className="flex-1 overflow-y-auto p-4 space-y-3" style={{ maxHeight: '460px' }}>
                  {messages.map((m) => (
                    <div key={m.id} className={`flex ${isMine(m) ? 'justify-start' : 'justify-end'}`}>
                      <div className={`max-w-[75%] px-4 py-2.5 rounded-2xl text-sm leading-relaxed ${isMine(m) ? 'bg-gradient-to-br from-teal-600 to-teal-500 text-white rounded-br-md' : 'bg-gray-100 text-gray-800 rounded-bl-md'}`}>
                        <p className="whitespace-pre-wrap break-words">{m.body}</p>
                        <p className={`text-[10px] mt-1 ${isMine(m) ? 'text-white/60' : 'text-gray-400'} text-start`} dir="ltr">{new Date(m.created_at).toLocaleTimeString(locale === 'ar' ? 'ar' : 'en', { hour: '2-digit', minute: '2-digit' })}</p>
                      </div>
                    </div>
                  ))}
                  <div ref={bottomRef} />
                </div>
                <div className="p-3 border-t border-gray-100 bg-white flex gap-2">
                  <Input
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && sendMessage()}
                    placeholder={t('اكتب رسالتك...', 'Type a message...')}
                    className="rounded-xl h-11 flex-1 min-w-0"
                  />
                  <Button onClick={sendMessage} disabled={!draft.trim() || sending} className="rounded-xl text-white shrink-0 shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
                    {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4 rtl:-scale-x-100" />}
                  </Button>
                </div>
              </>
            )}
          </div>
        </div>
      </Card>
    </div>
  );
}
