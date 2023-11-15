<?php

namespace Drutiny\Bulk\Commands;

use Drutiny\Bulk\Message\ProfileRun;
use Drutiny\Bulk\QueueService\QueueServiceFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bulk:queue',
    description: 'Queues jobs to be run.'
)]
class QueueCommand extends Command
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
            ->setName('bulk:profile:run')
            ->setDescription('Queues profile:run jobs to be run.')
            ->setHelp('This command allows you to queue targets to be run against a profile.')
            ->addArgument('profile', InputArgument::REQUIRED, 'The profile to audit with')
            ->addArgument('target', InputArgument::OPTIONAL, 'The target to audit')
            ->addOption(
                'queue-name',
                null,
                InputOption::VALUE_OPTIONAL,
                'The queue name to work. Defaults to profile:run.',
            )
            ->addOption(
                'queue-service', 
                's', 
                InputOption::VALUE_OPTIONAL,
                'The queue service to connect to for messages (e.g. amqp or sqs).',
                'amqp'
            )
            ->addOption('target-list', 'l', InputOption::VALUE_OPTIONAL, 'A file of line seperated list of targets to audit.', false)
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'A format to generate Options: json, html, csv, md.', ['json'])
            ->addOption(
                'store',
                null,
                InputOption::VALUE_OPTIONAL,
                'The handler to use to store the formatted output.',
                'fs'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $targets = [];
        if (file_exists($input->getOption('target-list'))) {
            $targets = array_map('trim', file($input->getOption('target-list')));
        }
        $targets[] = $input->getArgument('target');

        $queue = $this->queueServiceFactory->load($input->getOption('queue-service'));

        $io = new SymfonyStyle($input, $output);

        foreach (array_filter($targets) as $app) {
            $message = new ProfileRun(
                profile: $input->getArgument('profile'),
                target: $app,
                format: $input->getOption('format'),
                store: $input->getOption('store')
            );

            if ($queue_name = $input->getOption('queue-name')) {
                $message->setQueueName($queue_name);
            }

            $queue->send($message);
            $io->info("Bulk run profile:run of {$message->profile} against {$message->target}.");
        }

        return Command::SUCCESS;
    }
}
