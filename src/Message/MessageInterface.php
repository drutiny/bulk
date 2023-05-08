<?php

namespace Drutiny\Bulk\Message;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

interface MessageInterface {
    public function asMessage():string;
    public static function fromMessage(string $payload): self;
    public function getQueueName():string;
    public function execute(InputInterface $input, OutputInterface $output, string $bin = 'drutiny');
}