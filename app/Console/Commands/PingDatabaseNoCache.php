<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PingDatabaseNoCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'benchmark:ping-nocache 
                            {--queries=100 : Number of queries to run for the batch test}
                            {--connection= : Database connection to use (defaults to default connection)}
                            {--table= : Optional table name to query instead of SELECT 1}
                            {--compare-cache : Run both cached and non-cached versions for comparison}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test database latency with SQL_NO_CACHE to measure true database performance';

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
        $tableName = $this->option('table');
        $compareCache = $this->option('compare-cache');

        // Get the database driver
        $this->dbDriver = config('database.connections.' . $this->connectionName . '.driver');

        // Check if this is MySQL (SQL_NO_CACHE is MySQL-specific)
        if ($this->dbDriver !== 'mysql') {
            $this->warn("SQL_NO_CACHE is MySQL-specific. Running standard queries for {$this->dbDriver}.");
        }

        $this->info("Starting database no-cache ping test");
        $this->info("Database Driver: {$this->dbDriver}");
        $this->info("Connection: {$this->connectionName}");
        $this->displayConnectionInfo();

        if ($compareCache && $this->dbDriver === 'mysql') {
            $this->info("\n=== COMPARISON MODE: Testing both cached and non-cached queries ===");

            // Test cached queries first
            $this->info("\n=== Test 1a: Cold Start Query (CACHED) ===");
            $cachedColdStart = $this->measureColdStart($tableName, false);

            $this->info("\n=== Test 1b: Cold Start Query (NO CACHE) ===");
            $nocacheColdStart = $this->measureColdStart($tableName, true);

            $this->info("\n=== Test 2a: Batch Queries - CACHED ({$queryCount} queries) ===");
            $cachedBatch = $this->measureBatchQueries($queryCount, $tableName, false);

            $this->info("\n=== Test 2b: Batch Queries - NO CACHE ({$queryCount} queries) ===");
            $nocacheBatch = $this->measureBatchQueries($queryCount, $tableName, true);

            // Display comparison results
            $this->displayComparisonResults($cachedColdStart, $nocacheColdStart, $cachedBatch, $nocacheBatch);
        } else {
            // Standard no-cache test
            $this->info("\n=== Test 1: Cold Start Query (NO CACHE) ===");
            $coldStartTime = $this->measureColdStart($tableName, true);

            $this->info("\n=== Test 2: Batch Queries - NO CACHE ({$queryCount} queries) ===");
            $batchResults = $this->measureBatchQueries($queryCount, $tableName, true);

            // Display results
            $this->displayResults($coldStartTime, $batchResults);
        }
    }

    /**
     * Build the appropriate query based on driver and cache setting
     */
    protected function buildQuery(?string $tableName, bool $noCache): string
    {
        if ($tableName) {
            // Query actual table
            $baseQuery = "SELECT * FROM {$tableName} LIMIT 1";
        } else {
            // Simple test query
            $baseQuery = "SELECT 1 as result";
        }

        // Add SQL_NO_CACHE for MySQL
        if ($this->dbDriver === 'mysql' && $noCache) {
            return str_replace('SELECT', 'SELECT SQL_NO_CACHE', $baseQuery);
        }

        return $baseQuery;
    }

    /**
     * Measure cold start query time
     */
    protected function measureColdStart(?string $tableName, bool $noCache): array
    {
        $query = $this->buildQuery($tableName, $noCache);
        $cacheStatus = $noCache ? 'NO CACHE' : 'CACHED';

        $this->info("Executing cold start query ({$cacheStatus}): {$query}");

        // Force a fresh connection by disconnecting first
        DB::connection($this->connectionName)->disconnect();

        $startTime = hrtime(true);
        $result = DB::connection($this->connectionName)->select($query);
        $endTime = hrtime(true);

        $durationNs = $endTime - $startTime;
        $durationMs = $durationNs / 1_000_000;

        $coldStartResult = [
            'query' => $query,
            'cache_status' => $cacheStatus,
            'duration_ms' => round($durationMs, 3),
            'result_count' => count($result),
            'no_cache' => $noCache,
        ];

        $this->info("Cold start query ({$cacheStatus}) completed in {$coldStartResult['duration_ms']} ms");

        Log::info("Benchmark [{$this->dbDriver}]: Cold Start Query ({$cacheStatus})", [
            'driver' => $this->dbDriver,
            'connection' => $this->connectionName,
            'query' => $query,
            'cache_status' => $cacheStatus,
            'duration_ms' => $coldStartResult['duration_ms'],
            'no_cache' => $noCache,
        ]);

        return $coldStartResult;
    }

    /**
     * Measure batch queries
     */
    protected function measureBatchQueries(int $queryCount, ?string $tableName, bool $noCache): array
    {
        $query = $this->buildQuery($tableName, $noCache);
        $cacheStatus = $noCache ? 'NO CACHE' : 'CACHED';

        $this->info("Executing {$queryCount} batch queries ({$cacheStatus}): {$query}");

        $times = [];
        $totalStartTime = hrtime(true);

        for ($i = 0; $i < $queryCount; $i++) {
            $startTime = hrtime(true);
            $result = DB::connection($this->connectionName)->select($query);
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
            'query' => $query,
            'cache_status' => $cacheStatus,
            'query_count' => $queryCount,
            'total_duration_ms' => round($totalDurationNs / 1_000_000, 3),
            'avg_duration_ms' => round($avgDurationNs / 1_000_000, 3),
            'min_duration_ms' => round($minDurationNs / 1_000_000, 3),
            'max_duration_ms' => round($maxDurationNs / 1_000_000, 3),
            'p50_duration_ms' => round($p50DurationNs / 1_000_000, 3),
            'p95_duration_ms' => round($p95DurationNs / 1_000_000, 3),
            'p99_duration_ms' => round($p99DurationNs / 1_000_000, 3),
            'queries_per_second' => round($queryCount / ($totalDurationNs / 1_000_000_000), 1),
            'no_cache' => $noCache,
        ];

        $this->info("Batch queries ({$cacheStatus}) completed:");
        $this->info("  Total time: {$batchResults['total_duration_ms']} ms");
        $this->info("  Average: {$batchResults['avg_duration_ms']} ms");
        $this->info("  Queries per second: {$batchResults['queries_per_second']}");

        Log::info("Benchmark [{$this->dbDriver}]: Batch Queries ({$cacheStatus})", [
            'driver' => $this->dbDriver,
            'connection' => $this->connectionName,
            'query' => $query,
            'cache_status' => $cacheStatus,
            'query_count' => $queryCount,
            'total_duration_ms' => $batchResults['total_duration_ms'],
            'avg_duration_ms' => $batchResults['avg_duration_ms'],
            'min_duration_ms' => $batchResults['min_duration_ms'],
            'max_duration_ms' => $batchResults['max_duration_ms'],
            'p50_duration_ms' => $batchResults['p50_duration_ms'],
            'p95_duration_ms' => $batchResults['p95_duration_ms'],
            'p99_duration_ms' => $batchResults['p99_duration_ms'],
            'queries_per_second' => $batchResults['queries_per_second'],
            'no_cache' => $noCache,
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
            $details['SQL_NO_CACHE Support'] = 'Yes';
        } elseif ($this->dbDriver === 'pgsql') {
            $details['Host'] = $config['host'] ?? 'N/A';
            $details['Port'] = $config['port'] ?? 'N/A';
            $details['Database'] = $config['database'] ?? 'N/A';
            $details['Schema'] = $config['schema'] ?? 'public';
            $details['SQL_NO_CACHE Support'] = 'No (PostgreSQL)';
        } elseif ($this->dbDriver === 'sqlite') {
            $details['Database'] = $config['database'] ?? 'N/A';
            $details['SQL_NO_CACHE Support'] = 'No (SQLite)';
        }

        // Display connection details table
        $rows = [];
        foreach ($details as $key => $value) {
            $rows[] = [$key, $value];
        }

        $this->table(['Setting', 'Value'], $rows);
    }

    /**
     * Display results for single test mode
     */
    protected function displayResults(array $coldStart, array $batch)
    {
        $this->info("\n" . str_repeat("=", 60));
        $this->info("DATABASE NO-CACHE BENCHMARK RESULTS");
        $this->info("Driver: {$this->dbDriver} | Connection: {$this->connectionName}");
        $this->info(str_repeat("=", 60));

        // Cold start results
        $this->info("\n🔸 COLD START QUERY ({$coldStart['cache_status']})");
        $this->info("Query: {$coldStart['query']}");
        $coldStartTable = [
            ['Duration (ms)', $coldStart['duration_ms']],
            ['Result Count', $coldStart['result_count']],
            ['Cache Status', $coldStart['cache_status']],
        ];
        $this->table(['Metric', 'Value'], $coldStartTable);

        // Batch results
        $this->info("\n🔸 BATCH QUERIES ({$batch['query_count']} × {$batch['cache_status']})");
        $this->info("Query: {$batch['query']}");
        $batchTable = [
            ['Total Duration (ms)', $batch['total_duration_ms']],
            ['Average (ms)', $batch['avg_duration_ms']],
            ['Minimum (ms)', $batch['min_duration_ms']],
            ['Maximum (ms)', $batch['max_duration_ms']],
            ['50th Percentile (ms)', $batch['p50_duration_ms']],
            ['95th Percentile (ms)', $batch['p95_duration_ms']],
            ['99th Percentile (ms)', $batch['p99_duration_ms']],
            ['Queries per Second', $batch['queries_per_second']],
        ];
        $this->table(['Metric', 'Value'], $batchTable);

        $this->info("\n✅ All benchmark results have been logged to the Laravel log file.");
    }

    /**
     * Display comparison results for cache vs no-cache
     */
    protected function displayComparisonResults(array $cachedCold, array $nocacheCold, array $cachedBatch, array $nocacheBatch)
    {
        $this->info("\n" . str_repeat("=", 70));
        $this->info("DATABASE CACHE vs NO-CACHE COMPARISON RESULTS");
        $this->info("Driver: {$this->dbDriver} | Connection: {$this->connectionName}");
        $this->info(str_repeat("=", 70));

        // Cold start comparison
        $this->info("\n🔸 COLD START COMPARISON");
        $coldCompareTable = [
            ['Metric', 'Cached (ms)', 'No Cache (ms)', 'Difference', 'Cache Benefit'],
            [
                'Duration',
                $cachedCold['duration_ms'],
                $nocacheCold['duration_ms'],
                round($nocacheCold['duration_ms'] - $cachedCold['duration_ms'], 3) . ' ms',
                round($nocacheCold['duration_ms'] / $cachedCold['duration_ms'], 1) . 'x faster'
            ],
        ];
        $this->table(['Metric', 'Cached (ms)', 'No Cache (ms)', 'Difference', 'Cache Benefit'], [
            [
                'Duration',
                $cachedCold['duration_ms'],
                $nocacheCold['duration_ms'],
                round($nocacheCold['duration_ms'] - $cachedCold['duration_ms'], 3) . ' ms',
                round($nocacheCold['duration_ms'] / $cachedCold['duration_ms'], 1) . 'x faster'
            ]
        ]);

        // Batch comparison
        $this->info("\n🔸 BATCH QUERIES COMPARISON ({$cachedBatch['query_count']} queries each)");
        $batchCompareTable = [
            [
                'Average',
                $cachedBatch['avg_duration_ms'],
                $nocacheBatch['avg_duration_ms'],
                round($nocacheBatch['avg_duration_ms'] - $cachedBatch['avg_duration_ms'], 3) . ' ms',
                round($nocacheBatch['avg_duration_ms'] / $cachedBatch['avg_duration_ms'], 1) . 'x faster'
            ],
            [
                'P95',
                $cachedBatch['p95_duration_ms'],
                $nocacheBatch['p95_duration_ms'],
                round($nocacheBatch['p95_duration_ms'] - $cachedBatch['p95_duration_ms'], 3) . ' ms',
                round($nocacheBatch['p95_duration_ms'] / $cachedBatch['p95_duration_ms'], 1) . 'x faster'
            ],
            [
                'QPS',
                $cachedBatch['queries_per_second'],
                $nocacheBatch['queries_per_second'],
                round($cachedBatch['queries_per_second'] - $nocacheBatch['queries_per_second'], 1),
                round($cachedBatch['queries_per_second'] / $nocacheBatch['queries_per_second'], 1) . 'x faster'
            ],
        ];
        $this->table(['Metric', 'Cached (ms)', 'No Cache (ms)', 'Difference', 'Cache Benefit'], $batchCompareTable);

        // Summary insights
        $this->info("\n🔸 PERFORMANCE INSIGHTS");
        $insights = [];

        $cacheSpeedup = round($nocacheBatch['avg_duration_ms'] / $cachedBatch['avg_duration_ms'], 1);
        if ($cacheSpeedup > 2) {
            $insights[] = "✅ Query cache provides significant benefit ({$cacheSpeedup}x faster)";
        } else {
            $insights[] = "⚠️  Query cache benefit is minimal ({$cacheSpeedup}x faster) - likely network/connection bound";
        }

        if ($nocacheBatch['avg_duration_ms'] > 10) {
            $insights[] = "⚠️  High no-cache latency ({$nocacheBatch['avg_duration_ms']}ms) - check database/network performance";
        } else {
            $insights[] = "✅ Good no-cache performance ({$nocacheBatch['avg_duration_ms']}ms)";
        }

        foreach ($insights as $insight) {
            $this->info("  " . $insight);
        }

        $this->info("\n✅ All benchmark results have been logged to the Laravel log file.");
    }
}
