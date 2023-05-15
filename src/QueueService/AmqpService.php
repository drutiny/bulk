<?php

namespace Drutiny\Bulk\QueueService;

use Drutiny\Attribute\Plugin;
use Drutiny\Attribute\PluginField;
use Drutiny\Bulk\Message\AbstractMessage;
use Drutiny\Bulk\Message\MessageInterface;
use Drutiny\Plugin as DrutinyPlugin;
use Drutiny\Plugin\FieldType;
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
class AmqpService implements QueueServiceInterface {

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
            $this->channel[$name]->queue_declare($name, false, false, false, false);
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

    /**
     * {@inheritDoc}
     */
    public function consume(string $queue_name, callable $callback):void {
        $this->getChannel($queue_name)->basic_qos(null, 1, null);
        $this->getChannel($queue_name)->basic_consume($queue_name, '', false, false, false, false, function (AMQPMessage $message) use ($callback) {
            $payload = AbstractMessage::fromMessage($message->getBody());
            $callback($payload) === MessageInterface::SUCCESS ? $message->ack() : $message->reject();
        });

        while ($this->getChannel($queue_name)->is_consuming()) {
            $this->getChannel($queue_name)->wait();
        }
    }

    public function __destruct()
    {
        foreach ($this->channel as $channel) {
            $channel->close();
        }
        $this->connection->close();
    }
}