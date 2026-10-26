<?php

declare(strict_types=1);

namespace App\Problem;

/**
 * Mirrors `components.schemas.ProblemCode` of docs/openapi.yaml 1:1.
 * Adding a case is a contract change (architect).
 */
enum ProblemCode: string
{
    case MalformedRequest = 'malformed_request';
    case InvalidCursor = 'invalid_cursor';
    case AccountTokenInvalid = 'account_token_invalid';
    case Unauthenticated = 'unauthenticated';
    case TokenExpired = 'token_expired';
    case InvalidCredentials = 'invalid_credentials';
    case RefreshTokenInvalid = 'refresh_token_invalid';
    case TokenReused = 'token_reused';
    case Forbidden = 'forbidden';
    case AccountSuspended = 'account_suspended';
    case StreamTokenInvalid = 'stream_token_invalid';
    case EmailNotVerified = 'email_not_verified';
    case NotFound = 'not_found';
    case EmailTaken = 'email_taken';
    case UsernameTaken = 'username_taken';
    case ArtistLimitReached = 'artist_limit_reached';
    case ArtistHasTracks = 'artist_has_tracks';
    case TrackNotReady = 'track_not_ready';
    case TrackAlreadyInPlaylist = 'track_already_in_playlist';
    case PlaylistFull = 'playlist_full';
    case PlaylistOrderMismatch = 'playlist_order_mismatch';
    case AlreadyReported = 'already_reported';
    case TooManyPendingUploads = 'too_many_pending_uploads';
    case UploadAlreadyUsed = 'upload_already_used';
    case UploadExpired = 'upload_expired';
    case UploadIncomplete = 'upload_incomplete';
    case EmailAlreadyVerified = 'email_already_verified';
    case StreamTokenExpired = 'stream_token_expired';
    case PayloadTooLarge = 'payload_too_large';
    case UnsupportedMediaType = 'unsupported_media_type';
    case ValidationFailed = 'validation_failed';
    case ArtistRequired = 'artist_required';
    case ClipsNotAllowed = 'clips_not_allowed';
    case ClipOutOfRange = 'clip_out_of_range';
    case CannotFollowSelf = 'cannot_follow_self';
    case CannotReportOwn = 'cannot_report_own';
    case RateLimited = 'rate_limited';
    case InternalError = 'internal_error';

    public function status(): int
    {
        return match ($this) {
            self::MalformedRequest, self::InvalidCursor, self::AccountTokenInvalid => 400,
            self::Unauthenticated, self::TokenExpired, self::InvalidCredentials, self::RefreshTokenInvalid, self::TokenReused => 401,
            self::Forbidden, self::AccountSuspended, self::StreamTokenInvalid, self::EmailNotVerified => 403,
            self::NotFound => 404,
            self::EmailTaken, self::UsernameTaken, self::ArtistLimitReached, self::ArtistHasTracks,
            self::TrackNotReady, self::TrackAlreadyInPlaylist, self::PlaylistFull, self::PlaylistOrderMismatch,
            self::AlreadyReported, self::TooManyPendingUploads, self::UploadAlreadyUsed, self::UploadExpired,
            self::UploadIncomplete, self::EmailAlreadyVerified => 409,
            self::StreamTokenExpired => 410,
            self::PayloadTooLarge => 413,
            self::UnsupportedMediaType => 415,
            self::ValidationFailed, self::ArtistRequired, self::ClipsNotAllowed, self::ClipOutOfRange,
            self::CannotFollowSelf, self::CannotReportOwn => 422,
            self::RateLimited => 429,
            self::InternalError => 500,
        };
    }

    /** Short English summary, stable per code. */
    public function title(): string
    {
        return match ($this) {
            self::MalformedRequest => 'Malformed request',
            self::InvalidCursor => 'Invalid cursor',
            self::AccountTokenInvalid => 'Invalid or expired token',
            self::Unauthenticated => 'Authentication required',
            self::TokenExpired => 'Access token expired',
            self::InvalidCredentials => 'Invalid credentials',
            self::RefreshTokenInvalid => 'Invalid refresh token',
            self::TokenReused => 'Refresh token reused',
            self::Forbidden => 'Forbidden',
            self::AccountSuspended => 'Account suspended',
            self::StreamTokenInvalid => 'Invalid stream token',
            self::EmailNotVerified => 'Email not verified',
            self::NotFound => 'Not found',
            self::EmailTaken => 'Email already in use',
            self::UsernameTaken => 'Username already in use',
            self::ArtistLimitReached => 'Artist limit reached',
            self::ArtistHasTracks => 'Artist has tracks',
            self::TrackNotReady => 'Track not ready',
            self::TrackAlreadyInPlaylist => 'Track already in playlist',
            self::PlaylistFull => 'Playlist full',
            self::PlaylistOrderMismatch => 'Playlist order mismatch',
            self::AlreadyReported => 'Already reported',
            self::TooManyPendingUploads => 'Too many pending uploads',
            self::UploadAlreadyUsed => 'Upload already used',
            self::UploadExpired => 'Upload expired',
            self::UploadIncomplete => 'Upload incomplete',
            self::EmailAlreadyVerified => 'Email already verified',
            self::StreamTokenExpired => 'Stream token expired',
            self::PayloadTooLarge => 'Payload too large',
            self::UnsupportedMediaType => 'Unsupported media type',
            self::ValidationFailed => 'Validation failed',
            self::ArtistRequired => 'Artist required',
            self::ClipsNotAllowed => 'Clips not allowed',
            self::ClipOutOfRange => 'Clip out of range',
            self::CannotFollowSelf => 'Cannot follow yourself',
            self::CannotReportOwn => 'Cannot report own content',
            self::RateLimited => 'Too many requests',
            self::InternalError => 'Internal error',
        };
    }
}
