<?php

namespace Drutiny\Bulk\Commands;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use PhpAmqpLib\Connection\AMQPStreamConnection;

trait AMQPQueueTrait
{
    protected function addQueueOptions()
    {
        $this->addOption(
            'ampq-host',
            'H',
            InputOption::VALUE_OPTIONAL,
            'Hostname of the AMPQ server.',
            'localhost'
        );
        $this->addOption(
            'ampq-port',
            'p',
            InputOption::VALUE_OPTIONAL,
            'Port of the AMPQ server.',
            5672
        );
        $this->addOption(
            'ampq-user',
            'u',
            InputOption::VALUE_OPTIONAL,
            'Username for the AMPQ server.',
            'guest'
        );
        $this->addOption(
            'ampq-password',
            'w',
            InputOption::VALUE_OPTIONAL,
            'Password for the AMPQ server.',
            'guest'
        );
        return $this;
    }

    protected function getAMQPConnection(InputInterface $input): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            $input->getOption('ampq-host'),
            $input->getOption('ampq-port'),
            $input->getOption('ampq-user'),
            $input->getOption('ampq-password')
        );
    }
}
