<?php

namespace Drutiny\Bulk\Commands;

use Drutiny\Attribute\Autoload;
use Drutiny\Bulk\Message\MessageInterface;
use Drutiny\Bulk\Message\MessageStatus;
use Drutiny\Bulk\Message\ProcessInterface;
use Drutiny\Bulk\Message\ProfileRun;
use Drutiny\Bulk\QueueService\QueueServiceFactory;
use Drutiny\Console\Command\AbstractBaseCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ExceptionInterface;

#[AsCommand(
    name: 'bulk:work',
    description: 'Work the profile run queue.'
)]
class WorkCommand extends AbstractBaseCommand implements SignalableCommandInterface
{

    protected QueueServiceFactory $queueServiceFactory;

    #[Autoload(service: 'bulk.logger')]
    protected LoggerInterface $logger;

    #[Autoload(false)]
    private MessageInterface $currentMessage;

    /**
     * {@inheritdoc}
     */
    public function getSubscribedSignals(): array
    {
        return [\SIGINT, \SIGTERM, \SIGUSR1];
    }

    /**
     * {@inheritdoc}
     */
    public function handleSignal(int $signal)
    {

        if (!isset($this->currentMessage)) {
            return;
        }
        if (!($this->currentMessage instanceof ProcessInterface)) {
            return;
        }
        
        try {
            $this->currentMessage->handleSignal($signal);
        }
        catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage());
        }

        return $signal;
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this
            // the command help shown when running the command with the "--help" option
            ->setName('bulk:work')
            ->setDescription('Work the profile run queue.')
            ->setHelp('This command allows you to work targets to be run against a profile.')
            ->addArgument(
                'queue_name',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'The queue name to work. Defaults to profile:run.',
            )
            ->addOption(
                'memory_limit', 
                'm', 
                InputOption::VALUE_OPTIONAL, 
                'A php memory limit setting for the profile:run process.', 
                '256M'
            )
            ->addOption(
                'queue-service', 
                's', 
                InputOption::VALUE_OPTIONAL,
                'The queue service to connect to for messages (e.g. amqp or sqs).',
                'amqp'
            );
    }

    /**
     * {@inheritDoc}
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $drutiny_bin = $GLOBALS['_composer_bin_dir'] . '/drutiny';

        if (!file_exists($drutiny_bin)) {
            $io->error("Cannot find a drutiny binary to run bulk commands.");
            return Command::FAILURE;
        }

        $queueService = $this->queueServiceFactory->load($input->getOption('queue-service'));

        // Track sequential retries.
        $sequential_retries = 0;
        $queues = $input->getArgument('queue_name');
        $priority = 0;
        
        do {
            $date = new \DateTime();

            if (count($queues) > 1) {
                // Communicate that we're checking a different queue.
                $output->writeln(strtr($date->format('H:i:s') . " Consuming messages from queue", [
                    'queue' => $queues[$priority]
                ]));
            }

            $last_message_status = $queueService->consume(
                queue_name: $queues[$priority], 
                callback: function (ProfileRun $message) use ($input, $output, $drutiny_bin) {
                    $this->currentMessage = $message;
                    return $this->currentMessage->execute(
                        input: $input,
                        output: $output,
                        bin: $drutiny_bin,
                        logger: $this->logger,
                    );
                },
                // Only loop if queue priority is 0 and the queue delivered a message.
                continue: function (MessageStatus $status) use ($priority, $sequential_retries) {
                    if ($status == MessageStatus::RETRY) {
                        $sequential_retries++;
                    }
                    // Do not reset or increment sequential_retries on a null message.
                    elseif ($status == MessageStatus::NONE) {
                        return false;
                    }
                    else {
                        $sequential_retries = 0;
                    }
                    if ($sequential_retries >= 10) {
                        return false;
                    }
                    return $priority == 0;
                }
            );

            // If the queue checked was not top priority go back and check the top queue again.
            if ($priority > 0) {
                $priority = 0;
            }
            // Exit because queue is empty. Check next priority.
            elseif ($last_message_status == MessageStatus::NONE) {
                $this->logger->notice("No messages in {queue}. Checking queue in next priority.", ['queue' => $queues[$priority]]);
                $priority++;
            }

            // Reset priority when no queue for a given priority.
            if (!isset($queues[$priority])) {
                $priority = 0;
            }

            if ($sequential_retries >= 3) {
                $this->logger->warning("Processed 3 messages in a row that all returned a RETRY status. Perhaps there is something wrong with this worker. Exiting.");
                return Command::FAILURE;
            }
        }
        while (true);

        return Command::SUCCESS;
    }
}
