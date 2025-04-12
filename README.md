# TaskRunner.php
A process pool implementation with minimized forking overhead for high memory usage PHP5 apps, by running in a separate process.

## Table of Contents

1. [The Problem](#the-problem)
2. [Solution](#solution)
3. [Basic Usage](#basic-usage)
    - [Caveat: Pipe Buffer Limitations](#caveat-pipe-buffer-limitations)
4. [Benchmarks](#benchmarks)
    - [Benchmark Environment](#benchmark-environment)
    - [Benchmark Results](#benchmark-results)
    - [Analysis](#analysis)
5. [Conclusion](#conclusion)

## The Problem

In a PHP5 workflow processing system handling around 170,000 tasks, we encountered severe performance bottlenecks despite using a traditional process pool implementation. On an 8-core VM, the entire workflow required approximately 40 minutes to complete, resulting in a throughput of only 70 tasks per second.

After investigation, we identified that the bottleneck was in PHP5's `proc_open()` function, particularly when used in memory-intensive applications:

- When using `proc_open()`, Linux uses **fork+exec** to create new processes
- Despite Copy-On-Write (COW) optimization, the system must mark all memory pages as write-protected during fork
- The larger the parent process memory footprint, the higher the overhead for each process creation
- Our workflow application consumed nearly 1GB of memory, making each `proc_open()` call extremely expensive

This overhead effectively negated much of the performance benefit we expected from parallel processing. The traditional process pool approach became increasingly inefficient as memory usage grew.

> **Note:** [PHP 8.3 added usage of `posix_spawn` for `proc_open`](https://www.php.net/ChangeLog-8#:~:text=Added%20usage%20of-,posix_spawn,-for%20proc_open%20when) which addresses this problem completely. If you're using PHP 8.3+, you should just use the native process pool implementation. Detailed benchmark results are provided in the [Benchmarks](#benchmarks) section below.

## Solution

TaskRunner.php takes the following approach:

1. **Separate Process**: TaskRunner runs the process pool in a completely separate PHP process with minimal memory footprint, dramatically reducing fork overhead.

2. **Task Passing Using JSONL**: Tasks are serialized to a temporary file in JSONL format, allowing workers to read incrementally and reducing memory usage versus loading all tasks at once.

3. **Streamlined Results Handling**: Each completed task outputs results as JSON to stdout, where the parent process captures and decodes them, providing a clean data exchange mechanism.

This architecture minimizes the fork overhead, no matter what the memory level of the parent process. By adopting this solution, the processing time of the aforementioned case was **reduced from `40` minutes to approximately `3` minutes**, showcasing a dramatic improvement in efficiency.

## Basic Usage

```php
// just copy the `src/TaskRunner.php` to your project and require it
require "TaskRunner.php";
use TaskRunner\TaskRunner;

// initialize TaskRunner with desired concurrency level
$taskRunner = new TaskRunner(8);    // max concurrency = 8

// add tasks to runner, tasks are serialized and written to a temporary JSONL file (non-blocking)
for ($i = 0; $i < 100; $i++) {
    $taskRunner->add(
        "task_{$i}",    // task id (will be included in result)
        "echo {$i}"     // shell command to execute
    );
}

$sum = 0;

// start execution of all tasks and wait for completion (blocking)
// process results with an optional callback function
$taskRunner->runAndWait(function ($result, $completed, $total) use (&$sum) {
    // each result contains:
    $id = $result["id"];            // task id you provided
    $status = $result["status"];    // exit code of the command
    $stdout = $result["stdout"];    // command output (stdout)
    $stderr = $result["stderr"];    // error output (stderr)

    // process the result
    $sum += (int) $stdout;
    
    // optional: display progress
    if ($completed % 10 == 0 || $completed == $total) {
        echo "Progress: {$completed}/{$total}\n";
    }
});

echo "Sum: {$sum}\n";
```

### Caveat: Pipe Buffer Limitations

For maximum performance, TaskRunner only reads process output pipes after process completion, which can cause **deadlocks** if child processes generate more output than the OS pipe buffer size (typically 64KB on Linux).

To circumvent this, redirect the output to a temporary file and read it in your callback:
```php
$taskRunner = new TaskRunner(4);

// this will work (64KB output)
$_1KB_str = str_repeat("a", 1024);
$_64KB_outputCmd = "for i in `seq 64`; do printf '{$_1KB_str}'; done";
$taskRunner->add("ok", $_64KB_outputCmd);

// this will deadlock (64KB + 1 byte output)
$taskRunner->add("deadlock", "{$_64KB_outputCmd}; printf 'a'");

// solution: redirect large output to temporary file
$tempFile = tempnam(sys_get_temp_dir(), "task_");
$taskRunner->add("large_output@{$tempFile}", "({$_64KB_outputCmd}; printf 'a') > {$tempFile}");

$taskRunner->runAndWait(function ($result) {
    // extract file path from id if exist
    list($id, $file) = explode("@", $result["id"]);
    if ($file) {
        // read temp file and clean up
        $output = file_get_contents($file);
        unlink($file);
    } else {
        $output = $result["stdout"];
    }
    echo "Task: {$id}\n\tOutput size: " . strlen($output) . " bytes\n";
    // you will never see the output of "deadlock" task
});

echo "You will never see this message\n";   // because process deadlocked above
```

## Benchmarks

To understand the performance improvements TaskRunner.php brings, a series of benchmarks were conducted using the included `test/benchmark.php` script. The benchmarks cover different PHP versions and simulated memory usage scenarios.
```
$ php ./test/benchmark.php -h
Usage: php benchmark.php [options]
  -n NUM_TASKS    Number of tasks to generate (default: 1000)
  -m MEM_USAGE    Simulate memory usage in MB (default: 500)
  -p POOL_SIZE    Max concurrency used by process pool (default: 8)
  -s [0|1]        Simulate extra task workload by sleep (default: 1)
  -h              Show this help message and exit
```

### Benchmark Environment

- **CPU**: Intel(R) Core(TM) i7-4790 CPU @ 3.60GHz (4C/8T)
- **OS**: Windows 10 22H2 19045.4170
- **Docker Desktop**: 4.40.0 (187762) WSL 2 backend
- **Docker images used**:
    - php:5.6-alpine
    - php:7.0-alpine
    - php:8.2-alpine
    - php:8.3-alpine
- **Benchmark configurations**: Default values were used unless specifically specified

### Benchmark Results

For each PHP version, two types of tests are performed with varying memory sizes (`-m${MB}`):

1. **Raw Forking Speed**: No `sleep` used as workload (`-s0`). Each task involves only 1 `echo` cmd, finishing quickly to focus on raw forking performance.
    ```sh
    for MB in 0 10 50 100 500; do
        php ./test/benchmark.php -s0 -m${MB} | awk '/TPS/{print $2}' | paste -sd ' '
    done
    ```
2. **High Concurrency Test**: With `sleep` included by default, each task is less cpu intensive, allowing higher concurrency to be tested (`-p16`).
    ```sh
    for MB in 0 10 50 100 500; do
        php ./test/benchmark.php -p16 -m${MB} | awk '/TPS/{print $2}' | paste -sd ' '
    done
    ```

The output of each benchmark included three different measurements:
- **ForLoopExec**: Tasks per second (TPS) for a simple loop using `exec()`.
- **ProcessPool**: TPS for a process pool running in the main PHP process.
- **TaskRunner**: TPS time for a process pool running in a separate process, which is TaskRunner.

#### Raw Forking Speed (`-s0 -m${MB}`)

| PHP version        | Memory (MB) | ForloopExec | ProcessPool | TaskRunner |
|--------------------|-------------|-------------|-------------|------------|
| **php:5.6-alpine** | 0           | 1618.272    | 3683.702    | 3348.826   |
|                    | 10          | 1603.034    | 2857.011    | 3329.294   |
|                    | 50          | 1601.607    | 1672.087    | 3320.506   |
|                    | 100         | 1607.546    | 1076.44     | 3342.102   |
|                    | 500         | 1618.671    | 267.226     | 3340.004   |
|                    |             |             |             |            |
| **php:7.0-alpine** | 0           | 1640.177    | 3840.481    | 3423.284   |
|                    | 10          | 1641.548    | 3675.175    | 3377.75    |
|                    | 50          | 1621.629    | 3518.154    | 3340.449   |
|                    | 100         | 1642.217    | 3298.869    | 3426.019   |
|                    | 500         | 1611.193    | 2450.734    | 3438.413   |
|                    |             |             |             |            |
| **php:8.2-alpine** | 0           | 1643.112    | 3486.215    | 3045.826   |
|                    | 10          | 1667.02     | 3307.842    | 3104.992   |
|                    | 50          | 1641.721    | 3177.176    | 3094.48    |
|                    | 100         | 1659.647    | 2938.861    | 3137.422   |
|                    | 500         | 1642.848    | 2284.67     | 3092.059   |
|                    |             |             |             |            |
| **php:8.3-alpine** | 0           | 1630.18     | 4777.558    | 4132.384   |
|                    | 10          | 1631.428    | 4690.655    | 4227.579   |
|                    | 50          | 1646.66     | 4767.902    | 4156.068   |
|                    | 100         | 1605.698    | 4633.192    | 4172.49    |
|                    | 500         | 1609.863    | 4677.509    | 4186.187   |

#### High Concurrency Test (`-p16 -m${MB}`)

| PHP version        | Memory (MB) | ForloopExec | ProcessPool | TaskRunner |
|--------------------|-------------|-------------|-------------|------------|
| **php:5.6-alpine** | 0           | 141.823     | 1581.513    | 1495.676   |
|                    | 10          | 141.82      | 1516.806    | 1505.006   |
|                    | 50          | 140.816     | 1503.14     | 1507.477   |
|                    | 100         | 141.28      | 996.201     | 1511.355   |
|                    | 500         | 141.515     | 240.075     | 1497.19    |
|                    |             |             |             |            |
| **php:7.0-alpine** | 0           | 141.288     | 1574.63     | 1502.727   |
|                    | 10          | 141.431     | 1567.128    | 1507.591   |
|                    | 50          | 141.402     | 1545.514    | 1501.335   |
|                    | 100         | 141.316     | 1539.335    | 1503.375   |
|                    | 500         | 141.92      | 1475.459    | 1506.494   |
|                    |             |             |             |            |
| **php:8.2-alpine** | 0           | 141.12      | 1531.863    | 1479.054   |
|                    | 10          | 140.346     | 1523.341    | 1472.798   |
|                    | 50          | 140.52      | 1511.72     | 1465.807   |
|                    | 100         | 141.828     | 1501.15     | 1471.648   |
|                    | 500         | 140.933     | 1459.095    | 1508.225   |
|                    |             |             |             |            |
| **php:8.3-alpine** | 0           | 142.316     | 1496.704    | 1503.024   |
|                    | 10          | 141.274     | 1538.836    | 1512.285   |
|                    | 50          | 141.022     | 1537.704    | 1518.287   |
|                    | 100         | 141.537     | 1521.002    | 1513.808   |
|                    | 500         | 141.587     | 1529.007    | 1509.596   |

### Analysis

The benchmark results reveal these key insights:

1. **PHP 5.6:** ProcessPool performance collapses by 93% (~3700 => ~270 TPS) as memory increases to 500MB, while TaskRunner maintains consistent ~3340 TPS regardless of memory usage.

2. **PHP 7.0-8.2:** These versions show internal optimizations with less sensitivity to memory usage, though ProcessPool still degrades as memory usage increases.

3. **PHP 8.3:** Significant improvements with `posix_spawn()` for `proc_open()`. ProcessPool no longer degrades with increased memory and outperforms TaskRunner in both tests.

4. **ForLoopExec Performance:** Surprisingly, the sequential `exec()` approach is unaffected by memory usage but is the least efficient method due to its single-threaded execution.

## Conclusion

TaskRunner is best for **PHP 5.6** applications with high memory usage. For **PHP 7.0-8.2**, TaskRunner provides advantages primarily for memory-intensive applications, though the benefits are less pronounced than in PHP 5.6. For **PHP 8.3+**, the native process pool implementation is recommended for better performance without needing TaskRunner's separate architecture.
