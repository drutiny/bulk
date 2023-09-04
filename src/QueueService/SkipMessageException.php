<?php

namespace Drutiny\Bulk\QueueService;

use Drutiny\Bulk\Message\MessageInterface;

class SkipMessageException extends \Exception {
    public function __construct(public readonly MessageInterface $queueMessage, string $reason, ?\Throwable $previous = null, int $code = 0)
    {
        parent::__construct($reason, $code, $previous);
    }
}