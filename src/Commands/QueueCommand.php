<?php

namespace Drutiny\Bulk\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class QueueCommand extends Command
{
    use AMQPQueueTrait;

    protected static $defaultName = 'bulk:queue';

    // the command description shown when running "php bin/console list"
    protected static $defaultDescription = 'Queues jobs to be run.';

    // ...
    protected function configure(): void
    {
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp('This command allows you to queue targets to be run against a profile.')
            ->addArgument('profile', InputArgument::REQUIRED, 'The profile to audit with')
            ->addArgument('target', InputArgument::OPTIONAL, 'The target to audit')
            ->addOption('target-list', 'l', InputOption::VALUE_OPTIONAL, 'A file of line seperated list of targets to audit.', false)
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'A format to generate Options: json, html, csv, md.', ['json'])
            ->addQueueOptions()
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->getAMQPConnection($input);
        $channel = $connection->channel();
        $channel->queue_declare('profile_run', false, false, false, false);
        $channel->queue_declare('profile_run_error', false, false, false, false);
        $timestamp = time();

        $targets = [];
        if (file_exists($input->getOption('target-list'))) {
            $targets = array_map('trim', file($input->getOption('target-list')));
        }
        $targets[] = $input->getArgument('target');

        foreach (array_filter($targets) as $app) {
            $payload = [
              'target' => $app,
              'profile' => $input->getArgument('profile'),
              'timestamp' => $timestamp,
              'format' => $input->getOption('format'),
            ];
            $msg = new AMQPMessage(json_encode($payload));
            $channel->basic_publish($msg, '', 'profile_run');
            $output->writeln("[x] Sent {$payload['target']} to the queue");
        }
        $channel->close();
        $connection->close();

        return Command::SUCCESS;

        // or return this if some error happened during the execution
        // (it's equivalent to returning int(1))
        // return Command::FAILURE;

        // or return this to indicate incorrect command usage; e.g. invalid options
        // or missing arguments (it's equivalent to returning int(2))
        // return Command::INVALID
    }
}
