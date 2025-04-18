<?php

namespace TaskRunner;

// simple wrapper class for easy use
class TaskRunner {
    private $parallel;
    private $taskFile;
    private $started = false;
    private $totalTasks = 0;

    function __construct($parallel) {
        $this->parallel = $parallel;
    }

    function add($id, $cmd) {
        if ($this->started) {
            throw new \Exception("cannot add task to started runner");
        } else if (!$this->taskFile) {
            $this->taskFile = tmpfile();
            if (!$this->taskFile) {
                throw new \Exception("cannot create tmp file for writing tasks");
            }
        }
        $jsonStr = json_encode([
            "id" => $id,
            "cmd" => $cmd,
        ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        fwrite($this->taskFile, "{$jsonStr}\n");
        $this->totalTasks++;
    }

    function runAndWait($callback = null) {
        if (!$this->taskFile) {
            // no task is added
            return;
        } else if ($this->started) {
            throw new \Exception("cannot re-run started runner");
        }
        $this->started = true;

        // just do fflush() but not fclose() as tmp file will be removed when closed
        fflush($this->taskFile);

        // escape the path as may contain special character
        $taskFilePath = stream_get_meta_data($this->taskFile)["uri"];
        $escapedTaskFilePath = escapeshellarg($taskFilePath);

        // execute current php file
        $escapedCurrentFilePath = escapeshellarg(__FILE__);
        $handle = popen("php -f {$escapedCurrentFilePath} tasks={$escapedTaskFilePath} parallel={$this->parallel}", "r");
        try {
            $completedTasks = 0;
            while (($jsonStr = fgets($handle)) !== false) {
                $result = json_decode($jsonStr, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception("json decode error");
                } else if (isset($result["error"])) {
                    // fatal error
                    throw new \Exception($result["error"]);
                }
                $completedTasks++;
                if ($callback) {
                    call_user_func($callback, $result, $completedTasks, $this->totalTasks);
                }
            }
        } finally {
            pclose($handle);
            fclose($this->taskFile);

            // reset status
            $this->taskFile = null;
            $this->started = false;
            $this->totalTasks = 0;
        }
    }
}

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

// check if executed directly
// ref: https://stackoverflow.com/a/20936930
if (get_included_files()[0] !== __FILE__) {
    return;
}

// entrypoint
call_user_func(function () {
    global $argc, $argv, $_GET;

    // parse command line arguments into the $_GET
    // ref: https://www.php.net/manual/en/features.commandline.php#108883
    if ($argc > 1) {
        parse_str(implode("&", array_slice($argv, 1)), $_GET);
    }

    function exitError($message) {
        $jsonStr = json_encode(["error" => $message], JSON_UNESCAPED_SLASHES);
        echo "{$jsonStr}\n";
        exit(1);
    }

    // check required 'tasks' param
    if (!isset($_GET["tasks"]) || strlen($_GET["tasks"]) == 0) {
        exitError("Usage: php " . $argv[0] . " tasks=xxx.jsonl [parallel=n]");
    }
    $tasksFilename = $_GET["tasks"];
    if (!is_file($tasksFilename) || !is_readable($tasksFilename)) {
        exitError("Error: can't open '{$tasksFilename}': No such file or not readable");
    }

    // check optional 'parallel' param
    if (isset($_GET["parallel"])) {
        $maxConcurrency = ctype_digit($_GET["parallel"]) ? (int) $_GET["parallel"] : 0;
        if ($maxConcurrency < 1) {
            exitError("Error: 'parallel' value must be an integer >= 1");
        }
    } else {
        // default to number of CPU cores * 2
        $maxConcurrency = (int) shell_exec("nproc 2>/dev/null") * 2;
        $maxConcurrency = $maxConcurrency > 0 ? $maxConcurrency : 8; // fallback to 8
    }

    /**
     * NOTE:
     * memory usage of current process will highly affect performance of proc_open()
     * since tasks jsonl file may be very large, we read it in 2 passes:
     * - 1st pass for validation only
     * - 2nd pass for creating jobs on the fly
     * => to achieve lowest memory consumption
     */

    // generator for reading file line by line
    // ref: https://www.php.net/manual/en/language.generators.overview.php#112985
    function getLines($file) {
        $fp = fopen($file, "r");
        try {
            $lineNumber = 0;
            while (($line = fgets($fp)) !== false) {
                yield ++$lineNumber => $line;
            }
        } finally {
            fclose($fp);
        }
    }

    // validate
    foreach (getLines($tasksFilename) as $lineNumber => $line) {
        $json = json_decode($line, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            exitError("Error: Invalid json in '{$tasksFilename}' on line {$lineNumber}: " . json_last_error_msg());
        } else if (!isset($json["id"]) || !isset($json["cmd"])) {
            exitError("Error: Missing 'id' or 'cmd' field in '{$tasksFilename}' on line {$lineNumber}");
        }
    }

    $collectJob = function ($id, $status, $stdout, $stderr) {
        $jsonOutput = json_encode([
            "id" => $id,
            "status" => $status,
            "stdout" => $stdout,
            "stderr" => $stderr,
        ], JSON_UNESCAPED_SLASHES);
        echo "{$jsonOutput}\n";
    };

    // add all jobs from tasks, and wait for finish
    $processPool = new ProcessPool($maxConcurrency);
    foreach (getLines($tasksFilename) as $line) {
        $json = json_decode($line, true);
        $processPool->addJob($json["id"], $json["cmd"], $collectJob);
    }
    $processPool->waitFinish();
});
