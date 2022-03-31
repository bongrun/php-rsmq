<?php declare(strict_types=1);


namespace BongRun\RSMQ;

/**
 * Interface WorkerDelayProvider
 *
 * @package BongRun\RSMQ
 * @author  Andrew Breksa <andrew@andrewbreksa.com>
 */
interface WorkerSleepProvider
{

    /**
     * Return the number of micro seconds that the worker should sleep for before grabbing the next message.
     * Returning null will cause the worker to exit.
     *
     * Note: this method is called _before_ the receiveMessage method is called.
     *
     * @return int|null
     */
    public function getSleep(): ?int;
}
