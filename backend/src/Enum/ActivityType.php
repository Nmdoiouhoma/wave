<?php

declare(strict_types=1);

namespace App\Enum;

/** DATA_MODEL §15: closed list of journal event types (CHECK in the migration). Prefix = category. */
enum ActivityType: string
{
    case LoginSucceeded = 'auth.login_succeeded';
    case LoginFailed = 'auth.login_failed';
    case Logout = 'auth.logout';
    case TokenReused = 'auth.token_reused';
    case PasswordChanged = 'auth.password_changed';
    case PasswordResetRequested = 'auth.password_reset_requested';
    case PasswordResetCompleted = 'auth.password_reset_completed';

    case Registered = 'account.registered';
    case EmailVerified = 'account.email_verified';
    case EmailChanged = 'account.email_changed';

    case TrackCreated = 'content.track_created';
    case TrackDeleted = 'content.track_deleted';
    case PostCreated = 'content.post_created';
    case PostDeleted = 'content.post_deleted';
    case CommentCreated = 'content.comment_created';
    case ArtistCreated = 'content.artist_created';

    case ReportCreated = 'report.created';
    case ReportAutoHidden = 'report.auto_hidden';

    case UploadRejected = 'upload.rejected';

    case RateLimited = 'abuse.rate_limited';

    case UserSuspended = 'admin.user_suspended';
    case UserUnsuspended = 'admin.user_unsuspended';
    case RoleChanged = 'admin.role_changed';
    case ContentHidden = 'admin.content_hidden';
    case ContentRestored = 'admin.content_restored';
    case ContentDeleted = 'admin.content_deleted';
    case ReportDecided = 'admin.report_decided';
    case AlertAcknowledged = 'admin.alert_acknowledged';
    case AnnouncementCreated = 'admin.announcement_created';
    case AnnouncementUpdated = 'admin.announcement_updated';
    case AnnouncementDeleted = 'admin.announcement_deleted';
    case JournalViewed = 'admin.journal_viewed';
    case AdminGranted = 'admin.admin_granted';
    case AdminRevoked = 'admin.admin_revoked';

    public function category(): ActivityCategory
    {
        return ActivityCategory::from(strstr($this->value, '.', true) ?: $this->value);
    }

    public function isAdmin(): bool
    {
        return ActivityCategory::Admin === $this->category();
    }
}
