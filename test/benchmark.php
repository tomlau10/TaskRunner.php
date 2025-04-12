<?php

// remove memory limit
ini_set('memory_limit', -1);
assert_options(ASSERT_BAIL, true);

// get options
$options = getopt("n:m:p:s:h");

// default values
$DEFAULT_NUM_TASKS = 1000;
$DEFAULT_SIM_MEM_USAGE_MB = 500;
$DEFAULT_MAX_CONCURRENCY = 8;
$DEFAULT_SIM_WORKLOAD_BY_SLEEP = true;

if (isset($options['h'])) {
    echo "Usage: php benchmark.php [options]\n";
    echo "  -n NUM_TASKS    Number of tasks to generate (default: {$DEFAULT_NUM_TASKS})\n";
    echo "  -m MEM_USAGE    Simulate memory usage in MB (default: {$DEFAULT_SIM_MEM_USAGE_MB})\n";
    echo "  -p POOL_SIZE    Max concurrency used by process pool (default: {$DEFAULT_MAX_CONCURRENCY})\n";
    echo "  -s [0|1]        Simulate extra task workload by sleep (default: " . (int)$DEFAULT_SIM_WORKLOAD_BY_SLEEP . ")\n";
    echo "  -h              Show this help message and exit\n";
    exit;
}

// num of tasks to generate
$NUM_TASKS = isset($options["n"]) ? (int)$options["n"] : $DEFAULT_NUM_TASKS;

// simulate memory usage in MB
$SIM_MEM_USAGE_MB = isset($options["m"]) ? (int)$options["m"] : $DEFAULT_SIM_MEM_USAGE_MB;

// max concurrency used by process pool
$MAX_CONCURRENCY = isset($options["p"]) ? (int)$options["p"] : $DEFAULT_MAX_CONCURRENCY;
assert($MAX_CONCURRENCY > 0, "process pool size must be > 0");

// simulate extra task workload by sleep
$SIM_WORKLOAD_BY_SLEEP = isset($options["s"]) ? (bool)$options["s"] : $DEFAULT_SIM_WORKLOAD_BY_SLEEP;

// print benchmark config
echo ">>>>> Config:\n";
echo "\tPHP version: " . phpversion() . "\n";
echo "\tSimulate memory usage: {$SIM_MEM_USAGE_MB}MB\n";
echo "\tTasks to generate: {$NUM_TASKS}\n";
echo "\tProcess pool size: {$MAX_CONCURRENCY}\n";
echo "\tSimulate workload by sleep: " . ($SIM_WORKLOAD_BY_SLEEP ? "true" : "false") . "\n";

// create dummy datas to increase memory usage
function increaseMemoryUsage($targetMemoryUsageMB) {
    static $datas = [];

    function generateRandomString($length = 10) {
        return substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, $length);
    }

    function createData() {
        $data = [];
        for ($i = 0; $i < 5; $i++) {
            $propertyName = "property_{$i}";
            $data[$propertyName] = generateRandomString();
        }
        return $data;
    }

    echo ">>>>> Increasing memory usage to {$targetMemoryUsageMB}MB\n";
    $targetUsageInBytes = $targetMemoryUsageMB * 1024 * 1024;
    while (memory_get_usage(true) < $targetUsageInBytes) {
        for ($i = 0; $i < 100; $i++) {
            $datas[] = createData();
        }
    }
    echo "\tGenerated datas count = " . count($datas) . "\n";
    echo "\tCurrent memory usage: " . (memory_get_usage(true) / 1024 / 1024) . " MB\n";
}
increaseMemoryUsage($SIM_MEM_USAGE_MB);

// basic test framework
interface Test {
    function run($tasks);
}

function benchmark(Test $test) {
    global $NUM_TASKS, $SIM_WORKLOAD_BY_SLEEP;

    // generate tasks
    static $tasks = [];
    if (count($tasks) == 0) {
        for ($i=0; $i < $NUM_TASKS; $i++) {
            if ($SIM_WORKLOAD_BY_SLEEP) {
                // sleep 1~10ms
                $tasks[] = "sleep " . (round(($i % 10 + 1) / 1000, 3)) . " && echo {$i}";
            } else {
                $tasks[] = "echo {$i}";
            }
        }
    }

    $name = get_class($test);
    echo "test: {$name}\n";

    $start = microtime(true);
    $sum = $test->run($tasks);
    $elapsed = microtime(true) - $start;
    echo "\ttime: {$elapsed}\n";
    echo "\tthroughput: " . round($NUM_TASKS/$elapsed, 3) . " TPS\n";

    // check if implementation is correct
    $expected = ($NUM_TASKS-1) * $NUM_TASKS / 2;
    assert($sum == $expected, "expected sum($sum) = {$expected}");
}

// simple for loop exec
class ForLoopExec implements Test {
    function run($tasks) {
        $sum = 0;
        foreach ($tasks as $id => $cmd) {
            $output = shell_exec($cmd);
            $sum += (int) $output;
        }
        return $sum;
    }
}

require __DIR__ . '/../src/TaskRunner.php';

// process pool in main process
class ProcessPoolInMainProcess implements Test {
    private $sum = 0;
    function run($tasks) {
        global $MAX_CONCURRENCY;
        $processPool = new TaskRunner\ProcessPool($MAX_CONCURRENCY);
        $collectJob = function ($id, $status, $stdout, $stder) {
            $this->sum += (int) $stdout;
        };
        foreach ($tasks as $id => $cmd) {
            $processPool->addJob("job{$id}", $cmd, $collectJob);
        }
        $processPool->waitFinish();
        return $this->sum;
    }
}

// process pool in separate process
class ProcessPoolInSeparateProcess implements Test {
    private $sum = 0;
    function run($tasks) {
        global $MAX_CONCURRENCY;
        $runner = new TaskRunner\TaskRunner($MAX_CONCURRENCY);
        foreach ($tasks as $id => $cmd) {
            $runner->add("job{$id}", $cmd);
        }
        $runner->runAndWait(function ($result) {
            $this->sum += (int) ($result["stdout"]);
        });
        return $this->sum;
    }
}

echo ">>>>> Benchmark started\n";
benchmark(new ForLoopExec());
benchmark(new ProcessPoolInMainProcess());
benchmark(new ProcessPoolInSeparateProcess());
