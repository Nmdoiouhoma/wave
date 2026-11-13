<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\ActivityEventView;
use App\ApiResource\AnnouncementView;
use App\ApiResource\SecurityAlertTargetView;
use App\ApiResource\SecurityAlertView;
use App\Entity\ActivityEvent;
use App\Entity\Announcement;
use App\Entity\SecurityAlert;
use App\Entity\User;

/** W-72 output views: journal events, security alerts, announcements (DATA_MODEL §15-17). */
final readonly class JournalMapper
{
    public function __construct(private UserMapper $users)
    {
    }

    public function event(ActivityEvent $event): ActivityEventView
    {
        $hash = $event->getIpHash();

        return new ActivityEventView(
            $event->getId()->toRfc4122(),
            $event->getCategory()->value,
            $event->getType()->value,
            $this->summary($event->getActor()),
            $this->summary($event->getSubjectUser()),
            $event->getTargetType(),
            $event->getTargetId()?->toRfc4122(),
            $event->getIpPrefix(),
            null === $hash ? null : bin2hex($hash),
            $event->getUserAgent(),
            (object) $event->getMetadata(),
            DateFormat::format($event->getCreatedAt()),
        );
    }

    public function alert(SecurityAlert $alert): SecurityAlertView
    {
        $hash = $alert->getSubjectIpHash();
        $targetType = $alert->getSubjectTargetType();
        $targetId = $alert->getSubjectTargetId();

        return new SecurityAlertView(
            $alert->getId()->toRfc4122(),
            $alert->getRule()->value,
            $alert->getSeverity()->value,
            $this->summary($alert->getSubjectUser()),
            null === $hash ? null : bin2hex($hash),
            null === $targetType || null === $targetId ? null : new SecurityAlertTargetView($targetType, $targetId->toRfc4122()),
            $alert->getHitCount(),
            DateFormat::format($alert->getFirstSeenAt()),
            DateFormat::format($alert->getLastSeenAt()),
            $alert->getStatus()->value,
            $this->summary($alert->getAcknowledgedBy()),
            DateFormat::nullable($alert->getAcknowledgedAt()),
            $alert->getNote(),
        );
    }

    public function announcement(Announcement $announcement): AnnouncementView
    {
        return new AnnouncementView(
            $announcement->getId()->toRfc4122(),
            $announcement->getTitle(),
            $announcement->getBody(),
            $announcement->getLevel()->value,
            DateFormat::format($announcement->getStartsAt()),
            DateFormat::nullable($announcement->getEndsAt()),
            DateFormat::format($announcement->getUpdatedAt()),
        );
    }

    private function summary(?User $user): ?\App\ApiResource\UserSummary
    {
        return null === $user ? null : $this->users->toSummary($user);
    }
}
