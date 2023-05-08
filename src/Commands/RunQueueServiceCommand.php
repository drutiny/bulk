<?php

namespace Drutiny\Bulk\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Drutiny\Attribute\UsePlugin;
use Drutiny\Plugin;


#[AsCommand(
    name: 'bulk:run-queue-service',
    description: 'Run the queue service.'
)]
#[UsePlugin('queue:amqp')]
class RunQueueServiceCommand extends Command
{

    public function __construct(protected Plugin $plugin)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('bulk:run-docker-rabbitmq')
            ->setDescription('Runs a rabbitMQ server install a docker container.')
            ->setHelp('This command allows you to run the RabbitMQ queuing service inside a docker container.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->plugin->host != 'localhost') {
            $io->error("AMQP queue service is configured to connect to {$this->plugin->host} and would not connect to rabbitMQ server on localhost.");
            return Command::INVALID;
        }

        $command = strtr('docker run --rm -it --hostname drutiny-bulk-rabbitmq -p %admin_port%:%admin_port% -p %port%:%port% rabbitmq:3-management', [
          '%admin_port%' => '1' . $this->plugin->port,
          '%port%' => $this->plugin->port,
        ]);

        $process = Process::fromShellCommandline($command);
        $process->setTty(true);
        $process->setTimeout(null);
        $process->mustRun();
        return $process->getExitCode();
    }
}
