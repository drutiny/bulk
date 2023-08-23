<?php

namespace Drutiny\Bulk\QueueService;

use Drutiny\Bulk\Message\MessageInterface;
use Drutiny\Bulk\Message\MessageStatus;
use Generator;

interface QueueServiceInterface {

    /**
     * Send a message to a queue.
     */
    public function send(MessageInterface $message): void;

    /**
     * Method to work a a queue until $callback is false.
     * 
     * @param string $queue_name The name of the queue.
     * @param callable|bool $callback A callback to determine if the queue should continue to be worked.
     * @return MessageStatus the status of the last message consumed.
     */
    public function consume(string $queue_name, callable $callback, callable|bool $continue = true): MessageStatus;
}