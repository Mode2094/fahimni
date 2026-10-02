import { create } from 'zustand';
import { assistant } from '@/services/endpoints';

export type AskMessage =
  | { id: string; from: 'bot' | 'user'; text: string }
  | { id: string; from: 'bot'; typing: true };

type AskMeState = {
  open: boolean;
  seen: boolean;
  messages: AskMessage[];
  asking: boolean;
  error: string | null;
  toggle: () => void;
  openPanel: () => void;
  closePanel: () => void;
  ask: (text: string) => Promise<void>;
  restart: () => void;
};

let seq = 0;
const uid = () => `am${Date.now()}-${seq++}`;

const WELCOME = 'أهلاً بك في اسألني 👋 أنا مساعدك في منصة Tadreesly.\n\nاسألني عن أي شيء في لوحتك: كيف أنشئ كورساً؟ ليش الفيديو مش ظاهر؟ كم باقيلي مساحة؟ كيف أبدأ بثاً؟ وهكذا.';

const SUGGESTIONS = [
  'كيف أعمل كورس؟',
  'وين أضيف الفيديو؟',
  'كم باقيلي مساحة؟',
  'كيف أبدأ بث؟',
  'ليش الفيديو مش ظاهر؟',
];

export const useAskMe = create<AskMeState>((set, get) => ({
  open: false,
  seen: false,
  messages: [],
  asking: false,
  error: null,

  toggle: () => {
    const { open } = get();
    if (open) {
      set({ open: false });
    } else {
      set({ open: true, seen: true });
      if (get().messages.length === 0) {
        set({ messages: [{ id: uid(), from: 'bot', text: WELCOME }] });
      }
    }
  },

  openPanel: () => {
    set({ open: true, seen: true });
    if (get().messages.length === 0) {
      set({ messages: [{ id: uid(), from: 'bot', text: WELCOME }] });
    }
  },

  closePanel: () => set({ open: false }),

  ask: async (text: string) => {
    const q = text.trim();
    if (!q || get().asking) return;

    const userMsg: AskMessage = { id: uid(), from: 'user', text: q };
    set((s) => ({ messages: [...s.messages, userMsg, { id: uid(), from: 'bot', typing: true }], asking: true, error: null }));

    try {
      const page = typeof window !== 'undefined' ? window.location.pathname : '';
      const res = await assistant.ask(q, page);

      set((s) => ({
        messages: [...s.messages.filter((m) => !('typing' in m && m.typing)), { id: uid(), from: 'bot', text: res.answer }],
        asking: false,
      }));
    } catch {
      set((s) => ({
        messages: [
          ...s.messages.filter((m) => !('typing' in m && m.typing)),
          {
            id: uid(),
            from: 'bot',
            text: 'عذراً، حدث خطأ أثناء معالجة سؤالك. يرجى المحاولة مرة أخرى بعد قليل، أو افتح تذكرة من صفحة الدعم الفني.',
          },
        ],
        asking: false,
        error: 'request_failed',
      }));
    }
  },

  restart: () => {
    set({ messages: [{ id: uid(), from: 'bot', text: WELCOME }], asking: false, error: null });
  },
}));

export { SUGGESTIONS };
