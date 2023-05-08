<?php

namespace Drutiny\Bulk\QueueService;

use Drutiny\Bulk\Message\MessageInterface;
use Generator;

interface QueueServiceInterface {

    /**
     * Send a message to a queue.
     */
    public function send(MessageInterface $message): void;

    /**
     * Consume a queue with a given callback.
     */
    public function consume(string $queue_name, callable $callback): void;
}