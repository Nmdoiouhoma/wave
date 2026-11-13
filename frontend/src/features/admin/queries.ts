import {
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
  type QueryClient,
} from '@tanstack/react-query';
import { adminApi, type AdminUserDetail, type AdminUserFilters, type SetRoleRequest } from './adminApi';

export const adminKeys = {
  all: () => ['admin'] as const,
  dashboard: () => ['admin', 'dashboard'] as const,
  users: (f: AdminUserFilters) => ['admin', 'users', f] as const,
  usersAll: () => ['admin', 'users'] as const,
  user: (id: string) => ['admin', 'user', id] as const,
};

export function useAdminDashboard() {
  return useQuery({
    queryKey: adminKeys.dashboard(),
    queryFn: adminApi.dashboard,
    staleTime: 30_000,
    retry: false,
  });
}

export function useAdminUsers(filters: AdminUserFilters) {
  return useInfiniteQuery({
    queryKey: adminKeys.users(filters),
    queryFn: ({ pageParam }) => adminApi.users(filters, { cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
    retry: false,
  });
}

export function useAdminUser(id: string) {
  return useQuery({ queryKey: adminKeys.user(id), queryFn: () => adminApi.user(id), retry: false });
}

function applyDetail(qc: QueryClient, detail: AdminUserDetail) {
  qc.setQueryData(adminKeys.user(detail.id), detail);
  void qc.invalidateQueries({ queryKey: adminKeys.usersAll() });
  void qc.invalidateQueries({ queryKey: adminKeys.dashboard() });
}

export function useSuspendUser(id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (note: string) => adminApi.suspend(id, note),
    onSuccess: (d) => applyDetail(qc, d),
  });
}

export function useUnsuspendUser(id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (note: string) => adminApi.unsuspend(id, note),
    onSuccess: (d) => applyDetail(qc, d),
  });
}

export function useSetUserRole(id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: SetRoleRequest) => adminApi.setRole(id, body),
    onSuccess: (d) => applyDetail(qc, d),
  });
}
