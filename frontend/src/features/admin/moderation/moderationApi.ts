// W-74: moderation operations of docs/openapi.yaml (/admin/report-targets*, /admin/content*).
// Own file (not endpoints.ts) so the back-office shell lot can evolve the shared API in parallel.
import { apiClient } from '@/api/instance';
import type { components } from '@/api/schema';

type S = components['schemas'];
export type ReportTargetItem = S['ReportTarget'];
export type ReportItem = S['Report'];
export type ReportTargetPageData = S['ReportTargetPage'];
export type ReportPageData = S['ReportPage'];
export type AdminContentItem = S['AdminContentItem'];
export type AdminContentPageData = S['AdminContentPage'];
export type AdminContentType = S['AdminContentType'];
export type ReportTargetType = S['ReportTargetType'];
export type ReportStatus = S['ReportStatus'];
export type Moderation = S['Moderation'];
export type DecisionAction = S['ModerationDecisionRequest']['action'];

const enc = encodeURIComponent;
const { request } = apiClient;

export const moderationApi = {
  queue: (q: { status: ReportStatus; targetType?: ReportTargetType; cursor?: string | null }) =>
    request<ReportTargetPageData>('GET', '/admin/report-targets', { query: { ...q, limit: 20 } }),
  reports: (type: ReportTargetType, id: string, cursor?: string | null) =>
    request<ReportPageData>('GET', `/admin/report-targets/${enc(type)}/${enc(id)}/reports`, {
      query: { cursor, limit: 20 },
    }),
  decide: (type: ReportTargetType, id: string, action: DecisionAction, note: string | null) =>
    request<ReportTargetItem>('POST', `/admin/report-targets/${enc(type)}/${enc(id)}/decision`, {
      body: { action, note },
    }),
  content: (q: {
    type: AdminContentType;
    moderation?: Moderation;
    ownerId?: string;
    q?: string;
    cursor?: string | null;
  }) => request<AdminContentPageData>('GET', '/admin/content', { query: { ...q, limit: 20 } }),
  deleteContent: (type: AdminContentType, id: string, note: string) =>
    request<undefined>('POST', `/admin/content/${enc(type)}/${enc(id)}/deletion`, {
      body: { note },
    }),
};
