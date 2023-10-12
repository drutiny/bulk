<?php

namespace Drutiny\Bulk\QueueService;

use Drutiny\Attribute\Plugin;
use Drutiny\Attribute\PluginField;
use Drutiny\Bulk\Message\AbstractMessage;
use Drutiny\Bulk\Message\MessageInterface;
use Drutiny\Bulk\Message\MessageStatus;
use Drutiny\Plugin as DrutinyPlugin;
use Drutiny\Plugin\FieldType;
use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

#[Plugin(name: 'queue:amqp')]
#[PluginField(
    name: 'host',
    description: 'Hostname of the AMPQ server.',
    type: FieldType::CONFIG,
    default: 'localhost'
)]
#[PluginField(
    name: 'port',
    description: 'Port of the AMPQ server.',
    type: FieldType::CONFIG,
    validation: 'is_numeric',
    default: 5672
)]
#[PluginField(
    name: 'username',
    description: 'Username for the AMPQ server.',
    type: FieldType::CONFIG,
    default: 'guest'
)]
#[PluginField(
    name: 'password',
    description: 'Password for the AMPQ server.',
    type: FieldType::CONFIG,
    default: 'guest',
)]
class AmqpService extends AbstractQueueService {

    protected AMQPStreamConnection $connection;

    /**
     * @var AMQPChannel[]
     */
    protected array $channel;

    public function __construct(
        public readonly DrutinyPlugin $plugin
    )
    {
        $this->connection = new AMQPStreamConnection(
            host: $plugin->host,
            port: $plugin->port,
            user: $plugin->username,
            password: $plugin->password
        );
    }

    /**
     * Get an AMQP Channel.
     */
    protected function getChannel(string $name):AMQPChannel {
        if (!isset($this->channel[$name])) {
            $this->channel[$name] = $this->connection->channel();

            $this->channel[$name]->queue_declare(
                queue: $name,
                auto_delete: false
            );
        }
        return $this->channel[$name];
    }

    /**
     * {@inheritDoc}
     */
    public function send(MessageInterface $message): void
    {
        $msg = new AMQPMessage($message->asMessage());
        $this->getChannel($message->getQueueName())->basic_publish($msg, '', $message->getQueueName());
    }

    protected function getMessage(string $queue_name): ?MessageInterface
    {
        $amqp_msg = $this->getChannel($queue_name)->basic_get($queue_name);
        if ($amqp_msg === null) {
            usleep(1000);
            return null;
        }
        $message = AbstractMessage::fromMessage($amqp_msg->getBody());
        $message->setQueueName($queue_name);
        $message->setMetadata('amqp.message', $amqp_msg);
        return $message;
    }

    protected function success(MessageStatus $status, MessageInterface $message): void
    {
        match ($status) {
            MessageStatus::RETRY => $message->getMetadata('amqp.message')->reject(),
            default => $message->getMetadata('amqp.message')->ack(),
        };
    }

    protected function failure(Exception $e, MessageInterface $message): void
    {
        $message->getMetadata('amqp.message')->reject();
    }

    public function __destruct()
    {
        foreach ($this->channel as $channel) {
            $channel->close();
        }
        $this->connection->close();
    }
}