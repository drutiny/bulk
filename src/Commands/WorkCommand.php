<?php

namespace Drutiny\Bulk\Commands;

use Drutiny\Console\Command\DrutinyBaseCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'bulk:work',
    description: 'Work the profile run queue.'
)]
class WorkCommand extends DrutinyBaseCommand
{
    use AMQPQueueTrait;

    public function __construct(protected LoggerInterface $logger)
    {
        parent::__construct();
    }

    // ...
    protected function configure(): void
    {
        $this
            // the command help shown when running the command with the "--help" option
            ->setName('bulk:work')
            ->setHelp('This command allows you to work targets to be run against a profile.')
            ->addOption('memory_limit', 'm', InputOption::VALUE_OPTIONAL, 'A php memory limit setting for the profile:run process.', '256M')
            ->addQueueOptions()
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $drutiny_bin = $GLOBALS['_composer_bin_dir'] . '/drutiny';

        if (!file_exists($drutiny_bin)) {
            $io->error("Cannot find a drutiny binary to run bulk commands.");
            return Command::FAILURE;
        }

        $connection = $this->getAMQPConnection($input);
        $channel = $connection->channel();

        $channel->queue_declare('profile_run_error', false, false, false, false);


        $callback = function ($msg) use ($drutiny_bin, $channel, $input, $output) {
            $payload = json_decode($msg->body);

            $command = [
                'php', 
                '-d', 'memory_limit=' . $input->getOption('memory_limit'),
                $drutiny_bin,
                'profile:run', $payload->profile,
                $payload->target,
                '--exit-on-severity=16'
            ];
            foreach ($payload->format as $format) {
                $command[] = '-f';
                $command[] = $format;
            }
            $process = new Process($command);
            $process->setTimeout(3600);
            $process->setPty(true);

            $output->writeln(date('[c] ') . $process->getCommandLine());
            $payload->worker_time = time();

            try {
                $process->mustRun(function ($type, $buffer) use ($output) {
                    $output->write($buffer);
                });
            }
            catch (ProcessFailedException $e) {
                $this->logger->error("[ERROR] {$payload->target} on {$payload->profile} exited with error code (".$process->getExitCode().").", [
                    'target' => $payload->target,   
                    'profile' => $payload->profile,
                    'return_var' => $process->getExitCode(),
                    'stderr' => $process->getErrorOutput(),
                    'stdout' => $process->getOutput(),
                ]);
                $output->writeln("[ERROR] {$payload->target} on {$payload->profile} exited with error code (".$process->getExitCode().").");
            }
            $msg->ack();
        };

        $channel->basic_qos(null, 1, null);
        $channel->basic_consume('profile_run', '', false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();

        return Command::SUCCESS;
    }
}
