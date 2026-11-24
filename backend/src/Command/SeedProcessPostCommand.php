<?php

declare(strict_types=1);

namespace App\Command;

use App\Media\MessageHandler\GenerateClipHandler;
use App\Media\MessageHandler\ProcessPostVisualHandler;
use App\Message\GenerateClip;
use App\Message\ProcessPostVisual;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** DEV ONLY, internal to app:seed: runs the real GenerateClip (+ ProcessPostVisual) handlers for one post. */
#[AsCommand(name: 'app:seed:process-post', description: 'DEV ONLY (internal to app:seed): process one post like the media worker')]
final readonly class SeedProcessPostCommand
{
    public function __construct(
        private GenerateClipHandler $clip,
        private ProcessPostVisualHandler $visual,
        #[Autowire('%kernel.environment%')]
        private string $env,
    ) {
    }

    public function __invoke(
        #[Argument(description: 'Post id (uuid)')]
        string $postId,
        #[Argument(description: '1 when the post has a visual')]
        string $hasVisual = '0',
    ): int {
        if ('dev' !== $this->env) {
            return Command::FAILURE;
        }
        ($this->clip)(new GenerateClip($postId));
        if ('1' === $hasVisual) {
            ($this->visual)(new ProcessPostVisual($postId));
        }

        return Command::SUCCESS;
    }
}
