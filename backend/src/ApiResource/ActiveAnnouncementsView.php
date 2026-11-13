<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `ActiveAnnouncements` schema: at most 3 items (DATA_MODEL §17). */
final readonly class ActiveAnnouncementsView
{
    /** @param list<AnnouncementView> $items */
    public function __construct(public array $items)
    {
    }
}
