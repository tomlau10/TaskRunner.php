<?php

// process pool implementation
class ProcessPool {
    private $processes = [];
    private $runningProcesses = 0;
    private $maxConcurrency;

    function __construct($maxConcurrency) {
        $this->maxConcurrency = $maxConcurrency;
    }

    function addJob($id, $cmd, $callback = null) {
        static $descriptorspec = [
            0 => ["file", "/dev/null", "r"],    // discard stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"], // stderr
        ];
    
        // check if jobs count reached maximum number of concurrent jobs
        if ($this->runningProcesses >= $this->maxConcurrency) {
            $this->waitJob(false);
        }
    
        $process = proc_open($cmd, $descriptorspec, $pipes);
    
        // add to pool
        $pid = proc_get_status($process)["pid"];
        $this->processes[$pid] = [
            "process" => $process,
            "pipes" => $pipes,
            "id" => $id,
            "callback" => $callback,
        ];
        $this->runningProcesses++;
    }

    private function waitJob($isWaitAll) {
        while ($this->runningProcesses > 0) {
            $anyFinished = false;
            foreach ($this->processes as $pid => $proc) {
                $status = proc_get_status($proc["process"]);
                if (!$status["running"]) {
                    // get output
                    $anyFinished = true;

                    // callback
                    $callback = $proc["callback"];
                    if ($callback) {
                        $exitCode = $status["exitcode"];
                        $stdout = stream_get_contents($proc["pipes"][1]);
                        $stderr = stream_get_contents($proc["pipes"][2]);
                        call_user_func($callback, $proc["id"], $exitCode, $stdout, $stderr);
                    }

                    // clean the job
                    fclose($proc["pipes"][1]);
                    fclose($proc["pipes"][2]);
                    proc_close($proc["process"]);
                    unset($this->processes[$pid]);
                    $this->runningProcesses--;
                }
            }
    
            if ($anyFinished && !$isWaitAll) {
                // only wait for at least 1
                break;
            } else if ($this->runningProcesses > 0) {
                // sleep 5ms to avoid busy loop
                usleep(5000);
            }
        }
    }

    function waitFinish() {
        $this->waitJob(true);
    }
}
