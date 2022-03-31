<?php declare(strict_types=1);


namespace BongRun\RSMQ;

/**
 * Class QueueWorker
 *
 * @package BongRun\RSMQ
 * @author  Andrew Breksa <andrew@andrewbreksa.com>
 */
class QueueWorker
{

    /**
     * @var RSMQClientInterface
     */
    protected $rsmq;

    /**
     * @var ExecutorInterface
     */
    protected $executor;

    /**
     * @var string
     */
    protected $queue;

    /**
     * @var WorkerSleepProvider
     */
    protected $sleepProvider;

    /**
     * @var int
     */
    protected $received = 0;

    /**
     * @var int
     */
    protected $failed = 0;

    /**
     * @var int
     */
    protected $successful = 0;

    /**
     * QueueWorker constructor.
     *
     * @param RSMQClientInterface $rsmq
     * @param ExecutorInterface $executor
     * @param WorkerSleepProvider $sleepProvider
     * @param string $queue
     */
    public function __construct(
        RSMQClientInterface $rsmq,
        ExecutorInterface   $executor,
        WorkerSleepProvider $sleepProvider,
        string              $queue
    )
    {
        $this->rsmq = $rsmq;
        $this->executor = $executor;
        $this->sleepProvider = $sleepProvider;
        $this->queue = $queue;
    }


    /**
     * @param bool $processOne
     * @param int|null $maxTime
     * @throws Exceptions\QueueNotFoundException
     * @throws Exceptions\QueueParametersValidationException
     */
    public function work(bool $processOne = false, ?int $maxTime = null): void
    {
        $startTime = microtime(true);
        while (true) {
            if ($maxTime !== null && microtime(true) - $startTime >= $maxTime) {
                break;
            }
            $sleep = $this->sleepProvider->getSleep();
            if ($sleep === null) {
                break;
            }
            $message = $this->rsmq->receiveMessage($this->queue);
            if (!($message instanceof Message)) {
                usleep($sleep);
                continue;
            }
            $this->received++;
            $result = $this->executor->__invoke($message);
            if ($result === true) {
                $this->successful++;
                $this->rsmq->deleteMessage($this->queue, $message->getId());
            } else {
                $this->failed++;
            }
            if ($processOne && $this->getProcessedCount() === 1) {
                break;
            }
        }
    }

    /**
     * @return int
     */
    public function getProcessedCount(): int
    {
        return $this->successful + $this->failed;
    }

    /**
     * @return int
     */
    public function getReceived(): int
    {
        return $this->received;
    }

    /**
     * @return int
     */
    public function getFailed(): int
    {
        return $this->failed;
    }

    /**
     * @return int
     */
    public function getSuccessful(): int
    {
        return $this->successful;
    }
}
