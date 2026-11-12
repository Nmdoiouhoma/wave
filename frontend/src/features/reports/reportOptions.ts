// W-36: report targets, reasons (French labels) and sheet titles.
import type { ReportReason, ReportTargetType } from '@/api/types';

export const DETAILS_MAX = 1000;

export const REPORT_REASONS: { value: ReportReason; label: string }[] = [
  { value: 'spam', label: 'Spam ou arnaque' },
  { value: 'harassment', label: 'Harcèlement ou intimidation' },
  { value: 'hate', label: 'Discours haineux' },
  { value: 'sexual', label: 'Contenu sexuel' },
  { value: 'violence', label: 'Violence ou menaces' },
  { value: 'copyright', label: 'Atteinte aux droits d’auteur' },
  { value: 'impersonation', label: 'Usurpation d’identité' },
  { value: 'other', label: 'Autre' },
];

export interface ReportTarget {
  type: ReportTargetType;
  id: string;
}

const TITLES: Record<ReportTarget['type'], string> = {
  track: 'Signaler ce morceau',
  post: 'Signaler ce post',
  comment: 'Signaler ce commentaire',
  playlist: 'Signaler cette playlist',
  artist: 'Signaler cet artiste',
  user: 'Signaler ce compte',
};

export function reportTitle(type: ReportTarget['type']): string {
  return TITLES[type];
}
