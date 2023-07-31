<?php

namespace Bernard\Event;

use Bernard\Queue;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * @package Bernard
 */
class PingEvent extends GenericEvent
{
    protected $queue;

    /**
     * @param Queue $queue
     */
    public function __construct(Queue $queue)
    {
        $this->queue = $queue;
    }

    /**
     * @return Queue
     */
    public function getQueue()
    {
        return $this->queue;
    }
}
