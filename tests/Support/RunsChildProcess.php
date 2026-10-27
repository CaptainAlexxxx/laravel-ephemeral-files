<?php

namespace Tests\Support;

/**
 * One child PHP process per test, with its output kept so a failure can show why it died.
 */
trait RunsChildProcess
{
    /** @var resource|null */
    private $child = null;

    /** @var array<int, resource> */
    private array $childPipes = [];

    private string $childOutput = '';

    /**
     * @param  array<int, string>  $command
     */
    private function startChild(array $command): void
    {
        $this->childOutput = '';
        $this->child = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->childPipes, base_path());

        foreach ($this->childPipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
    }

    private function childIsRunning(): bool
    {
        return $this->child !== null && proc_get_status($this->child)['running'];
    }

    private function drainChild(): void
    {
        foreach ($this->childPipes as $pipe) {
            $this->childOutput .= (string) stream_get_contents($pipe);
        }
    }

    /**
     * Waits for the child to exit and returns its exit code. The output is in $this->childOutput.
     */
    private function finishChild(int $timeoutSeconds = 30): int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->child);
            $this->drainChild();

            if (! $status['running']) {
                // the exit code is only reliable on the first status call after exit
                proc_close($this->child);
                $this->child = null;

                return $status['exitcode'];
            }

            usleep(50_000);
        }

        $this->terminateChild();
        $this->fail("The child process did not finish in {$timeoutSeconds}s. Output: ".$this->childOutput);
    }

    private function terminateChild(): void
    {
        if ($this->child === null) {
            return;
        }

        if (proc_get_status($this->child)['running']) {
            proc_terminate($this->child);
        }

        $this->drainChild();
        proc_close($this->child);
        $this->child = null;
    }
}
