<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ListenGrantRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/** Daily (WaveSchedule): listen grants older than 1 day (DATA_MODEL §11, ADR 010). */
#[AsCommand(name: 'wave:listen-grants:purge', description: 'Delete listen grants older than one day')]
final readonly class PurgeListenGrantsCommand
{
    public function __construct(private ListenGrantRepository $grants, private ClockInterface $clock)
    {
    }

    public function __invoke(OutputInterface $output): int
    {
        $deleted = $this->grants->purgeOlderThan($this->clock->now()->sub(new \DateInterval('P1D')));
        $output->writeln(\sprintf('%d listen grant(s) purged.', $deleted));

        return Command::SUCCESS;
    }
}
