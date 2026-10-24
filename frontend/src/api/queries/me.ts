import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../instance';
import type { ChangePasswordRequest, Me, UpdateMeRequest } from '../types';
import { sessionStore, useSession } from '@/stores/session';
import { openSession } from './auth';
import { keys } from './keys';

export function useMe() {
  const authenticated = useSession((s) => s.status === 'authenticated');
  return useQuery({
    queryKey: keys.me(),
    queryFn: async () => {
      const me = await api.me.get();
      sessionStore.getState().setUser(me);
      return me;
    },
    enabled: authenticated,
    initialData: () => sessionStore.getState().user ?? undefined,
    initialDataUpdatedAt: 0,
  });
}

/** Current user, only call below an authenticated guard. */
export function useCurrentUser(): Me {
  const { data } = useMe();
  const fallback = useSession((s) => s.user);
  const me = data ?? fallback;
  if (!me) throw new Error('useCurrentUser() used outside an authenticated route');
  return me;
}

function useSetMe() {
  const qc = useQueryClient();
  return (me: Me) => {
    qc.setQueryData(keys.me(), me);
    sessionStore.getState().setUser(me);
    void qc.invalidateQueries({ queryKey: keys.user(me.username) });
  };
}

export function useUpdateMe() {
  const setMe = useSetMe();
  return useMutation({
    mutationFn: (body: UpdateMeRequest) => api.me.update(body),
    onSuccess: setMe,
  });
}

export function useResendVerification() {
  return useMutation({ mutationFn: () => api.me.resendVerification() });
}

/** ADR 008: other sessions are revoked, this one is rotated (new access token + cookie). */
export function useChangePassword() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: ChangePasswordRequest) => api.me.changePassword(body),
    onSuccess: (session) => openSession(qc, session),
  });
}

export function useUploadMyAvatar() {
  return useMutation({ mutationFn: (file: File) => api.me.uploadAvatar(file) });
}

export function useDeleteMyAvatar() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => api.me.deleteAvatar(),
    onSuccess: () => qc.invalidateQueries({ queryKey: keys.me() }),
  });
}
