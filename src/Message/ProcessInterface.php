<?php

namespace Drutiny\Bulk\Message;

use Symfony\Component\Process\Process;

interface ProcessInterface {

    public function setProcess(Process $process);

    public function getProcess();

    public function hasProcess();

    public function handleSignal(int $signal);
}