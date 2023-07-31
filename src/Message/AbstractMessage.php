<?php

namespace Drutiny\Bulk\Message;

use Drutiny\Bulk\Attribute\Queue;
use Exception;
use ReflectionClass;

abstract class AbstractMessage implements MessageInterface {

    public int $exitCode;

    protected string $queueName;
    protected int $priority = 0;
    
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