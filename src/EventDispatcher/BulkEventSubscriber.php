<?php

namespace Drutiny\Bulk\EventDispatcher;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class BulkEventSubscriber implements EventSubscriberInterface {

    public function __construct(protected ContainerInterface $container)
    {
        
    }

    public static function getSubscribedEvents() {
        return [
            'command.run' => 'commandRun',
        ];
    }

    /**
     * Response to the command.run event.
     */
    public function commandRun(GenericEvent $event) {
        switch ($event->getArgument('output')->getVerbosity()) {
            case OutputInterface::VERBOSITY_VERBOSE:
              $this->container->get('bulk.logger.logfile')->setLevel('NOTICE');
              break;
            case OutputInterface::VERBOSITY_VERY_VERBOSE:
              $this->container->get('bulk.logger.logfile')->setLevel('INFO');
              break;
            case OutputInterface::VERBOSITY_DEBUG:
              $this->container->get('bulk.logger.logfile')->setLevel('DEBUG');
              break;
            default:
              $this->container->get('bulk.logger.logfile')->setLevel('WARNING');
              break;
          }
    }
}