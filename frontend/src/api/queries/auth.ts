import { useMutation, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { api, apiClient } from '../instance';
import type {
  AuthSession,
  LoginRequest,
  PasswordResetConfirmRequest,
  RegisterRequest,
} from '../types';
import { sessionStore } from '@/stores/session';
import { playerStore } from '@/stores/player';
import { keys } from './keys';

/** Stores a fresh session (and shares it with the other tabs). */
export function openSession(qc: QueryClient, session: AuthSession) {
  apiClient.adoptSession(session);
  qc.setQueryData(keys.me(), session.user);
}

export function useLogin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: LoginRequest) => api.auth.login(body),
    onSuccess: (s) => openSession(qc, s),
  });
}

export function useRegister() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: RegisterRequest) => api.auth.register(body),
    onSuccess: (s) => openSession(qc, s),
  });
}

export function useLogout() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      playerStore.getState().stop();
      try {
        await api.auth.logout();
      } catch {
        // Always 204 by contract; a network failure must not keep the user logged in locally.
      }
    },
    onSettled: () => {
      apiClient.endSession('logout');
      qc.clear();
    },
  });
}

export function useVerifyEmail() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (token: string) => api.auth.verifyEmail(token),
    onSuccess: () => {
      if (sessionStore.getState().status === 'authenticated') {
        void qc.invalidateQueries({ queryKey: keys.me() });
      }
    },
  });
}

export function useRequestPasswordReset() {
  return useMutation({ mutationFn: (email: string) => api.auth.requestPasswordReset(email) });
}

export function useConfirmPasswordReset() {
  return useMutation({
    mutationFn: (body: PasswordResetConfirmRequest) => api.auth.confirmPasswordReset(body),
  });
}
