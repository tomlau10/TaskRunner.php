# TaskRunner.php
A process pool implementation with minimized forking overhead for high memory usage PHP5 apps, by running in a separate process.

## The Problem

In a PHP5 workflow processing system handling around 170,000 tasks, we encountered severe performance bottlenecks despite using a traditional process pool implementation. On an 8-core VM, the entire workflow required approximately 40 minutes to complete, resulting in a throughput of only 70 tasks per second.

After investigation, we identified that the bottleneck was in PHP5's `proc_open()` function, particularly when used in memory-intensive applications:

- When using `proc_open()`, Linux uses **fork+exec** to create new processes
- Despite Copy-On-Write (COW) optimization, the system must mark all memory pages as write-protected during fork
- The larger the parent process memory footprint, the higher the overhead for each process creation
- Our workflow application consumed nearly 1GB of memory, making each `proc_open()` call extremely expensive

This overhead effectively negated much of the performance benefit we expected from parallel processing. The traditional process pool approach became increasingly inefficient as memory usage grew.

> **Note:** [PHP 8.3 added usage of `posix_spawn` for `proc_open`](https://www.php.net/ChangeLog-8#:~:text=Added%20usage%20of-,posix_spawn,-for%20proc_open%20when) which addresses this problem completely. If you're using PHP 8.3+, you should just use the native process pool implementation.

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
