<?php

namespace Drutiny\Bulk\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class WorkCommand extends Command
{
    use AMQPQueueTrait;

    protected static $defaultName = 'bulk:work';

    // the command description shown when running "php bin/console list"
    protected static $defaultDescription = 'Work the profile run queue.';

    // ...
    protected function configure(): void
    {
        $this
            // the command help shown when running the command with the "--help" option
            ->setName('bulk:work')
            ->setHelp('This command allows you to work targets to be run against a profile.')
            ->addArgument('drutiny-dir', InputArgument::REQUIRED, 'The location where drutiny source is located.')
            ->addArgument('reports-dir', InputArgument::REQUIRED, 'Where the reports should be written too.')
            ->addQueueOptions()
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->getAMQPConnection($input);
        $channel = $connection->channel();

        $channel->queue_declare('profile_run_error', false, false, false, false);

        $working_directory = getcwd();

        $callback = function ($msg) use ($working_directory, $channel, $input, $output) {
            chdir($input->getArgument('drutiny-dir'));
            $payload = json_decode($msg->body);

            $command = 'php -d memory_limit=256M ./vendor/bin/drutiny profile:run %s %s -o %s --exit-on-severity=16';
            $args = [
              escapeshellarg($payload->profile),
              escapeshellarg($payload->target),
              escapeshellarg($input->getArgument('reports-dir')),
            ];
            foreach ($payload->format as $format) {
                $command .= ' -f %s';
                $args[] = $format;
            }
            array_unshift($args, $command);
            $command = call_user_func_array('sprintf', $args);
            $output->writeln(date('[c] ') . $command);
            $payload->worker_time = time();
            passthru($command, $return_var);

            chdir($working_directory);
            $msg->ack();

            if ($return_var !== 0) {
                $payload->exit_code = $return_var;
                $output->writeln("[ERROR] {$payload->target} on {$payload->profile} exited with error code ($return_var).");
                $channel->basic_publish(new AMQPMessage(json_encode($payload)), '', 'profile_run_error');
            }
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
