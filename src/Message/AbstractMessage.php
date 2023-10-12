<?php

namespace Drutiny\Bulk\Message;

use Drutiny\Bulk\Attribute\Queue;
use Exception;
use ReflectionClass;

abstract class AbstractMessage implements MessageInterface {

    public int $exitCode;

    protected string $queueName;
    protected int $priority = 0;
    protected array $meta = [];
    
    /**
     * {@inheritdoc}
     */
    public function asMessage():string {
        $args = get_object_vars($this);
        unset($args['queueName']);
        return json_encode([
            'class' => get_class($this),
            'arguments' => $args
        ]);
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Metadata that is not persisted through the I/O of the queue.
     */
    public function setMetadata(string $key, mixed $value): void {
        $this->meta[$key] = $value;
    }

    public function getMetadata(string $key): mixed {
        return $this->meta[$key] ?? null;
    }

    public function getMetadataAll(): array {
        return $this->meta;
    }

    /**
     * {@inheritdoc}
     */
    public static function fromMessage(string $payload): self
    {
        $props = json_decode($payload, true);
        $reflection = new ReflectionClass($props['class']);
        return $reflection->newInstance(...$props['arguments']);
    }

    /**
     * {@inheritdoc}
     */
    public function getQueueName():string {
        if (isset($this->queueName)) {
            return $this->queueName;
        }
        $reflection = new ReflectionClass($this);
        $attributes = $reflection->getAttributes(Queue::class);

        foreach ($attributes as $attribute) {
            return $this->queueName = $attribute->newInstance()->name;
        }
        throw new Exception(sprintf("No attribute of type '%s' found on class '%s'", Queue::class, get_class($this)));
    }

    public function setQueueName(string $queue_name):static {
        $this->queueName = $queue_name;
        return $this;
    }
}