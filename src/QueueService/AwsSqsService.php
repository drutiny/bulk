<?php

namespace Drutiny\Bulk\QueueService;

use AsyncAws\Core\AwsClientFactory;
use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\Core\Result;
use AsyncAws\Sqs\Input\ChangeMessageVisibilityRequest;
use AsyncAws\Sqs\Input\DeleteMessageRequest;
use AsyncAws\Sqs\Input\GetQueueUrlRequest;
use AsyncAws\Sqs\Input\ReceiveMessageRequest;
use AsyncAws\Sqs\Input\SendMessageRequest;
use AsyncAws\Sqs\SqsClient;
use Drutiny\Bulk\Message\AbstractMessage;
use Drutiny\Bulk\Message\MessageInterface;
use Drutiny\Settings;
use Exception;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

class AwsSqsService implements QueueServiceInterface {

    protected SqsClient $client;
    protected LoggerInterface $logger;
    
    /**
     * @var string[]
     */
    protected array $queueUrls;

    protected int $defaultVisibilityTimeout = 600;

    public function __construct(
        Logger $logger,
        protected EventDispatcher $eventDispatcher,
        Settings $settings,
        AwsClientFactory $awsFactory
    )
    {
        $this->logger = $logger->withName('queue');
        $this->client =  $awsFactory->sqs();
        $this->defaultVisibilityTimeout = $settings->has('queue.sqs.VisibilityTimeout') ? $settings->get('queue.sqs.VisibilityTimeout') : $this->defaultVisibilityTimeout;
    }

    /**
     * {@inheritDoc}
     */
    public function send(MessageInterface $message): void
    {
        $this->client->sendMessage(new SendMessageRequest([
            'QueueUrl' => $this->getQueueUrl($message->getQueueName()),
            'MessageBody' => $message->asMessage(),
            'MessageGroupId' => substr(hash('md5', $message->asMessage()), 0, 8)
        ]));
    }

    /**
     * Get the AWS Queue URL.
     */
    protected function getQueueUrl(string $queue_name):string {
        $this->queueUrls[$queue_name] ??= $this->client->getQueueUrl(new GetQueueUrlRequest([
            'QueueName' => $queue_name,
        ]))->getQueueUrl();
        return $this->queueUrls[$queue_name];
    }

    /**
     * {@inheritDoc}
     */
    public function consume(string $queue_name, callable $callback): void
    {
        while (true) {
            $this->logger->debug("Requesting to recieve messages from " . $this->getQueueUrl($queue_name));
            $result = $this->client->receiveMessage(new ReceiveMessageRequest([
                'QueueUrl' => $this->getQueueUrl($queue_name),
                'WaitTimeSeconds' => 20,
                'MaxNumberOfMessages' => 1,
                'VisibilityTimeout' => $this->defaultVisibilityTimeout
            ]));

            foreach ($result->getMessages() as $message) {
                try {
                    $this->logger->info("Got new message from SQS for queue $queue_name.");
                    $payload = AbstractMessage::fromMessage($message->getBody());

                    $result = match ($action = $callback($payload)) {
                        // Worker failed to handle message. Return to queue to try again.
                        MessageInterface::RETRY => $this->client->changeMessageVisibility(new ChangeMessageVisibilityRequest([
                            'QueueUrl' => $this->getQueueUrl($queue_name),
                            'ReceiptHandle' => $message->getReceiptHandle(),
                            'VisibilityTimeout' => 0,
                        ])),
                        // When finished, delete the message
                        default => $this->client->deleteMessage(new DeleteMessageRequest([
                            'QueueUrl' => $this->getQueueUrl($queue_name),
                            'ReceiptHandle' => $message->getReceiptHandle(),
                        ]))
                    };

                    if ($action === MessageInterface::RETRY) {
                        $message_id = $message->getMessageId();
                        $this->logger->warning("Message $message_id from SQS queue $queue_name was returned to queue to be retried.");
                    }

                    assert($result instanceof Result);
                }
                catch (Exception $e) {
                    $this->logger->error($e->getMessage());
                    $this->eventDispatcher->dispatch((object) ['exception' => $e, 'message' => $payload, 'queue_name' => $queue_name], "queue.sqs.retry");

                    // This may fail if the message receipt has expired.
                    $result = $this->client->changeMessageVisibility(new ChangeMessageVisibilityRequest([
                        'QueueUrl' => $this->getQueueUrl($queue_name),
                        'ReceiptHandle' => $message->getReceiptHandle(),
                        'VisibilityTimeout' => 0,
                    ]));
                }
                try {
                    $result?->resolve();
                    $result = null;
                }
                catch (ClientException $e) {
                    $this->logger->error($e->getMessage());
                }
            }
        }
    }
}
