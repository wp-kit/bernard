<?php

namespace Bernard;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Bernard\Event\EnvelopeEvent;
use Bernard\Event\PingEvent;
use Bernard\Event\RejectEnvelopeEvent;

/**
 * @package Consumer
 */
class Consumer
{
    protected $router;
    protected $dispatcher;
    protected $shutdown = false;
    protected $pause = false;
    protected $configured = false;
    protected $options = [
        'max-runtime' => PHP_INT_MAX,
        'max-messages' => null,
        'stop-when-empty' => false,
        'stop-on-error' => false,
    ];

    /**
     * @param Router                   $router
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(Router $router, EventDispatcherInterface $dispatcher)
    {
        $this->router = $router;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Starts an infinite loop calling Consumer::tick();
     *
     * @param Queue $queue
     * @param array $options
     */
    public function consume(Queue $queue, array $options = [])
    {
        declare (ticks = 1);

        $this->bind();

        while ($this->tick($queue, $options)) {
            // NO op
        }
    }

    /**
     * Returns true do indicate it should be run again or false to indicate
     * it should not be run again.
     *
     * @param Queue $queue
     * @param array $options
     *
     * @return bool
     */
    public function tick(Queue $queue, array $options = [])
    {
        $this->configure($options);

        if ($this->shutdown) {
            return false;
        }

        if (microtime(true) > $this->options['max-runtime']) {
            return false;
        }

        if ($this->pause) {
            return true;
        }

        $this->dispatcher->dispatch(new PingEvent($queue), BernardEvents::PING);

        if (!$envelope = $queue->dequeue()) {
            return !$this->options['stop-when-empty'];
        }

        $this->invoke($envelope, $queue);

        if (null === $this->options['max-messages']) {
            return true;
        }

        return (boolean) --$this->options['max-messages'];
    }

    /**
     * Mark Consumer as shutdown
     */
    public function shutdown()
    {
        $this->shutdown = true;
    }

    /**
     * Pause consuming
     */
    public function pause()
    {
        $this->pause = true;
    }

    /**
     * Resume consuming
     */
    public function resume()
    {
        $this->pause = false;
    }

    /**
     * Until there is a real extension point to doing invoked stuff, this can be used
     * by wrapping the invoke method.
     *
     * @param Envelope $envelope
     * @param Queue    $queue
     *
     * @throws \Exception
     * @throws \Throwable
     */
    public function invoke(Envelope $envelope, Queue $queue)
    {
        try {
            $this->dispatcher->dispatch(new EnvelopeEvent($envelope, $queue), BernardEvents::INVOKE);

            // for 5.3 support where a function name is not a callable
            call_user_func($this->router->map($envelope), $envelope->getMessage());

            // We successfully processed the message.
            $queue->acknowledge($envelope);

            $this->dispatcher->dispatch(new EnvelopeEvent($envelope, $queue), BernardEvents::ACKNOWLEDGE);
        } catch (\Throwable $error) {
            $this->rejectDispatch($error, $envelope, $queue);
        } catch (\Exception $exception) {
            $this->rejectDispatch($exception, $envelope, $queue);
        }
    }

    /**
     * @param array $options
     *
     * @return void
     */
    protected function configure(array $options)
    {
        if ($this->configured) {
            return $this->options;
        }

        $this->options = array_filter($options) + $this->options;
        $this->options['max-runtime'] += microtime(true);
        $this->configured = true;
    }

    /**
     * Setup signal handlers for unix signals.
     *
     * If the process control extension does not exist (e.g. on Windows), ignore the signal handlers.
     * The difference is that when terminating the consumer, running processes will not stop gracefully
     * and will terminate immediately.
     */
    protected function bind()
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT,  [$this, 'shutdown']);
            pcntl_signal(SIGQUIT, [$this, 'shutdown']);
            pcntl_signal(SIGUSR2, [$this, 'pause']);
            pcntl_signal(SIGCONT, [$this, 'resume']);
        }
    }

    /**
     * @param \Throwable|\Exception $exception note that the type-hint is missing due to PHP 5.x compat
     *
     * @param Envelope              $envelope
     * @param Queue                 $queue
     *
     * @throws \Exception
     * @throws \Throwable
     */
    private function rejectDispatch($exception, Envelope $envelope, Queue $queue)
    {
        // Make sure the exception is not interfering.
        // Previously failing jobs handling have been moved to a middleware.
        //
        // Emit an event to let others log that exception
        $this->dispatcher->dispatch(new RejectEnvelopeEvent($envelope, $queue, $exception), BernardEvents::REJECT);

        if ($this->options['stop-on-error']) {
            throw $exception;
        }
    }
}
