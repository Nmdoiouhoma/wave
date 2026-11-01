import { createStore, useStore } from 'zustand';

export interface Toast {
  id: number;
  message: string;
  tone: 'info' | 'success' | 'error';
}

interface ToastState {
  toasts: Toast[];
  show(message: string, tone?: Toast['tone']): void;
  dismiss(id: number): void;
}

let seq = 0;
export const toastStore = createStore<ToastState>()((set, get) => ({
  toasts: [],
  show: (message, tone = 'info') => {
    const id = ++seq;
    set({ toasts: [...get().toasts.slice(-2), { id, message, tone }] });
    setTimeout(() => get().dismiss(id), 3500);
  },
  dismiss: (id) => set({ toasts: get().toasts.filter((t) => t.id !== id) }),
}));

export const toast = (message: string, tone?: Toast['tone']) =>
  toastStore.getState().show(message, tone);

export function useToasts() {
  return useStore(toastStore, (s) => s.toasts);
}
