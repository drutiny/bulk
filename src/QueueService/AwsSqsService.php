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

class AwsSqsService extends AbstractQueueService {
    
    /**
     * @var string[]
     */
    protected array $queueUrls;

    protected int $defaultVisibilityTimeout = 600;

    public function __construct(
        Logger $logger,
        Settings $settings,
        protected SqsClient $client
    )
    {
        $this->logger = $logger->withName('sqs');
        $this->defaultVisibilityTimeout = $settings->has('queue.sqs.VisibilityTimeout') ? $settings->get('queue.sqs.VisibilityTimeout') : $this->defaultVisibilityTimeout;
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
     * {@inheritdoc}
     */
    protected function getMessage(string $queue_name): ?MessageInterface
    {
        $this->logger->debug("Requesting to recieve messages from " . $this->getQueueUrl($queue_name));

        $result = $this->client->receiveMessage([
            'QueueUrl' => $this->getQueueUrl($queue_name),
            'WaitTimeSeconds' => 20,
            'MaxNumberOfMessages' => 1,
            'VisibilityTimeout' => $this->defaultVisibilityTimeout
        ]);
        
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

    protected function failure(Exception $e, MessageInterface $message): void
    {
        $this->client->changeMessageVisibility([
            'QueueUrl' => $this->getQueueUrl($message->getQueueName()),
            'ReceiptHandle' => $message->getMetadata('ReceiptHandle'),
            'VisibilityTimeout' => 0,
        ]);
    }
}
