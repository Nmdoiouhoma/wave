import { isApiError, type ErrorCode } from '@/api/errors';
import type { FailureReason } from '@/api/types';

const MESSAGES: Partial<Record<ErrorCode, string>> = {
  network_error: 'Connexion impossible. Vérifiez votre réseau et réessayez.',
  aborted: 'Opération annulée.',
  malformed_request: 'La requête est invalide.',
  invalid_cursor: 'La liste a changé, rechargez la page.',
  account_token_invalid: 'Ce lien est invalide ou a expiré.',
  unauthenticated: 'Vous devez être connecté·e.',
  token_expired: 'Votre session a expiré, reconnectez-vous.',
  invalid_credentials: 'E-mail ou mot de passe incorrect.',
  refresh_token_invalid: 'Votre session a expiré, reconnectez-vous.',
  token_reused: 'Session expirée pour des raisons de sécurité, reconnecte-toi.',
  forbidden: 'Vous n’avez pas le droit de faire cela.',
  account_suspended: 'Ce compte est suspendu.',
  email_not_verified: 'Confirmez votre adresse e-mail pour effectuer cette action.',
  not_found: 'Introuvable.',
  email_taken: 'Cette adresse e-mail est déjà utilisée.',
  username_taken: 'Ce nom d’utilisateur est déjà pris.',
  artist_limit_reached: 'Vous gérez déjà 10 artistes, le maximum.',
  artist_has_tracks: 'Cet artiste a encore des titres : supprimez-les ou déplacez-les d’abord.',
  track_not_ready: 'Ce titre n’est pas encore prêt.',
  email_already_verified: 'Votre adresse e-mail est déjà confirmée.',
  stream_token_expired: 'Le lien de lecture a expiré.',
  payload_too_large: 'Fichier trop lourd : 30 Mo maximum (200 Mo pour un MP4).',
  unsupported_media_type: 'Format de fichier non pris en charge : MP3, AAC/M4A ou MP4.',
  validation_failed: 'Certains champs sont invalides.',
  artist_required: 'Choisissez l’artiste sous lequel publier ce titre.',
  rate_limited: 'Trop de tentatives.',
  too_many_pending_uploads:
    'Trop d’envois en cours (10 maximum). Attends qu’ils se terminent avant d’en lancer d’autres.',
  upload_already_used: 'Ce fichier a déjà été utilisé pour créer un titre.',
  upload_expired: 'L’envoi a expiré (plus de 2 heures). Relance l’import de ce fichier.',
  upload_incomplete: 'Le fichier n’est pas arrivé complètement. Relance l’import.',
  storage_rejected: 'Le stockage a refusé le fichier (lien expiré ou taille non conforme).',
  internal_error: 'Une erreur est survenue. Réessayez dans un instant.',
};

/** ADR 008: a reused refresh token revoked the whole session family. */
export const TOKEN_REUSED_MESSAGE = 'Session expirée pour des raisons de sécurité, reconnecte-toi.';

export function retryHint(seconds: number | null): string {
  if (seconds === null) return 'Réessayez plus tard.';
  if (seconds < 60) return `Réessayez dans ${seconds} s.`;
  return `Réessayez dans ${Math.ceil(seconds / 60)} min.`;
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

const FAILURES: Record<NonNullable<FailureReason>, string> = {
  invalid_media: 'Le fichier n’est pas un audio valide.',
  unsupported_codec: 'Codec non pris en charge : utilisez MP3 ou AAC.',
  too_long: 'Le titre dépasse la durée maximale de 10 minutes.',
  too_large: 'Le fichier dépasse la taille maximale de 30 Mo.',
  processing_error: 'Le traitement a échoué. Réessayez l’import.',
};

export function failureMessage(reason: FailureReason): string {
  return reason ? FAILURES[reason] : 'Le traitement a échoué.';
}
