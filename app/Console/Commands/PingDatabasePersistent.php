<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class PingDatabasePersistent extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'benchmark:ping-persistent 
                            {--queries=100 : Number of queries to run for the batch test}
                            {--connection= : Database connection to use (defaults to default connection)}
                            {--persistent : Test with persistent connections enabled}
                            {--compare : Compare persistent vs non-persistent connections}
                            {--no-cache : Use SQL_NO_CACHE for MySQL queries}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test database performance with PDO persistent connections vs regular connections';

    /**
     * The database driver being used
     */
    protected string $dbDriver;

    /**
     * The database connection name
     */
    protected string $connectionName;

    /**
     * Original connection configuration
     */
    protected array $originalConfig;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $queryCount = $this->option('queries');
        $this->connectionName = $this->option('connection') ?: config('database.default');
        $usePersistent = $this->option('persistent');
        $compare = $this->option('compare');
        $noCache = $this->option('no-cache');

        // Get the database driver and store original config
        $this->dbDriver = config('database.connections.' . $this->connectionName . '.driver');
        $this->originalConfig = config('database.connections.' . $this->connectionName);

        $this->info("Starting PDO persistent connection benchmark");
        $this->info("Database Driver: {$this->dbDriver}");
        $this->info("Connection: {$this->connectionName}");
        $this->displayConnectionInfo();

        if ($compare) {
            $this->info("\n=== COMPARISON MODE: Testing persistent vs non-persistent connections ===");

            // Test non-persistent first
            $this->info("\n=== Test 1: Non-Persistent Connections ===");
            $this->configureConnection(false);
            $nonPersistentResults = $this->runFullTest($queryCount, $noCache, 'Non-Persistent');

            // Test persistent connections
            $this->info("\n=== Test 2: Persistent Connections ===");
            $this->configureConnection(true);
            $persistentResults = $this->runFullTest($queryCount, $noCache, 'Persistent');

            // Display comparison
            $this->displayComparisonResults($nonPersistentResults, $persistentResults);
        } else {
            // Single test mode
            $connectionType = $usePersistent ? 'Persistent' : 'Non-Persistent';
            $this->info("\n=== Testing {$connectionType} Connections ===");

            $this->configureConnection($usePersistent);
            $results = $this->runFullTest($queryCount, $noCache, $connectionType);

            $this->displaySingleResults($results);
        }

        // Restore original configuration
        $this->restoreConnection();
    }

    /**
     * Configure connection for persistent or non-persistent
     */
    protected function configureConnection(bool $persistent)
    {
        $config = $this->originalConfig;

        if ($persistent) {
            // Enable persistent connections
            $config['options'] = array_merge(
                $config['options'] ?? [],
                [\PDO::ATTR_PERSISTENT => true]
            );
        } else {
            // Ensure persistent connections are disabled
            $config['options'] = array_merge(
                $config['options'] ?? [],
                [\PDO::ATTR_PERSISTENT => false]
            );
        }

        // Update configuration
        Config::set('database.connections.' . $this->connectionName, $config);

        // Force reconnection
        DB::purge($this->connectionName);

        $persistentStatus = $persistent ? 'ENABLED' : 'DISABLED';
        $this->info("PDO Persistent Connections: {$persistentStatus}");
    }

    /**
     * Restore original connection configuration
     */
    protected function restoreConnection()
    {
        Config::set('database.connections.' . $this->connectionName, $this->originalConfig);
        DB::purge($this->connectionName);
        $this->info("Connection configuration restored to original settings");
    }

    /**
     * Run a full test (cold start + batch)
     */
    protected function runFullTest(int $queryCount, bool $noCache, string $connectionType): array
    {
        // Cold start test
        $this->info("Running cold start test...");
        $coldStart = $this->measureColdStart($noCache, $connectionType);

        // Batch test
        $this->info("Running batch test ({$queryCount} queries)...");
        $batch = $this->measureBatchQueries($queryCount, $noCache, $connectionType);

        // Connection reuse test (specific to persistent connections)
        $this->info("Running connection reuse test...");
        $connectionReuse = $this->measureConnectionReuse($connectionType);

        return [
            'type' => $connectionType,
            'cold_start' => $coldStart,
            'batch' => $batch,
            'connection_reuse' => $connectionReuse,
        ];
    }

    /**
     * Build query with optional SQL_NO_CACHE
     */
    protected function buildQuery(bool $noCache): string
    {
        $query = "SELECT 1 as result";

        if ($this->dbDriver === 'mysql' && $noCache) {
            $query = "SELECT SQL_NO_CACHE 1 as result";
        }

        return $query;
    }

    /**
     * Measure cold start query time
     */
    protected function measureColdStart(bool $noCache, string $connectionType): array
    {
        $query = $this->buildQuery($noCache);

        // Force a fresh connection by disconnecting first
        DB::connection($this->connectionName)->disconnect();

        $startTime = hrtime(true);
        $result = DB::connection($this->connectionName)->select($query);
        $endTime = hrtime(true);

        $durationNs = $endTime - $startTime;
        $durationMs = round($durationNs / 1_000_000, 3);

        $coldStartResult = [
            'query' => $query,
            'connection_type' => $connectionType,
            'duration_ms' => $durationMs,
            'result_count' => count($result),
        ];

        $this->info("Cold start ({$connectionType}): {$durationMs} ms");

        Log::info("Benchmark [{$this->dbDriver}]: Cold Start - {$connectionType}", [
            'driver' => $this->dbDriver,
            'connection' => $this->connectionName,
            'connection_type' => $connectionType,
            'duration_ms' => $durationMs,
            'query' => $query,
        ]);

        return $coldStartResult;
    }

    /**
     * Measure batch queries
     */
    protected function measureBatchQueries(int $queryCount, bool $noCache, string $connectionType): array
    {
        $query = $this->buildQuery($noCache);
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

        sort($times);
        $p50Index = (int) floor(count($times) * 0.5);
        $p95Index = (int) floor(count($times) * 0.95);
        $p99Index = (int) floor(count($times) * 0.99);

        $batchResults = [
            'query' => $query,
            'connection_type' => $connectionType,
            'query_count' => $queryCount,
            'total_duration_ms' => round($totalDurationNs / 1_000_000, 3),
            'avg_duration_ms' => round($avgDurationNs / 1_000_000, 3),
            'min_duration_ms' => round($minDurationNs / 1_000_000, 3),
            'max_duration_ms' => round($maxDurationNs / 1_000_000, 3),
            'p50_duration_ms' => round($times[$p50Index] / 1_000_000, 3),
            'p95_duration_ms' => round($times[$p95Index] / 1_000_000, 3),
            'p99_duration_ms' => round($times[$p99Index] / 1_000_000, 3),
            'queries_per_second' => round($queryCount / ($totalDurationNs / 1_000_000_000), 1),
        ];

        $this->info("Batch ({$connectionType}): Avg {$batchResults['avg_duration_ms']} ms, {$batchResults['queries_per_second']} QPS");

        Log::info("Benchmark [{$this->dbDriver}]: Batch Queries - {$connectionType}", [
            'driver' => $this->dbDriver,
            'connection' => $this->connectionName,
            'connection_type' => $connectionType,
            'query_count' => $queryCount,
            'avg_duration_ms' => $batchResults['avg_duration_ms'],
            'p95_duration_ms' => $batchResults['p95_duration_ms'],
            'queries_per_second' => $batchResults['queries_per_second'],
        ]);

        return $batchResults;
    }

    /**
     * Measure connection reuse performance (multiple disconnects/reconnects)
     */
    protected function measureConnectionReuse(string $connectionType): array
    {
        $this->info("Testing connection reuse pattern...");

        $iterations = 10;
        $times = [];
        $query = $this->buildQuery(false);

        for ($i = 0; $i < $iterations; $i++) {
            // Disconnect to simulate end of request
            DB::connection($this->connectionName)->disconnect();

            // Measure time to reconnect and query (simulates new request)
            $startTime = hrtime(true);
            $result = DB::connection($this->connectionName)->select($query);
            $endTime = hrtime(true);

            $durationNs = $endTime - $startTime;
            $times[] = $durationNs;
        }

        $avgDurationNs = array_sum($times) / count($times);
        $minDurationNs = min($times);
        $maxDurationNs = max($times);

        $reuseResults = [
            'connection_type' => $connectionType,
            'iterations' => $iterations,
            'avg_reconnect_ms' => round($avgDurationNs / 1_000_000, 3),
            'min_reconnect_ms' => round($minDurationNs / 1_000_000, 3),
            'max_reconnect_ms' => round($maxDurationNs / 1_000_000, 3),
        ];

        $this->info("Connection reuse ({$connectionType}): Avg {$reuseResults['avg_reconnect_ms']} ms per reconnect");

        Log::info("Benchmark [{$this->dbDriver}]: Connection Reuse - {$connectionType}", [
            'driver' => $this->dbDriver,
            'connection' => $this->connectionName,
            'connection_type' => $connectionType,
            'avg_reconnect_ms' => $reuseResults['avg_reconnect_ms'],
            'iterations' => $iterations,
        ]);

        return $reuseResults;
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

        if ($this->dbDriver === 'mysql') {
            $details['Host'] = $config['host'] ?? 'N/A';
            $details['Port'] = $config['port'] ?? 'N/A';
            $details['Database'] = $config['database'] ?? 'N/A';
        } elseif ($this->dbDriver === 'pgsql') {
            $details['Host'] = $config['host'] ?? 'N/A';
            $details['Port'] = $config['port'] ?? 'N/A';
            $details['Database'] = $config['database'] ?? 'N/A';
        }

        // Check current persistent connection setting
        $persistentEnabled = ($config['options'][\PDO::ATTR_PERSISTENT] ?? false) ? 'Yes' : 'No';
        $details['Current Persistent Setting'] = $persistentEnabled;

        $rows = [];
        foreach ($details as $key => $value) {
            $rows[] = [$key, $value];
        }

        $this->table(['Setting', 'Value'], $rows);
    }

    /**
     * Display single test results
     */
    protected function displaySingleResults(array $results)
    {
        $this->info("\n" . str_repeat("=", 60));
        $this->info("PDO PERSISTENT CONNECTION BENCHMARK - {$results['type']}");
        $this->info("Driver: {$this->dbDriver} | Connection: {$this->connectionName}");
        $this->info(str_repeat("=", 60));

        $coldStart = $results['cold_start'];
        $batch = $results['batch'];
        $reuse = $results['connection_reuse'];

        // Display results
        $this->info("\n🔸 COLD START QUERY");
        $this->table(['Metric', 'Value'], [
            ['Duration (ms)', $coldStart['duration_ms']],
            ['Connection Type', $coldStart['connection_type']],
        ]);

        $this->info("\n🔸 BATCH QUERIES ({$batch['query_count']} queries)");
        $this->table(['Metric', 'Value'], [
            ['Average (ms)', $batch['avg_duration_ms']],
            ['P95 (ms)', $batch['p95_duration_ms']],
            ['P99 (ms)', $batch['p99_duration_ms']],
            ['Queries/sec', $batch['queries_per_second']],
        ]);

        $this->info("\n🔸 CONNECTION REUSE ({$reuse['iterations']} reconnects)");
        $this->table(['Metric', 'Value'], [
            ['Avg Reconnect (ms)', $reuse['avg_reconnect_ms']],
            ['Min Reconnect (ms)', $reuse['min_reconnect_ms']],
            ['Max Reconnect (ms)', $reuse['max_reconnect_ms']],
        ]);

        $this->info("\n✅ All benchmark results have been logged to the Laravel log file.");
    }

    /**
     * Display comparison results
     */
    protected function displayComparisonResults(array $nonPersistent, array $persistent)
    {
        $this->info("\n" . str_repeat("=", 70));
        $this->info("PDO PERSISTENT vs NON-PERSISTENT CONNECTION COMPARISON");
        $this->info("Driver: {$this->dbDriver} | Connection: {$this->connectionName}");
        $this->info(str_repeat("=", 70));

        // Cold start comparison
        $this->info("\n🔸 COLD START COMPARISON");
        $coldImprovement = round($nonPersistent['cold_start']['duration_ms'] / $persistent['cold_start']['duration_ms'], 1);
        $this->table(['Metric', 'Non-Persistent', 'Persistent', 'Improvement'], [
            [
                'Cold Start (ms)',
                $nonPersistent['cold_start']['duration_ms'],
                $persistent['cold_start']['duration_ms'],
                "{$coldImprovement}x faster"
            ]
        ]);

        // Batch comparison
        $this->info("\n🔸 BATCH QUERIES COMPARISON");
        $avgImprovement = round($nonPersistent['batch']['avg_duration_ms'] / $persistent['batch']['avg_duration_ms'], 1);
        $qpsImprovement = round($persistent['batch']['queries_per_second'] / $nonPersistent['batch']['queries_per_second'], 1);

        $this->table(['Metric', 'Non-Persistent', 'Persistent', 'Improvement'], [
            [
                'Average (ms)',
                $nonPersistent['batch']['avg_duration_ms'],
                $persistent['batch']['avg_duration_ms'],
                "{$avgImprovement}x faster"
            ],
            [
                'P95 (ms)',
                $nonPersistent['batch']['p95_duration_ms'],
                $persistent['batch']['p95_duration_ms'],
                round($nonPersistent['batch']['p95_duration_ms'] / $persistent['batch']['p95_duration_ms'], 1) . "x faster"
            ],
            [
                'Queries/sec',
                $nonPersistent['batch']['queries_per_second'],
                $persistent['batch']['queries_per_second'],
                "{$qpsImprovement}x higher"
            ]
        ]);

        // Connection reuse comparison
        $this->info("\n🔸 CONNECTION REUSE COMPARISON");
        $reconnectImprovement = round($nonPersistent['connection_reuse']['avg_reconnect_ms'] / $persistent['connection_reuse']['avg_reconnect_ms'], 1);

        $this->table(['Metric', 'Non-Persistent', 'Persistent', 'Improvement'], [
            [
                'Avg Reconnect (ms)',
                $nonPersistent['connection_reuse']['avg_reconnect_ms'],
                $persistent['connection_reuse']['avg_reconnect_ms'],
                "{$reconnectImprovement}x faster"
            ]
        ]);

        // Summary and recommendations
        $this->info("\n🔸 PERFORMANCE ANALYSIS");

        if ($avgImprovement > 1.5) {
            $this->info("✅ Persistent connections show significant benefit ({$avgImprovement}x faster avg query)");
            $this->info("📈 Recommended: Enable persistent connections in production");
        } else {
            $this->info("⚠️  Persistent connections show minimal benefit ({$avgImprovement}x faster avg query)");
            $this->info("🤔 Consider other optimizations - connection overhead may not be the bottleneck");
        }

        if ($reconnectImprovement > 2) {
            $this->info("✅ Connection reuse is highly beneficial ({$reconnectImprovement}x faster reconnects)");
        }

        $this->info("\n✅ All benchmark results have been logged to the Laravel log file.");

        // Log comparison summary
        Log::info("Benchmark [{$this->dbDriver}]: Persistent Connection Comparison Summary", [
            'driver' => $this->dbDriver,
            'connection' => $this->connectionName,
            'cold_start_improvement' => $coldImprovement,
            'avg_query_improvement' => $avgImprovement,
            'qps_improvement' => $qpsImprovement,
            'reconnect_improvement' => $reconnectImprovement,
            'recommendation' => $avgImprovement > 1.5 ? 'Enable persistent connections' : 'Investigate other optimizations',
        ]);
    }
}
