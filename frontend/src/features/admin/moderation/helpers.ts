import { formatDate } from '@/lib/format';

export const NOTE_MAX = 500;
export const DELETE_WORD = 'SUPPRIMER';

export const reportTargetPath = (t: { targetType: string; targetId: string }) =>
  `/admin/reports/${t.targetType}/${t.targetId}`;

export const when = (iso: string) => formatDate(iso);
