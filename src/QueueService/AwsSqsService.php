<?php

namespace Drutiny\Bulk\QueueService;

use Aws\Sqs\Exception\SqsException;
use Aws\Sqs\SqsClient;
use Drutiny\Bulk\Message\AbstractMessage;
use Drutiny\Bulk\Message\MessageInterface;
use Drutiny\Bulk\Message\MessageStatus;
use Drutiny\Settings;
use Exception;
use Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class AwsSqsService extends AbstractQueueService {

    /**
     * @var int The number of seconds to delay message polls.
     */
    const DEFAULT_POLL_DELAY = 20;
    
    /**
     * @var string[]
     */
    protected array $queueUrls;
    private array $lastMessageTime;
    private int $pollDelay;

    protected int $defaultVisibilityTimeout = 600;

    public function __construct(
        Logger $logger,
        Settings $settings,
        protected SqsClient $client,
        protected EventDispatcherInterface $eventDispatcher
    )
    {
        $this->logger = $logger->withName('sqs');
        $this->defaultVisibilityTimeout = $settings->has('queue.sqs.VisibilityTimeout') ? $settings->get('queue.sqs.VisibilityTimeout') : $this->defaultVisibilityTimeout;
        $this->pollDelay = $settings->has('queue.sqs.pollDelay') ?   $settings->get('queue.sqs.pollDelay') : self::DEFAULT_POLL_DELAY;
    }

    /**
     * {@inheritDoc}
     */
    public function send(MessageInterface $message): void
    {
        $this->client->sendMessage([
            'QueueUrl' => $this->getQueueUrl($message->getQueueName()),
            'MessageBody' => $message->asMessage(),
            'MessageGroupId' => substr(hash('md5', $message->asMessage()), 0, 8)
        ]);
    }

    /**
     * @var string[]
     */
    public function getAttributes(string $queue_name):array {
        $result = $this->client->getQueueAttributes([
            'QueueUrl' => $this->getQueueUrl($queue_name),
            'AttributeNames' => [
                "ApproximateNumberOfMessages",
                "ApproximateNumberOfMessagesNotVisible",
                "ApproximateNumberOfMessagesDelayed",
                "CreatedTimestamp",
                "LastModifiedTimestamp",
                "VisibilityTimeout",
                "MaximumMessageSize",
                "MessageRetentionPeriod",
                "DelaySeconds",
                "ReceiveMessageWaitTimeSeconds",
                "SqsManagedSseEnabled",
                "FifoQueue",
                "DeduplicationScope",
                "FifoThroughputLimit",
                "ContentBasedDeduplication",
            ]
        ]);
        return $result->toArray();
    }

    /**
     * {@inheritdoc}
     */
    protected function getMessage(string $queue_name): ?MessageInterface
    {
        // If the last receiveMessage attempt was within the POLL_DELAY timeframe
        // then we need to wait before we try again.
        $lastMessageTime = $this->lastMessageTime[$queue_name] ?? null;
        while (isset($lastMessageTime) && $lastMessageTime > (time() - $this->pollDelay)) {
            sleep(1);
        }

        $this->logger->debug("Requesting to recieve messages from " . $this->getQueueUrl($queue_name));

        $result = $this->client->receiveMessage([
            'QueueUrl' => $this->getQueueUrl($queue_name),
            'WaitTimeSeconds' => 5,
            'MaxNumberOfMessages' => 1,
            'VisibilityTimeout' => $this->defaultVisibilityTimeout
        ]);

        $this->lastMessageTime[$queue_name] = time();
        
        $messages = $result->get('Messages');
        
        if (empty($messages)) {
            return null;
        }

        $message = AbstractMessage::fromMessage($messages[0]['Body']);
        $message->setQueueName($queue_name);
        $message->setMetadata('ReceiptHandle', $messages[0]['ReceiptHandle']);
        return $message;
    }

    /**
     * Get the AWS Queue URL.
     */
    protected function getQueueUrl(string $queue_name):string {
        $this->queueUrls[$queue_name] ??= $this->client->getQueueUrl([
            'QueueName' => $queue_name,
        ])->get('QueueUrl');
        return $this->queueUrls[$queue_name];
    }

    /**
     * {@inheritdoc}
     */
    protected function success(MessageStatus $status, MessageInterface $message): void
    {
        try {
            match ($status) {
                // Worker failed to handle message. Return to queue to try again.
                MessageStatus::RETRY => $this->client->changeMessageVisibility([
                    'QueueUrl' => $this->getQueueUrl($message->getQueueName()),
                    'ReceiptHandle' => $message->getMetadata('ReceiptHandle'),
                    'VisibilityTimeout' => 0,
                ]),
                // When finished, delete the message
                default => $this->client->deleteMessage([
                    'QueueUrl' => $this->getQueueUrl($message->getQueueName()),
                    'ReceiptHandle' => $message->getMetadata('ReceiptHandle'),
                ])
            };
        }
        // This can happen when the message timeout to delete the message expires.
        catch (SqsException $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function skip(SkipMessageException $e, MessageInterface $message): void
    {
        $this->success(MessageStatus::SKIP, $message);
    }

    /**
     * {@inheritdoc}
     */
    protected function failure(Exception $e, MessageInterface $message): void
    {
        $this->client->changeMessageVisibility([
            'QueueUrl' => $this->getQueueUrl($message->getQueueName()),
            'ReceiptHandle' => $message->getMetadata('ReceiptHandle'),
            'VisibilityTimeout' => 0,
        ]);
    }
}
