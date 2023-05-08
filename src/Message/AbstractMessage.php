<?php

namespace Drutiny\Bulk\Message;

use Drutiny\Attribute\Name;
use Drutiny\Bulk\Attribute\Queue;
use Exception;
use ReflectionClass;

abstract class AbstractMessage implements MessageInterface {
    
    /**
     * {@inheritdoc}
     */
    public function asMessage():string {
        return json_encode([
            'class' => get_class($this),
            'arguments' => get_object_vars($this)
        ]);
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
        $reflection = new ReflectionClass($this);
        $attributes = $reflection->getAttributes(Queue::class);

        foreach ($attributes as $attribute) {
            $queue = $attribute->newInstance();
            return $queue->name;
        }
        throw new Exception(sprintf("No attribute of type '%s' found on class '%s'", Queue::class, get_class($this)));
    }
}