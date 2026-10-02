import { create } from 'zustand';
import { planRecommender } from '@/services/endpoints';
import type { RecommenderQuestion, PlanRecommendationResponse } from '@/types/api';
import { useLocale } from './locale';

export type ChatMessage =
  | { id: string; kind: 'text'; from: 'bot' | 'user'; text: string }
  | { id: string; kind: 'question'; from: 'bot'; question: RecommenderQuestion }
  | { id: string; kind: 'typing'; from: 'bot' }
  | { id: string; kind: 'result'; from: 'bot'; result: PlanRecommendationResponse };

type Draft =
  | { kind: 'text'; from: 'bot' | 'user'; text: string }
  | { kind: 'question'; from: 'bot'; question: RecommenderQuestion }
  | { kind: 'typing'; from: 'bot' }
  | { kind: 'result'; from: 'bot'; result: PlanRecommendationResponse };

type AssistantState = {
  open: boolean;
  seen: boolean;
  messages: ChatMessage[];
  questions: RecommenderQuestion[];
  answers: Record<string, string>;
  asking: boolean;
  toggle: () => void;
  openPanel: () => void;
  closePanel: () => void;
  start: () => void;
  answer: (optionKey: string) => void;
  restart: () => void;
};

let seq = 0;
const uid = () => `m${Date.now()}-${seq++}`;

const TYPE_DELAY = 700;
const RESULT_DELAY = 1400;

const withSet = (set: any) => (m: Draft) => {
  const msg = { ...m, id: uid() } as ChatMessage;
  set((s: AssistantState) => ({ messages: [...s.messages, msg] }));
};

export const useAssistant = create<AssistantState>((set, get) => {
  const push = withSet(set);

  return {
    open: false,
    seen: false,
    messages: [],
    questions: [],
    answers: {},
    asking: false,

    toggle: () => {
      const { open } = get();
      if (open) {
        set({ open: false });
      } else {
        set({ open: true, seen: true });
        if (get().messages.length === 0) get().start();
      }
    },

    openPanel: () => {
      set({ open: true, seen: true });
      if (get().messages.length === 0) get().start();
    },

    closePanel: () => set({ open: false }),

    start: async () => {
      push({
        kind: 'text',
        from: 'bot',
        text: 'أهلاً بك! 👋 أنا مساعد اختيار الخطة الذكي، رح أسألك شوية أسئلة سريعة (ثواني بس) وأختارلك الخطة الأنسب لمنصتك.',
      });
      push({ kind: 'typing', from: 'bot' });

      try {
        const questions = await planRecommender.questions();
        set({ questions, asking: false });

        window.setTimeout(() => {
          set((s) => ({ messages: s.messages.filter((m) => m.kind !== 'typing') }));
          if (questions[0]) {
            push({ kind: 'question', from: 'bot', question: questions[0] });
          }
        }, TYPE_DELAY);
      } catch {
        set((s) => ({
          messages: [
            ...s.messages.filter((m) => m.kind !== 'typing'),
            { id: uid(), kind: 'text', from: 'bot', text: 'عذراً، تعذر تحميل الأسئلة. يرجى المحاولة لاحقاً.' },
          ],
          asking: false,
        }));
      }
    },

    answer: (optionKey: string) => {
      const { questions, answers, asking } = get();
      if (asking) return;

      const index = Object.keys(answers).length;
      const question = questions[index];
      if (!question) return;

      const option = question.options.find((o) => o.key === optionKey);
      const isAr = useLocale.getState().locale === 'ar';
      const label = option ? (isAr ? option.label.ar : option.label.en) : optionKey;

      const nextAnswers = { ...answers, [question.key]: optionKey };
      set({ asking: true });

      push({ kind: 'text', from: 'user', text: label });
      push({ kind: 'typing', from: 'bot' });

      window.setTimeout(() => {
        const next = questions[index + 1];

        if (next) {
          set((s) => ({
            messages: s.messages.filter((m) => m.kind !== 'typing'),
            answers: nextAnswers,
            asking: false,
          }));
          push({ kind: 'question', from: 'bot', question: next });
          return;
        }

        set((s) => ({
          messages: s.messages.filter((m) => m.kind !== 'typing'),
          answers: nextAnswers,
        }));

        window.setTimeout(async () => {
          try {
            const result = await planRecommender.recommend(nextAnswers);
            set((s) => ({
              messages: [...s.messages, { id: uid(), kind: 'result', from: 'bot', result }],
              asking: false,
            }));
          } catch {
            set((s) => ({
              messages: [
                ...s.messages,
                { id: uid(), kind: 'text', from: 'bot', text: 'عذراً، حدث خطأ أثناء اختيار الخطة. يرجى المحاولة لاحقاً.' },
              ],
              asking: false,
            }));
          }
        }, RESULT_DELAY);
      }, TYPE_DELAY);
    },

    restart: () => {
      const { questions } = get();
      set({ messages: [], answers: {}, asking: false });
      push({ kind: 'text', from: 'bot', text: 'حسناً، لنبدأ من جديد! 👌' });
      window.setTimeout(() => {
        if (questions[0]) {
          push({ kind: 'question', from: 'bot', question: questions[0] });
        } else {
          get().start();
        }
      }, 400);
    },
  };
});
