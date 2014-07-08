<?php
namespace Icicle\Loop;

use Event;
use EventBase;
use Icicle\Loop\Exception\RunningException;
use Icicle\Loop\Exception\UnsupportedException;
use Icicle\Socket\ReadableSocketInterface;
use Icicle\Socket\SocketInterface;
use Icicle\Socket\WritableSocketInterface;
use Icicle\Structures\UnreferencableObjectStorage;
use Icicle\Timer\TimerInterface;

class EventLoop extends AbstractLoop
{
    /**
     * @var     EventBase
     */
    private $base;
    
    /**
     * UnreferencableObjectStorage mapping Timer objects to Event objects.
     *
     * @var     UnreferencableObjectStorage
     */
    private $timers;
    
    /**
     * @var     Event[int]
     */
    private $readEvents = [];
    
    /**
     * @var     Event[int]
     */
    private $writeEvents = [];
    
    /**
     * @var     Event[int]
     */
    private $signalEvents = [];
    
    /**
     * @var     Closure
     */
    private $readCallback;
    
    /**
     * @var     Closure
     */
    private $writeCallback;
    
    /**
     * @var     Closure
     */
    private $timerCallback;
    
    /**
     * Determines if the PECL event extension is loaded, which is required for this class.
     *
     * @return  bool
     */
    public static function enabled()
    {
        return extension_loaded('event');
    }
    
    /**
     * @throws  UnsupportedException Thrown if the PECL event extension is not loaded.
     */
    public function __construct()
    {
        if (!self::enabled()) {
            throw new UnsupportedException('EventLoop class requires the event extension.');
        }
        
        parent::__construct();
        
        $this->base = new EventBase();
        $this->timers = new UnreferencableObjectStorage();
        
        if ($this->signalHandlingEnabled()) {
            $callback = $this->createSignalCallback();
            
            foreach ($this->getSignalList() as $signal) {
                $event = new Event($this->base, $signal, Event::SIGNAL | Event::PERSIST, $callback);
                $event->add();
                $this->signalEvents[$signal] = $event;
            }
        }
        
        $this->readCallback = function ($_, $what, ReadableSocketInterface $socket) {
            if (Event::TIMEOUT & $what) {
                $socket->onTimeout();
            } else {
                $socket->onRead();
            }
        };
        
        $this->writeCallback = function ($_, $_, WritableSocketInterface $socket) {
            $socket->onWrite();
        };
        
        $this->timerCallback = function ($_, $_, TimerInterface $timer) {
            if (!$this->timers[$timer]->pending(Event::TIMEOUT)) {
                $this->timers[$timer]->free();
                unset($this->timers[$timer]);
            }
            
            $timer->call();
        };
    }
    
    /**
     */
    public function __destruct()
    {
        if ($this->isRunning()) {
            $this->stop();
        }
        
        foreach ($this->readEvents as $event) {
            $event->free();
        }
        
        foreach ($this->writeEvents as $event) {
            $event->free();
        }
        
        for ($this->timers->rewind(); $this->timers->valid(); $this->timers->next()) {
            $this->timers->getInfo()->free();
        }
        
        foreach ($this->signalEvents as $event) {
            $event->free();
        }
    }
    
    /**
     * Calls reInit() on the EventBase object.
     */
    public function reInit()
    {
        $this->base->reInit();
    }
    
    /**
     * {@inheritdoc}
     */
    public function isEmpty()
    {
        return empty($this->readEvents) && empty($this->writeEvents) && !$this->timers->count() && parent::isEmpty();
    }
    
    /**
     * {@inheritdoc}
     */
    public function dispatch($blocking)
    {
        $flags = EventBase::LOOP_ONCE;
        
        if (!$blocking) {
            $flags |= EventBase::LOOP_NONBLOCK;
        }
        
        $this->base->loop($flags); // Dispatch I/O, timer, and signal callbacks.
    }
    
    /**
     * {@inheritdoc}
     */
    public function addReadableSocket(ReadableSocketInterface $socket)
    {
        $id = $socket->getId();
        
        if (!isset($this->readEvents[$id])) {
            $event = new Event($this->base, $socket->getResource(), Event::READ | Event::PERSIST, $this->readCallback, $socket);
            
            if ($timeout = $socket->getTimeout()) {
                $event->add($timeout);
            } else {
                $event->add();
            }
            
            $this->readEvents[$id] = $event;
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function pauseReadableSocket(ReadableSocketInterface $socket)
    {
        $id = $socket->getId();
        
        if (isset($this->readEvents[$id])) {
            $this->readEvents[$id]->del();
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function resumeReadableSocket(ReadableSocketInterface $socket)
    {
        $id = $socket->getId();
        
        if (isset($this->readEvents[$id]) && !$this->readEvents[$id]->pending(Event::READ)) {
            if ($timeout = $socket->getTimeout()) {
                $event->add($timeout);
            } else {
                $event->add();
            }
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function isReadableSocketPending(ReadableSocketInterface $socket)
    {
        $id = $socket->getId();
        
        return isset($this->readEvents[$id]) && $this->readEvents[$id]->pending(Event::READ);
    }
    
    /**
     * {@inheritdoc}
     */
    public function scheduleWritableSocket(WritableSocketInterface $socket)
    {
        $id = $socket->getId();
        
        if (!isset($this->writeEvents[$id])) {
            $this->writeEvents[$id] = new Event($this->base, $socket->getResource(), Event::WRITE, $this->writeCallback, $socket);
        }
        
        $this->writeEvents[$id]->add();
    }
    
    /**
     * {@inheritdoc}
     */
    public function isWritableSocketScheduled(WritableSocketInterface $socket)
    {
        $id = $socket->getId();
        
        return isset($this->writeEvents[$id]) && $this->writeEvents[$id]->pending(Event::WRITE);
    }
    
    /**
     * {@inheritdoc}
     */
    public function unscheduleWritableSocket(WritableSocketInterface $socket)
    {
        $id = $socket->getId();
        
        if (isset($this->writeEvents[$id])) {
            $this->writeEvents[$id]->del();
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function containsSocket(SocketInterface $socket)
    {
        $id = $socket->getId();
        
        return isset($this->readEvents[$id]) || isset($this->writeEvents[$id]);
    }
    
    /**
     * {@inheritdoc}
     */
    public function removeSocket(SocketInterface $socket)
    {
        $id = $socket->getId();
        
        if (isset($this->readEvents[$id])) {
            $this->readEvents[$id]->free();
            unset($this->readEvents[$id]);
        }
        
        if (isset($this->writeEvents[$id])) {
            $this->writeEvents[$id]->free();
            unset($this->writeEvents[$id]);
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function addTimer(TimerInterface $timer)
    {
        if (!isset($this->timers[$timer])) {
            $flags = Event::TIMEOUT;
            if ($timer->isPeriodic()) {
                $flags |= Event::PERSIST;
            }
            
            $event = new Event($this->base, -1, $flags, $this->timerCallback, $timer);
            
            $this->timers[$timer] = $event;
            
            $event->add($timer->getInterval());
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function cancelTimer(TimerInterface $timer)
    {
        if (isset($this->timers[$timer])) {
            $this->timers[$timer]->free();
            unset($this->timers[$timer]);
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function isTimerActive(TimerInterface $timer)
    {
        return isset($this->timers[$timer]);
    }
    
    /**
     * {@inheritdoc}
     */
    public function unreferenceTimer(TimerInterface $timer)
    {
        $this->timers->unreference($timer);
    }
    
    /**
     * {@inheritdoc}
     */
    public function referenceTimer(TimerInterface $timer)
    {
        $this->timers->reference($timer);
    }
    
    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        parent::clear();
        
        foreach ($this->readEvents as $event) {
            $event->free();
        }
        
        foreach ($this->writeEvents as $event) {
            $event->free();
        }
        
        $this->readEvents = [];
        $this->writeEvents = [];
        
        for ($this->timers->rewind(); $this->timers->valid(); $this->timers->next()) {
            $this->timers->getInfo()->free();
        }
        
        $this->timers = new UnreferencableObjectStorage();
    }
}