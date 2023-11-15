<?php

namespace Drutiny\Bulk\Message;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

interface MessageInterface {
    public function asMessage():string;
    public static function fromMessage(string $payload): self;
    public function getQueueName():string;
    public function execute(InputInterface $input, OutputInterface $output, string $bin = 'drutiny',  LoggerInterface $logger = new NullLogger): MessageStatus;
    public function getPriority(): int;
    /**
     * Metadata that is not persisted through the I/O of the queue.
     */
    public function setMetadata(string $key, mixed $value): void;

    public function getMetadata(string $key): mixed;
}