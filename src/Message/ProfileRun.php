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
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[Queue(name: 'profile:run')]
class ProfileRun extends AbstractMessage implements ProcessInterface {

    use ProcessTrait;

    public DateTimeInterface $reportingPeriodStart;
    public DateTimeInterface $reportingPeriodEnd;
    
    /**
     * Exit on severity threshold for profile:run command.
     * 
     * Controls which failures return non-zero exit codes:
     * - 32: All dependency failures (LOW+ dependencies)
     * - 36: HIGH and CRITICAL dependency failures only
     * - 40: CRITICAL dependency failures only
     * - 0 or false: Disabled (always exit 0)
     * 
     * Exit codes >= 32 indicate dependency failures that will be marked as not_applicable.
     */
    public int|false $exitOnSeverity;

    public function __construct(
        public string $profile,
        public string $target,
        public array $format = ['html'],
        DateTimeInterface|array $reportingPeriodStart = new DateTime('-3 days'),
        DateTimeInterface|array $reportingPeriodEnd = new DateTime('now'),
        int $priority = 0,
        protected array $meta = [],
        public string $store = 'fs',
        int|false $exitOnSeverity = false,
    )
    {
        $this->reportingPeriodEnd = is_array($reportingPeriodEnd) ? new DateTime($reportingPeriodEnd['date'], new DateTimeZone($reportingPeriodEnd['timezone'])) : $reportingPeriodEnd;
        $this->reportingPeriodStart = is_array($reportingPeriodStart) ? new DateTime($reportingPeriodStart['date'], new DateTimeZone($reportingPeriodStart['timezone'])) : $reportingPeriodStart;
        $this->priority = $priority;
        $this->exitOnSeverity = $exitOnSeverity;
    }

    /**
     * {@inheritDoc}
     * 
     * Exit codes:
     * - 0: Success
     * - 1-8: Policy failures by severity (LOW=1, NORMAL=2, HIGH=4, CRITICAL=8)
     * - 33-40: Dependency failures encoded as 32 + severity weight
     * - 220: TargetNotFoundException
     * - 221: TargetLoadingException  
     * - 222: InvalidTargetException
     */
    public function execute(InputInterface $input, OutputInterface $output, string $bin = 'drutiny', LoggerInterface $logger = new NullLogger):MessageStatus
    {
        $command = 'php -d memory_limit=%s %s profile:run %s %s --no-interaction --reporting-period-start=%s --reporting-period-end=%s --store=%s --pipe';
        $args = [
          escapeshellarg($input->getOption('memory_limit')),
          escapeshellarg($bin),
          escapeshellarg($this->profile),
          escapeshellarg($this->target),
          escapeshellarg($this->reportingPeriodStart->format('Y-m-d H:i:s')),
          escapeshellarg($this->reportingPeriodEnd->format('Y-m-d H:i:s')),
          escapeshellarg($this->store)
        ];
        
        // Add exit-on-severity option if specified
        if ($this->exitOnSeverity !== false) {
            $command .= ' --exit-on-severity=%s';
            $args[] = escapeshellarg($this->exitOnSeverity);
        }
        foreach ($this->format as $format) {
            $command .= ' -f %s';
            $args[] = escapeshellarg($format);
        }
        $command = sprintf($command, ...$args);
        

        $process = Process::fromShellCommandline($command);
        $process->setTty(Process::isTtySupported());
        $process->setPty(Process::isPtySupported());
        $process->setTimeout(null);
        $this->setProcess($process);
        $process->run(function ($type, $buffer) use ($output) {
            (match ($buffer) {
                Process::ERR => $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output,
                default => $output
            })->write($buffer);
        });

        $exit_code = $process->getExitCode();

        $log = "[$exit_code] $command";
        $context = [
            'exit_code' => $exit_code, 
            'command' => $command,
            'milliseconds' => (microtime(true) - $process->getStartTime()) * 1000,
        ];
        $process->isSuccessful() ? $logger->notice($log, $context) : $logger->error($log, $context);

        return match (true) {
            // Dependency failures (exit codes 32-48) are treated as success for queue processing
            // The actual workflow state (NA vs FAILED) is handled by EventSubscriber
            $exit_code >= 32 && $exit_code < 48 => MessageStatus::SUCCESS,
            // Target-specific error codes
            $exit_code === TargetLoadingException::ERROR_CODE => MessageStatus::RETRY,
            $exit_code === InvalidTargetException::ERROR_CODE => MessageStatus::FAIL,
            $exit_code === TargetNotFoundException::ERROR_CODE => MessageStatus::SUCCESS,
            $exit_code === TargetSourceFailureException::ERROR_CODE => MessageStatus::RETRY,
            // All other exit codes (including policy failures) are success for queue processing
            default => MessageStatus::SUCCESS
        };
    }
}