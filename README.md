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
