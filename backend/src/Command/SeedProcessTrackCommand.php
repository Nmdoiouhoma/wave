<?php

declare(strict_types=1);

namespace App\Command;

use App\Media\MessageHandler\ProcessTrackHandler;
use App\Message\ProcessTrack;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** DEV ONLY, internal to app:seed: runs the real ProcessTrack handler for one track (lets the seed use all cores). */
#[AsCommand(name: 'app:seed:process-track', description: 'DEV ONLY (internal to app:seed): process one track like the media worker')]
final readonly class SeedProcessTrackCommand
{
    public function __construct(
        private ProcessTrackHandler $handler,
        #[Autowire('%kernel.environment%')]
        private string $env,
    ) {
    }

    public function __invoke(#[Argument(description: 'Track id (uuid)')] string $trackId): int
    {
        if ('dev' !== $this->env) {
            return Command::FAILURE;
        }
        ($this->handler)(new ProcessTrack($trackId));

        return Command::SUCCESS;
    }
}
