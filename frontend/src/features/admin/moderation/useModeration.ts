import {
  useInfiniteQuery,
  useMutation,
  useQueryClient,
  type InfiniteData,
  type QueryKey,
} from '@tanstack/react-query';
import {
  moderationApi,
  type AdminContentPageData,
  type AdminContentType,
  type DecisionAction,
  type Moderation,
  type ReportStatus,
  type ReportTargetItem,
  type ReportTargetPageData,
  type ReportTargetType,
} from './moderationApi';

const root = ['admin', 'moderation'] as const;
export const modKeys = {
  queue: (status: ReportStatus, targetType?: ReportTargetType) =>
    [...root, 'queue', { status, targetType }] as const,
  queueAll: [...root, 'queue'] as const,
  reports: (t: ReportTargetType, id: string) => [...root, 'reports', t, id] as const,
  target: (t: ReportTargetType, id: string) => [...root, 'target', t, id] as const,
  content: (f: ContentFilters) => [...root, 'content', f] as const,
  contentAll: [...root, 'content'] as const,
};

export interface ContentFilters {
  type: AdminContentType;
  moderation?: Moderation;
  ownerId?: string;
  q?: string;
}

export function useReportQueue(status: ReportStatus, targetType?: ReportTargetType) {
  return useInfiniteQuery({
    queryKey: modKeys.queue(status, targetType),
    queryFn: ({ pageParam }) => moderationApi.queue({ status, targetType, cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
  });
}

export function useTargetReports(type: ReportTargetType, id: string) {
  return useInfiniteQuery({
    queryKey: modKeys.reports(type, id),
    queryFn: ({ pageParam }) => moderationApi.reports(type, id, pageParam),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
  });
}

export function useAdminContent(filters: ContentFilters) {
  return useInfiniteQuery({
    queryKey: modKeys.content(filters),
    queryFn: ({ pageParam }) => moderationApi.content({ ...filters, cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
  });
}

type Snapshot = [QueryKey, unknown][];

function mapPages<P extends { items: unknown[] }>(
  data: InfiniteData<P> | undefined,
  fn: (items: P['items']) => P['items'],
) {
  return data && { ...data, pages: data.pages.map((p) => ({ ...p, items: fn(p.items) })) };
}

export interface DecisionVars {
  type: ReportTargetType;
  id: string;
  action: DecisionAction;
  note: string | null;
}

/**
 * Decision on a target. Optimistic: the target leaves the open queue and the content list row
 * flips hidden/visible at once; every touched cache is rolled back if the server refuses.
 */
export function useDecide() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: DecisionVars) => moderationApi.decide(v.type, v.id, v.action, v.note),
    onMutate: async (v) => {
      await qc.cancelQueries({ queryKey: root });
      const snapshot: Snapshot = [
        ...qc.getQueriesData({ queryKey: modKeys.queueAll }),
        ...qc.getQueriesData({ queryKey: modKeys.contentAll }),
        ...qc.getQueriesData({ queryKey: modKeys.target(v.type, v.id) }),
      ];
      qc.setQueriesData<InfiniteData<ReportTargetPageData>>({ queryKey: modKeys.queueAll }, (d) =>
        mapPages(d, (items) =>
          items.filter((t) => !(t.targetType === v.type && t.targetId === v.id)),
        ),
      );
      qc.setQueriesData<InfiniteData<AdminContentPageData>>({ queryKey: modKeys.contentAll }, (d) =>
        mapPages(d, (items) =>
          items.map((c) =>
            c.type === v.type && c.id === v.id
              ? { ...c, moderation: v.action === 'hide' ? 'hidden' : 'visible' }
              : c,
          ),
        ),
      );
      return { snapshot };
    },
    onError: (_e, _v, ctx) => ctx?.snapshot.forEach(([key, data]) => qc.setQueryData(key, data)),
    onSuccess: (target: ReportTargetItem, v) =>
      qc.setQueryData(modKeys.target(v.type, v.id), target),
    onSettled: () => qc.invalidateQueries({ queryKey: root }),
  });
}

export function useDeleteContent() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { type: AdminContentType; id: string; note: string }) =>
      moderationApi.deleteContent(v.type, v.id, v.note),
    onMutate: async (v) => {
      await qc.cancelQueries({ queryKey: root });
      const snapshot: Snapshot = qc.getQueriesData({ queryKey: modKeys.contentAll });
      qc.setQueriesData<InfiniteData<AdminContentPageData>>({ queryKey: modKeys.contentAll }, (d) =>
        mapPages(d, (items) => items.filter((c) => !(c.type === v.type && c.id === v.id))),
      );
      return { snapshot };
    },
    onError: (_e, _v, ctx) => ctx?.snapshot.forEach(([key, data]) => qc.setQueryData(key, data)),
    onSettled: () => qc.invalidateQueries({ queryKey: root }),
  });
}
