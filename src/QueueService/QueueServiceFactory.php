<?php

namespace Drutiny\Bulk\QueueService;

use Psr\Container\ContainerInterface;

class QueueServiceFactory {
    protected array $registry = [
        'amqp' => AmqpService::class,
        'sqs' => AwsSqsService::class
    ];

    public function __construct(
        protected ContainerInterface $container
    )
    {}

    /**
     * Load a queue service.
     */
    public function load(string $name):QueueServiceInterface {
        return $this->container->get($this->registry[$name]);
    }
}