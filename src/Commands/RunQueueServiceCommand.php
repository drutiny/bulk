<?php

namespace Drutiny\Bulk\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RunQueueServiceCommand extends Command
{
    use AMQPQueueTrait;

    protected static $defaultName = 'bulk:run-queue-service';

    // the command description shown when running "php bin/console list"
    protected static $defaultDescription = 'Run the queue service.';

    // ...
    protected function configure(): void
    {
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp('This command allows you to run the RabbitMQ queuing service inside a docker container.')
            ->addQueueOptions()
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // docker run --rm -it --hostname my-rabbit -p 15672:15672 -p 5672:5672 rabbitmq:3-management
        $command = strtr('docker run --rm -it --hostname drutiny-bulk-rabbitmq -p %admin_port%:%admin_port% -p %port%:%port% rabbitmq:3-management', [
          '%admin_port%' => '1' . $input->getOption('ampq-port'),
          '%port%' => $input->getOption('ampq-port'),
        ]);

        passthru($command, $exit_code);
        return $exit_code;
        // return Command::SUCCESS;

        // or return this if some error happened during the execution
        // (it's equivalent to returning int(1))
        // return Command::FAILURE;

        // or return this to indicate incorrect command usage; e.g. invalid options
        // or missing arguments (it's equivalent to returning int(2))
        // return Command::INVALID
    }
}
