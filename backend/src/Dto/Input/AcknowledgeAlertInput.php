<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `AdminNoteRequest` of `POST /admin/alerts/{alertId}/acknowledgement`: note required, 1–500 after trim. */
final class AcknowledgeAlertInput extends InputDto
{
    #[Assert\NotNull]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 500, normalizer: 'trim')]
    public ?string $note = null;
}
