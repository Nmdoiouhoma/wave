import { isApiError } from '@/api/errors';
import { errorMessage } from '@/lib/errorMessages';
import { REPORT_REASONS } from '@/features/reports/reportOptions';
import type {
  AdminContentType,
  DecisionAction,
  ReportStatus,
  ReportTargetType,
} from './moderationApi';

export const TARGET_LABEL: Record<ReportTargetType, string> = {
  track: 'Morceau',
  post: 'Post',
  comment: 'Commentaire',
  playlist: 'Playlist',
  artist: 'Artiste',
  user: 'Compte',
};

export const CONTENT_TYPES: AdminContentType[] = ['track', 'post', 'comment', 'playlist', 'artist'];
export const CONTENT_LABEL: Record<AdminContentType, string> = {
  track: 'Morceaux',
  post: 'Posts',
  comment: 'Commentaires',
  playlist: 'Playlists',
  artist: 'Artistes',
};

export const STATUS_LABEL: Record<ReportStatus, string> = {
  open: 'Ouverts',
  actioned: 'Traités',
  dismissed: 'Classés sans suite',
};
export const REPORT_STATUS_LABEL: Record<ReportStatus, string> = {
  open: 'Ouvert',
  actioned: 'Traité',
  dismissed: 'Classé sans suite',
};

export const REASON_LABEL: Record<string, string> = Object.fromEntries(
  REPORT_REASONS.map((r) => [r.value, r.label]),
);

export const VISIBILITY_LABEL: Record<string, string> = {
  public: 'Public',
  followers: 'Abonnés',
  close_friends: 'Proches',
  private: 'Privé',
};
export const MEDIA_STATUS_LABEL: Record<string, string> = {
  processing: 'En traitement',
  ready: 'Prêt',
  failed: 'Échec',
};

export const ACTION_COPY: Record<
  DecisionAction,
  { title: string; confirm: string; body: string; danger: boolean; done: string }
> = {
  hide: {
    title: 'Masquer ce contenu',
    confirm: 'Masquer',
    body: 'Le contenu disparaît pour les autres comptes et les signalements ouverts sont traités.',
    danger: true,
    done: 'Contenu masqué',
  },
  dismiss: {
    title: 'Rétablir ce contenu',
    confirm: 'Rétablir',
    body: 'Le contenu redevient visible et les signalements ouverts sont classés sans suite.',
    danger: false,
    done: 'Contenu rétabli',
  },
  suspend: {
    title: 'Suspendre ce compte',
    confirm: 'Suspendre',
    body: 'Le compte est suspendu, ses sessions sont révoquées et les signalements ouverts sont traités.',
    danger: true,
    done: 'Compte suspendu',
  },
};

/** French messages for the moderation endpoints (403/404/409/422 mapped, rest falls back). */
export function moderationError(e: unknown): string {
  if (isApiError(e)) {
    if (e.status === 403) return 'Tu n’as pas les droits pour cette action sur cette cible.';
    if (e.status === 404) return 'Cette cible n’existe plus (déjà supprimée ?).';
    if (e.code === 'artist_has_tracks')
      return 'Cet artiste a encore des morceaux : supprime-les d’abord.';
    if (e.status === 409) return 'Un conflit empêche l’action, recharge la liste.';
    if (e.status === 422)
      return 'Action impossible : compte déjà suspendu, toi-même, ou note invalide (500 caractères max).';
  }
  return errorMessage(e);
}
