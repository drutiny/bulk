<?php

namespace Drutiny\Bulk\Message;

use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Drutiny\Bulk\Attribute\Queue;
use Drutiny\Target\Exception\InvalidTargetException;
use Drutiny\Target\Exception\TargetLoadingException;
use Drutiny\Target\Exception\TargetNotFoundException;
use Drutiny\Target\Exception\TargetSourceFailureException;
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
        int $priority = 0,
        protected array $meta = [],
    )
    {
        $this->reportingPeriodEnd = is_array($reportingPeriodEnd) ? new DateTime($reportingPeriodEnd['date'], new DateTimeZone($reportingPeriodEnd['timezone'])) : $reportingPeriodEnd;
        $this->reportingPeriodStart = is_array($reportingPeriodStart) ? new DateTime($reportingPeriodStart['date'], new DateTimeZone($reportingPeriodStart['timezone'])) : $reportingPeriodStart;
        $this->priority = $priority;
    }

    /**
     * {@inheritDoc}
     */
    public function execute(InputInterface $input, OutputInterface $output, string $bin = 'drutiny', LoggerInterface $logger = new NullLogger):MessageStatus
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
        

        $process = Process::fromShellCommandline($command);
        $process->setTty(true);
        $process->setTimeout(null);
        $process->run();

        $exit_code = $process->getExitCode();

        $log = "[$exit_code] $command";
        $context = [
            'exit_code' => $exit_code, 
            'command' => $command,
            'milliseconds' => (microtime(true) - $process->getStartTime()) * 1000,
        ];
        $process->isSuccessful() ? $logger->notice($log, $context) : $logger->error($log, $context);

        return match ($exit_code) {
            TargetLoadingException::ERROR_CODE => MessageStatus::RETRY,
            InvalidTargetException::ERROR_CODE => MessageStatus::FAIL,
            TargetNotFoundException::ERROR_CODE => MessageStatus::SUCCESS,
            TargetSourceFailureException::ERROR_CODE => MessageStatus::RETRY,
            default => MessageStatus::SUCCESS
        };
    }
}