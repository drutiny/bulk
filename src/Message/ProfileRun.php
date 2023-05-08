<?php

namespace Drutiny\Bulk\Message;

use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Drutiny\Bulk\Attribute\Queue;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[Queue(name: 'profile:run')]
class ProfileRun extends AbstractMessage {

    public readonly DateTimeInterface $reportingPeriodStart;
    public readonly DateTimeInterface $reportingPeriodEnd;

    public function __construct(
        public readonly string $profile,
        public readonly string $target,
        public readonly array $format = ['html'],
        DateTimeInterface|array $reportingPeriodStart = new DateTime('-3 days'),
        DateTimeInterface|array $reportingPeriodEnd = new DateTime('now'),
    )
    {
        $this->reportingPeriodEnd = is_array($reportingPeriodEnd) ? new DateTime($reportingPeriodEnd['date'], new DateTimeZone($reportingPeriodEnd['timezone'])) : $reportingPeriodEnd;
        $this->reportingPeriodStart = is_array($reportingPeriodStart) ? new DateTime($reportingPeriodStart['date'], new DateTimeZone($reportingPeriodStart['timezone'])) : $reportingPeriodStart;
    }

    /**
     * {@inheritDoc}
     */
    public function execute(InputInterface $input, OutputInterface $output, string $bin = 'drutiny', LoggerInterface $logger = new NullLogger):int
    {
        $command = 'php -d memory_limit=%s %s profile:run %s %s --exit-on-severity=16 --reporting-period-start=%s --reporting-period-end=%s';
        $args = [
          escapeshellarg($input->getOption('memory_limit')),
          escapeshellarg($bin),
          escapeshellarg($this->profile),
          escapeshellarg($this->target),
          escapeshellarg($this->reportingPeriodStart->format('Y-m-d H:i:s')),
          escapeshellarg($this->reportingPeriodEnd->format('Y-m-d H:i:s'))
        ];
        foreach ($this->format as $format) {
            $command .= ' -f %s';
            $args[] = escapeshellarg($format);
        }
        $command = sprintf($command, ...$args);
        $logger->info($command);

        $process = Process::fromShellCommandline($command);
        $process->setTty(true);
        $process->mustRun();

        return $process->getExitCode();
    }
}