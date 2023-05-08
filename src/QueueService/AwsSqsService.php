<?php

namespace Drutiny\Bulk\QueueService;

use AsyncAws\Sqs\Input\ChangeMessageVisibilityRequest;
use AsyncAws\Sqs\Input\DeleteMessageRequest;
use AsyncAws\Sqs\Input\GetQueueUrlRequest;
use AsyncAws\Sqs\Input\ReceiveMessageRequest;
use AsyncAws\Sqs\Input\SendMessageRequest;
use AsyncAws\Sqs\SqsClient;
use Drutiny\Attribute\Plugin;
use Drutiny\Attribute\PluginField;
use Drutiny\Bulk\Message\AbstractMessage;
use Drutiny\Bulk\Message\MessageInterface;
use Drutiny\Plugin as DrutinyPlugin;
use Drutiny\Plugin\FieldType;
use Exception;

#[Plugin(name: 'queue:sqs')]
#[PluginField(
    name: 'accessKeyId',
    description: 'AWS accessKeyId',
    type: FieldType::CREDENTIAL,
)]
#[PluginField(
    name: 'accessKeySecret',
    description: 'AWS accessKeySecret',
    type: FieldType::CREDENTIAL,
)]
#[PluginField(
    name: 'region',
    description: 'AWS region',
    type: FieldType::CONFIG,
)]
class AwsSqsService implements QueueServiceInterface {

    protected SqsClient $client;
    
    /**
     * @var string[]
     */
    protected array $queueUrls;

    public function __construct(public readonly DrutinyPlugin $plugin)
    {
        $this->client =  new SqsClient([
            'accessKeyId' => $plugin->accessKeyId,
            'accessKeySecret' => $plugin->accessKeySecret,
            'region' => $plugin->region,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function send(MessageInterface $message): void
    {
        $this->client->sendMessage(new SendMessageRequest([
            'QueueUrl' => $this->getQueueUrl($message->getQueueName()),
            'MessageBody' => $message->asMessage(),
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
            $result = $this->client->receiveMessage(new ReceiveMessageRequest([
                'QueueUrl' => $this->getQueueUrl($queue_name),
                'WaitTimeSeconds' => 20,
                'MaxNumberOfMessages' => 5,
            ]));

            foreach ($result->getMessages() as $message) {
                try {
                    $payload = AbstractMessage::fromMessage($message->getBody());
                    $callback($payload);

                    // When finished, delete the message
                    $this->client->deleteMessage(new DeleteMessageRequest([
                        'QueueUrl' => $this->getQueueUrl($queue_name),
                        'ReceiptHandle' => $message->getReceiptHandle(),
                    ]));
                }
                catch (Exception $e) {
                    $this->client->changeMessageVisibility(new ChangeMessageVisibilityRequest([
                        'QueueUrl' => $this->getQueueUrl($queue_name),
                        'ReceiptHandle' => $message->getReceiptHandle(),
                        'VisibilityTimeout' => 0,
                    ]));
                }
            }
        }
    }
}
