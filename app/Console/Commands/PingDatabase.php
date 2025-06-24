<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PingDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'benchmark:ping 
                            {--queries=100 : Number of queries to run for the batch test}
                            {--connection= : Database connection to use (defaults to default connection)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test database connection latency with SELECT 1 queries';

    /**
     * The database driver being used
     */
    protected string $dbDriver;

    /**
     * The database connection name
     */
    protected string $connectionName;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $queryCount = $this->option('queries');
        $this->connectionName = $this->option('connection') ?: config('database.default');

        // Get the database driver
        $this->dbDriver = config('database.connections.' . $this->connectionName . '.driver');

        $this->info("Starting database ping test");
        $this->info("Database Driver: {$this->dbDriver}");
        $this->info("Connection: {$this->connectionName}");
        $this->displayConnectionInfo();

        // Test 1: Cold start - single SELECT 1
        $this->info("\n=== Test 1: Cold Start Query ===");
        $coldStartTime = $this->measureColdStart();

        // Test 2: Batch queries - multiple SELECT 1
        $this->info("\n=== Test 2: Batch Queries ({$queryCount} queries) ===");
        $batchResults = $this->measureBatchQueries($queryCount);

        // Display results
        $this->displayResults($coldStartTime, $batchResults);
    }

    /**
     * Measure cold start query time
     */
    protected function measureColdStart(): array
    {
        $this->info("Executing cold start query...");

        // Force a fresh connection by disconnecting first
        DB::connection($this->connectionName)->disconnect();

        $startTime = hrtime(true);
        $result = DB::connection($this->connectionName)->select('SELECT 1 as result');
        $endTime = hrtime(true);

        $durationNs = $endTime - $startTime;
        $durationMs = $durationNs / 1_000_000; // Convert nanoseconds to milliseconds
        $durationUs = $durationNs / 1_000; // Convert nanoseconds to microseconds

        $coldStartResult = [
            'duration_ns' => $durationNs,
            'duration_ms' => round($durationMs, 3),
            'duration_us' => round($durationUs, 1),
            'result' => $result[0]->result ?? null,
        ];

        $this->info("Cold start query completed in {$coldStartResult['duration_ms']} ms ({$coldStartResult['duration_us']} μs)");

        Log::info("Benchmark [{$this->dbDriver}]: Cold Start Query", [
            'driver' => $this->dbDriver,
            'connection' => $this->connectionName,
            'duration_ms' => $coldStartResult['duration_ms'],
            'duration_us' => $coldStartResult['duration_us'],
        ]);

        return $coldStartResult;
    }

    /**
     * Measure batch queries
     */
    protected function measureBatchQueries(int $queryCount): array
    {
        $this->info("Executing {$queryCount} batch queries...");

        $times = [];
        $totalStartTime = hrtime(true);

        for ($i = 0; $i < $queryCount; $i++) {
            $startTime = hrtime(true);
            $result = DB::connection($this->connectionName)->select('SELECT 1 as result');
            $endTime = hrtime(true);

            $durationNs = $endTime - $startTime;
            $times[] = $durationNs;
        }

        $totalEndTime = hrtime(true);
        $totalDurationNs = $totalEndTime - $totalStartTime;

        // Calculate statistics
        $avgDurationNs = array_sum($times) / count($times);
        $minDurationNs = min($times);
        $maxDurationNs = max($times);

        // Sort times to calculate percentiles
        sort($times);
        $p50Index = (int) floor(count($times) * 0.5);
        $p95Index = (int) floor(count($times) * 0.95);
        $p99Index = (int) floor(count($times) * 0.99);

        $p50DurationNs = $times[$p50Index];
        $p95DurationNs = $times[$p95Index];
        $p99DurationNs = $times[$p99Index];

        $batchResults = [
            'query_count' => $queryCount,
            'total_duration_ms' => round($totalDurationNs / 1_000_000, 3),
            'avg_duration_ms' => round($avgDurationNs / 1_000_000, 3),
            'avg_duration_us' => round($avgDurationNs / 1_000, 1),
            'min_duration_ms' => round($minDurationNs / 1_000_000, 3),
            'min_duration_us' => round($minDurationNs / 1_000, 1),
            'max_duration_ms' => round($maxDurationNs / 1_000_000, 3),
            'max_duration_us' => round($maxDurationNs / 1_000, 1),
            'p50_duration_ms' => round($p50DurationNs / 1_000_000, 3),
            'p50_duration_us' => round($p50DurationNs / 1_000, 1),
            'p95_duration_ms' => round($p95DurationNs / 1_000_000, 3),
            'p95_duration_us' => round($p95DurationNs / 1_000, 1),
            'p99_duration_ms' => round($p99DurationNs / 1_000_000, 3),
            'p99_duration_us' => round($p99DurationNs / 1_000, 1),
            'queries_per_second' => round($queryCount / ($totalDurationNs / 1_000_000_000), 1),
        ];

        $this->info("Batch queries completed:");
        $this->info("  Total time: {$batchResults['total_duration_ms']} ms");
        $this->info("  Average: {$batchResults['avg_duration_ms']} ms ({$batchResults['avg_duration_us']} μs)");
        $this->info("  Queries per second: {$batchResults['queries_per_second']}");

        Log::info("Benchmark [{$this->dbDriver}]: Batch Queries", [
            'driver' => $this->dbDriver,
            'connection' => $this->connectionName,
            'query_count' => $queryCount,
            'total_duration_ms' => $batchResults['total_duration_ms'],
            'avg_duration_ms' => $batchResults['avg_duration_ms'],
            'avg_duration_us' => $batchResults['avg_duration_us'],
            'min_duration_ms' => $batchResults['min_duration_ms'],
            'max_duration_ms' => $batchResults['max_duration_ms'],
            'p50_duration_ms' => $batchResults['p50_duration_ms'],
            'p95_duration_ms' => $batchResults['p95_duration_ms'],
            'p99_duration_ms' => $batchResults['p99_duration_ms'],
            'queries_per_second' => $batchResults['queries_per_second'],
        ]);

        return $batchResults;
    }

    /**
     * Display database connection information
     */
    protected function displayConnectionInfo()
    {
        $config = config('database.connections.' . $this->connectionName);

        $details = [
            'Connection' => $this->connectionName,
            'Driver' => $this->dbDriver,
        ];

        // Add driver-specific information
        if ($this->dbDriver === 'mysql') {
            $details['Host'] = $config['host'] ?? 'N/A';
            $details['Port'] = $config['port'] ?? 'N/A';
            $details['Database'] = $config['database'] ?? 'N/A';
        } elseif ($this->dbDriver === 'pgsql') {
            $details['Host'] = $config['host'] ?? 'N/A';
            $details['Port'] = $config['port'] ?? 'N/A';
            $details['Database'] = $config['database'] ?? 'N/A';
            $details['Schema'] = $config['schema'] ?? 'public';
        } elseif ($this->dbDriver === 'sqlite') {
            $details['Database'] = $config['database'] ?? 'N/A';
        }

        // Display connection details table
        $rows = [];
        foreach ($details as $key => $value) {
            $rows[] = [$key, $value];
        }

        $this->table(['Setting', 'Value'], $rows);
    }

    /**
     * Display comprehensive results
     */
    protected function displayResults(array $coldStart, array $batch)
    {
        $this->info("\n" . str_repeat("=", 60));
        $this->info("DATABASE PING BENCHMARK RESULTS");
        $this->info("Driver: {$this->dbDriver} | Connection: {$this->connectionName}");
        $this->info(str_repeat("=", 60));

        // Cold start results
        $this->info("\n🔸 COLD START QUERY (SELECT 1)");
        $coldStartTable = [
            ['Duration (ms)', $coldStart['duration_ms']],
            ['Duration (μs)', $coldStart['duration_us']],
            ['Result', $coldStart['result']],
        ];
        $this->table(['Metric', 'Value'], $coldStartTable);

        // Batch results
        $this->info("\n🔸 BATCH QUERIES ({$batch['query_count']} × SELECT 1)");
        $batchTable = [
            ['Total Duration (ms)', $batch['total_duration_ms']],
            ['Average (ms)', $batch['avg_duration_ms']],
            ['Average (μs)', $batch['avg_duration_us']],
            ['Minimum (ms)', $batch['min_duration_ms']],
            ['Minimum (μs)', $batch['min_duration_us']],
            ['Maximum (ms)', $batch['max_duration_ms']],
            ['Maximum (μs)', $batch['max_duration_us']],
            ['50th Percentile (ms)', $batch['p50_duration_ms']],
            ['50th Percentile (μs)', $batch['p50_duration_us']],
            ['95th Percentile (ms)', $batch['p95_duration_ms']],
            ['95th Percentile (μs)', $batch['p95_duration_us']],
            ['99th Percentile (ms)', $batch['p99_duration_ms']],
            ['99th Percentile (μs)', $batch['p99_duration_us']],
            ['Queries per Second', $batch['queries_per_second']],
        ];
        $this->table(['Metric', 'Value'], $batchTable);

        // Summary comparison
        $this->info("\n🔸 SUMMARY");
        $coldStartVsBatch = round($coldStart['duration_ms'] / $batch['avg_duration_ms'], 1);
        $summaryTable = [
            ['Cold Start vs Avg Batch', "{$coldStartVsBatch}x slower"],
            ['Connection Overhead', round($coldStart['duration_ms'] - $batch['avg_duration_ms'], 3) . ' ms'],
            ['Recommended for', $batch['avg_duration_ms'] < 5 ? 'High-frequency queries' : 'Standard queries'],
        ];
        $this->table(['Comparison', 'Value'], $summaryTable);

        $this->info("\n✅ All benchmark results have been logged to the Laravel log file.");

        // Log final summary
        Log::info("Benchmark [{$this->dbDriver}]: Ping Test Summary", [
            'driver' => $this->dbDriver,
            'connection' => $this->connectionName,
            'cold_start_ms' => $coldStart['duration_ms'],
            'batch_avg_ms' => $batch['avg_duration_ms'],
            'batch_p95_ms' => $batch['p95_duration_ms'],
            'queries_per_second' => $batch['queries_per_second'],
            'cold_start_overhead_ms' => round($coldStart['duration_ms'] - $batch['avg_duration_ms'], 3),
            'cold_start_multiplier' => round($coldStart['duration_ms'] / $batch['avg_duration_ms'], 1),
        ]);
    }
}
