<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Input\MarkNotificationsReadInput;
use App\Http\JsonInputDecoder;
use App\Problem\ApiProblemException;
use App\Repository\NotificationRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /me/notifications/read` (204, idempotent): the caller's unread items whose `updatedAt`
 * (as sent on the wire, second precision) is ≤ `upTo`; an event arriving meanwhile stays unread.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class MarkNotificationsReadProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private NotificationRepository $notifications,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, MarkNotificationsReadInput::class);
        $raw = (string) $input->upTo;
        [$y, $m, $d] = array_map('intval', explode('-', substr($raw, 0, 10)));
        [$h, $i, $s] = array_map('intval', explode(':', substr($raw, 11, 8)));
        try {
            if (!checkdate($m, $d, $y) || $h > 23 || $i > 59 || $s > 59) {
                throw new \InvalidArgumentException();
            }
            $upTo = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw ApiProblemException::validation('upTo', 'upTo must be an RFC 3339 date-time.');
        }
        $this->notifications->markRead($user, $upTo);

        return null;
    }
}
