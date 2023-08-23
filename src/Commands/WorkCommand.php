<?php

namespace Drutiny\Bulk\Commands;

use DateTime;
use Drutiny\Bulk\Message\ProfileRun;
use Drutiny\Bulk\QueueService\QueueServiceFactory;
use Drutiny\Console\Command\DrutinyBaseCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[AsCommand(
    name: 'bulk:work',
    description: 'Work the profile run queue.'
)]
class WorkCommand extends DrutinyBaseCommand
{

    public function __construct(
        protected QueueServiceFactory $queueServiceFactory,
        protected LoggerInterface $logger,
    )
    {
        parent::__construct();
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
                InputArgument::OPTIONAL,
                'The queue name to work. Defaults to profile:run.',
                'profile:run'
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $drutiny_bin = $GLOBALS['_composer_bin_dir'] . '/drutiny';

        if (!file_exists($drutiny_bin)) {
            $io->error("Cannot find a drutiny binary to run bulk commands.");
            return Command::FAILURE;
        }
        $date = new \DateTime();
        $output->writeln($date->format('H:i:s') . ' Listening to ' . $input->getArgument('queue_name') . ' for new messages...');
        $output->writeln(['']);

        $this->queueServiceFactory
            ->load($input->getOption('queue-service'))
            ->consume($input->getArgument('queue_name'), fn(ProfileRun $message) => $message->execute(
                input: $input,
                output: $output,
                bin: $drutiny_bin,
                logger: $this->logger,
            ));

        return Command::SUCCESS;
    }
}
