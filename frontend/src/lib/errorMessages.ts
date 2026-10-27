import { isApiError, type ErrorCode } from '@/api/errors';
import type { FailureReason } from '@/api/types';

const MESSAGES: Partial<Record<ErrorCode, string>> = {
  network_error: 'Connexion impossible. Vérifie ton réseau et réessaie.',
  aborted: 'Opération annulée.',
  malformed_request: 'La requête est invalide.',
  invalid_cursor: 'La liste a changé, recharge la page.',
  account_token_invalid: 'Ce lien est invalide ou a expiré.',
  unauthenticated: 'Tu dois être connecté·e.',
  token_expired: 'Ta session a expiré, reconnecte-toi.',
  invalid_credentials: 'E-mail ou mot de passe incorrect.',
  refresh_token_invalid: 'Ta session a expiré, reconnecte-toi.',
  token_reused: 'Session expirée pour des raisons de sécurité, reconnecte-toi.',
  forbidden: 'Tu n’as pas le droit de faire cela.',
  account_suspended: 'Ce compte est suspendu.',
  email_not_verified: 'Confirme ton adresse e-mail pour effectuer cette action.',
  not_found: 'Introuvable.',
  email_taken: 'Cette adresse e-mail est déjà utilisée.',
  username_taken: 'Ce nom d’utilisateur est déjà pris.',
  artist_limit_reached: 'Tu gères déjà 10 artistes, le maximum.',
  artist_has_tracks: 'Cet artiste a encore des morceaux : supprime-les ou déplace-les d’abord.',
  track_not_ready: 'Ce titre n’est pas encore prêt.',
  email_already_verified: 'Ton adresse e-mail est déjà confirmée.',
  stream_token_expired: 'Le lien de lecture a expiré.',
  payload_too_large: 'Fichier trop lourd : 30 Mo maximum (200 Mo pour un MP4).',
  unsupported_media_type: 'Format de fichier non pris en charge : MP3, AAC/M4A ou MP4.',
  validation_failed: 'Certains champs sont invalides.',
  artist_required: 'Choisis l’artiste sous lequel publier ce morceau.',
  rate_limited: 'Trop de tentatives.',
  too_many_pending_uploads:
    'Trop d’envois en cours (10 maximum). Attends qu’ils se terminent avant d’en lancer d’autres.',
  upload_already_used: 'Ce fichier a déjà été utilisé pour créer un titre.',
  upload_expired: 'L’envoi a expiré (plus de 2 heures). Relance l’import de ce fichier.',
  upload_incomplete: 'Le fichier n’est pas arrivé complètement. Relance l’import.',
  storage_rejected: 'Le stockage a refusé le fichier (lien expiré ou taille non conforme).',
  internal_error: 'Une erreur est survenue. Réessaie dans un instant.',
};

/** ADR 008: a reused refresh token revoked the whole session family. */
export const TOKEN_REUSED_MESSAGE = 'Session expirée pour des raisons de sécurité, reconnecte-toi.';

export function retryHint(seconds: number | null): string {
  if (seconds === null) return 'Réessaie plus tard.';
  if (seconds < 60) return `Réessaie dans ${seconds} s.`;
  return `Réessaie dans ${Math.ceil(seconds / 60)} min.`;
}

export function errorMessage(error: unknown): string {
  if (!isApiError(error)) return MESSAGES.internal_error!;
  // Client-side pre-check failures carry their own French message.
  if (error.status === 0 && error.code === 'unsupported_media_type' && error.detail)
    return error.detail;
  if (error.code === 'rate_limited')
    return `${MESSAGES.rate_limited} ${retryHint(error.retryAfter)}`;
  return MESSAGES[error.code] ?? MESSAGES.internal_error!;
}

/**
 * FB-10 `no_audio` is not in openapi.yaml's FailureReason enum yet: handled as a string fallback
 * until the architect adds it (then it is simply part of the generated union).
 */
export type KnownFailureReason = NonNullable<FailureReason> | 'no_audio';

const FAILURES: Record<KnownFailureReason, string | ((video: boolean) => string)> = {
  invalid_media:
    'Ce fichier est illisible ou n’est pas un format audio/vidéo pris en charge (MP3, M4A, AAC, MP4).',
  no_audio: (video) =>
    video
      ? 'Cette vidéo ne contient pas de son : il n’y a rien à importer.'
      : 'Ce fichier ne contient pas de son.',
  unsupported_codec: 'Codec non pris en charge : utilise MP3 ou AAC.',
  too_long: 'Le morceau dépasse la durée maximale de 10 minutes.',
  too_large: (video) =>
    video
      ? 'Le fichier dépasse la taille maximale de 200 Mo pour un MP4.'
      : 'Le fichier dépasse la taille maximale (30 Mo, 200 Mo pour un MP4).',
  processing_error: 'Le traitement a échoué. Relance l’import.',
};

/**
 * `video`: whether the source was a video (known client-side from the picked file's extension);
 * unknown (e.g. track page) → the neutral wording.
 */
export function failureMessage(reason: FailureReason | string, { video = false } = {}): string {
  const entry = reason ? FAILURES[reason as KnownFailureReason] : undefined;
  if (!entry) return 'Le traitement a échoué.';
  return typeof entry === 'function' ? entry(video) : entry;
}
