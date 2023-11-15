<?php

namespace Drutiny\Bulk\Message;

use Symfony\Component\Process\Process;

trait ProcessTrait {
    protected Process $process;

    public function setProcess(Process $process) {
        $this->process = $process;
    }

    public function getProcess(): Process {
        return $this->process;
    }

    public function hasProcess(): bool {
        return isset($this->process);
    }

    public function handleSignal(int $signal) {
        $this->process->signal($signal);
    }
}