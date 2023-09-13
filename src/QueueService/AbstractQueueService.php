<?php

namespace Drutiny\Bulk\QueueService;

use Drutiny\Bulk\Message\MessageInterface;
use Drutiny\Bulk\Message\MessageStatus;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

abstract class AbstractQueueService implements QueueServiceInterface {

    use LoggerAwareTrait;

    protected EventDispatcherInterface $eventDispatcher;

    protected function getEventDispatcher(): EventDispatcherInterface {
        return $this->eventDispatcher ?? new EventDispatcher;
    }

    /**
     * Send a message to a queue.
     */
    abstract public function send(MessageInterface $message): void;

    /**
     * Consume a queue with a given callback.
     */
    final public function consume(string $queue_name, callable $callback, callable|bool $continue = true): MessageStatus {
        do {
            $status = MessageStatus::NONE;
            $message = $this->getMessage($queue_name);

            if ($message === NULL) {
                continue;
            }
            try {
                $start = new \DateTime();
                $this->logger?->info("Processing new message to profile:run {profile} {target}.", [
                    'date' => $start->format('Y-m-d H:i:s'),
                    'profile' => $message->profile,
                    'target' => $message->target
                ]);
                $event_name = 'queue.message.new';
                $this->getEventDispatcher()->dispatch($message, $event_name);

                $status = $callback($message);

                $this->logger?->log($status == MessageStatus::RETRY ? 'warning' : 'info', "Message of type " . $message::class . " returned status: {$status->name}.");

                $event_name = 'queue.message.' . strtolower($status->name);
                $this->getEventDispatcher()->dispatch($message, $event_name);
                $this->success($status, $message);
            }
            catch (SkipMessageException $e) {
                $status = MessageStatus::SKIP;
                $this->logger->warning("Skipping message: " . $e->getMessage());
                $event_name = 'queue.message.' . strtolower($status->name);
                $this->getEventDispatcher()->dispatch($message, $event_name);
                $this->skip($e, $e->queueMessage);
            }
            catch (\Exception $e) {
                throw $e;
                $event_name = 'queue.message.error';
                $this->logger->error($e->getMessage());
                $this->getEventDispatcher()->dispatch($message, $event_name);
                $this->failure($e, $message);
            }

            $finish = new \DateTime();
            $interval = $start->diff($finish);

            $this->logger?->info("<info>[{date}]</info> Completed message to profile:run {profile} {target} in {duration}.", [
                'date' => $finish->format('Y-m-d H:i:s'),
                'profile' => $message->profile,
                'target' => $message->target,
                'duration' => $interval->format('%H hours, %I minutes and %S seconds')
            ]);
        }
        while (is_callable($continue) ? $continue($status) : $continue);

        // Return the last message status.
        return $status;
    }

    /**
     * Get a message from the queue.
     */
    abstract protected function getMessage(string $queue_name): ?MessageInterface;

    protected function success(MessageStatus $status, MessageInterface $message): void {}
    protected function failure(\Exception $e, MessageInterface $message): void {}
    protected function skip(SkipMessageException $e, MessageInterface $message): void {}
}