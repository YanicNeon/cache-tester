<?php

namespace App\Console\Commands;

use App\Models\Benchmark;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BenchmarkDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'benchmark:db 
                            {--records=100 : Number of records to create for benchmarking}
                            {--iterations=5 : Number of iterations to run for each operation}
                            {--chunk-size=10 : Chunk size for batch operations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run database operations and measure latency';

    /**
     * Results storage
     */
    protected array $results = [];

    /**
     * The database driver being used
     */
    protected string $dbDriver;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $records = $this->option('records');
        $iterations = $this->option('iterations');
        $chunkSize = $this->option('chunk-size');

        // Get the database driver
        $this->dbDriver = config('database.connections.' . config('database.default') . '.driver');

        $this->info("Starting database benchmark with {$records} records, {$iterations} iterations");
        $this->info("Database Driver: {$this->dbDriver}");
        $this->displayConnectionInfo();

        // Clean up any existing benchmark data
        Benchmark::truncate();
        $this->info("Cleaned up previous benchmark data");

        // Run the benchmark operations
        // $this->benchmarkInsert($records, $iterations);
        // $this->benchmarkBulkInsert($records, $iterations, $chunkSize);
        $this->benchmarkFind($iterations);
        // $this->benchmarkQuery($iterations);
        // $this->benchmarkUpdate($iterations);
        // $this->benchmarkDelete($iterations);

        // Display the results as a table
        $this->displayResults();
    }

    /**
     * Benchmark individual inserts
     */
    protected function benchmarkInsert(int $records, int $iterations)
    {
        $this->info("Benchmarking individual inserts...");

        $times = [];

        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);

            for ($j = 0; $j < $records; $j++) {
                Benchmark::create([
                    'name' => 'Benchmark Test ' . Str::random(10),
                    'description' => 'This is a test record for benchmarking database performance ' . Str::random(20),
                    'counter' => rand(1, 1000),
                    'value' => rand(1, 1000) / 100,
                    'is_active' => rand(0, 1) === 1,
                    'metadata' => [
                        'iteration' => $i,
                        'record' => $j,
                        'tags' => ['test', 'benchmark', 'laravel-cloud'],
                        'timestamp' => now()->timestamp,
                    ],
                ]);
            }

            $end = microtime(true);
            $times[] = $end - $start;

            // Clean up if not the last iteration
            if ($i < $iterations - 1) {
                Benchmark::truncate();
            }
        }

        $avgTime = array_sum($times) / count($times);
        $this->results['insert'] = [
            'operation' => 'Individual Inserts',
            'driver' => $this->dbDriver,
            'records' => $records,
            'iterations' => $iterations,
            'avg_time' => round($avgTime, 4),
            'avg_per_record' => round($avgTime / $records, 4),
        ];

        Log::info("Benchmark [{$this->dbDriver}]: Individual Inserts", $this->results['insert']);
    }

    /**
     * Benchmark bulk inserts
     */
    protected function benchmarkBulkInsert(int $records, int $iterations, int $chunkSize)
    {
        $this->info("Benchmarking bulk inserts...");

        Benchmark::truncate();
        $times = [];

        for ($i = 0; $i < $iterations; $i++) {
            $data = [];

            for ($j = 0; $j < $records; $j++) {
                $data[] = [
                    'name' => 'Bulk Test ' . Str::random(10),
                    'description' => 'This is a bulk test record for benchmarking ' . Str::random(20),
                    'counter' => rand(1, 1000),
                    'value' => rand(1, 1000) / 100,
                    'is_active' => rand(0, 1) === 1,
                    'metadata' => json_encode([
                        'iteration' => $i,
                        'record' => $j,
                        'tags' => ['test', 'benchmark', 'bulk'],
                        'timestamp' => now()->timestamp,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $start = microtime(true);

            // Insert in chunks
            foreach (array_chunk($data, $chunkSize) as $chunk) {
                Benchmark::insert($chunk);
            }

            $end = microtime(true);
            $times[] = $end - $start;

            // Clean up if not the last iteration
            if ($i < $iterations - 1) {
                Benchmark::truncate();
            }
        }

        $avgTime = array_sum($times) / count($times);
        $this->results['bulk_insert'] = [
            'operation' => 'Bulk Inserts',
            'driver' => $this->dbDriver,
            'records' => $records,
            'iterations' => $iterations,
            'chunk_size' => $chunkSize,
            'avg_time' => round($avgTime, 4),
            'avg_per_record' => round($avgTime / $records, 4),
        ];

        Log::info("Benchmark [{$this->dbDriver}]: Bulk Inserts", $this->results['bulk_insert']);
    }

    /**
     * Benchmark find by ID operations
     */
    protected function benchmarkFind(int $iterations)
    {
        $this->info("Benchmarking find by ID...");

        $times = [];

        // Get all IDs
        $ids = Benchmark::pluck('id')->toArray();

        if (empty($ids)) {
            $this->error("No records found for benchmarking find operations");
            return;
        }

        $totalIds = count($ids);
        $findCount = min(100, $totalIds);

        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);

            for ($j = 0; $j < $findCount; $j++) {
                $randomId = $ids[array_rand($ids)];
                $record = Benchmark::find($randomId);
            }

            $end = microtime(true);
            $times[] = $end - $start;
        }

        $avgTime = array_sum($times) / count($times);
        $this->results['find'] = [
            'operation' => 'Find by ID',
            'driver' => $this->dbDriver,
            'records' => $findCount,
            'iterations' => $iterations,
            'avg_time' => round($avgTime, 4),
            'avg_per_record' => round($avgTime / $findCount, 4),
        ];

        Log::info("Benchmark [{$this->dbDriver}]: Find by ID", $this->results['find']);
        Log::info("Benchmark [{$this->dbDriver}]: Find by ID detailed", $times);
    }

    /**
     * Benchmark query operations
     */
    protected function benchmarkQuery(int $iterations)
    {
        $this->info("Benchmarking queries...");

        $times = [];

        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);

            // Test different query types
            $count = Benchmark::count();
            $avg = Benchmark::avg('counter');
            $sum = Benchmark::sum('counter');
            $where = Benchmark::where('is_active', true)->limit(100)->get();
            $whereWith = Benchmark::where('counter', '>', 500)->where('is_active', true)->limit(50)->get();
            $orderBy = Benchmark::orderBy('counter', 'desc')->limit(50)->get();

            $end = microtime(true);
            $times[] = $end - $start;
        }

        $avgTime = array_sum($times) / count($times);
        $this->results['query'] = [
            'operation' => 'Complex Queries',
            'driver' => $this->dbDriver,
            'queries_per_iteration' => 6,
            'iterations' => $iterations,
            'avg_time' => round($avgTime, 4),
            'avg_per_query' => round($avgTime / 6, 4),
        ];

        Log::info("Benchmark [{$this->dbDriver}]: Queries", $this->results['query']);
    }

    /**
     * Benchmark update operations
     */
    protected function benchmarkUpdate(int $iterations)
    {
        $this->info("Benchmarking updates...");

        $times = [];

        // Get a sample of IDs to update
        $ids = Benchmark::pluck('id')->toArray();

        if (empty($ids)) {
            $this->error("No records found for benchmarking update operations");
            return;
        }

        $totalIds = count($ids);
        $updateCount = min(100, $totalIds);
        $sampleIds = array_slice($ids, 0, $updateCount);

        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);

            foreach ($sampleIds as $id) {
                Benchmark::where('id', $id)->update([
                    'counter' => rand(1, 2000),
                    'value' => rand(1, 2000) / 100,
                    'updated_at' => now(),
                ]);
            }

            $end = microtime(true);
            $times[] = $end - $start;
        }

        $avgTime = array_sum($times) / count($times);
        $this->results['update'] = [
            'operation' => 'Updates',
            'driver' => $this->dbDriver,
            'records' => $updateCount,
            'iterations' => $iterations,
            'avg_time' => round($avgTime, 4),
            'avg_per_record' => round($avgTime / $updateCount, 4),
        ];

        Log::info("Benchmark [{$this->dbDriver}]: Updates", $this->results['update']);
    }

    /**
     * Benchmark delete operations
     */
    protected function benchmarkDelete(int $iterations)
    {
        $this->info("Benchmarking deletes...");

        // Create some records specifically for delete testing
        // so we don't affect the other benchmarks
        $this->info("Creating records for delete testing...");
        $deleteRecords = 100;

        $times = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Create records for deletion
            $deleteIds = [];
            for ($j = 0; $j < $deleteRecords; $j++) {
                $record = Benchmark::create([
                    'name' => 'Delete Test ' . Str::random(10),
                    'description' => 'This record will be deleted',
                    'counter' => rand(1, 1000),
                ]);
                $deleteIds[] = $record->id;
            }

            $start = microtime(true);

            foreach ($deleteIds as $id) {
                Benchmark::where('id', $id)->delete();
            }

            $end = microtime(true);
            $times[] = $end - $start;
        }

        $avgTime = array_sum($times) / count($times);
        $this->results['delete'] = [
            'operation' => 'Deletes',
            'driver' => $this->dbDriver,
            'records' => $deleteRecords,
            'iterations' => $iterations,
            'avg_time' => round($avgTime, 4),
            'avg_per_record' => round($avgTime / $deleteRecords, 4),
        ];

        Log::info("Benchmark [{$this->dbDriver}]: Deletes", $this->results['delete']);
    }

    /**
     * Display the results as a table
     */
    protected function displayResults()
    {
        $this->info("\nDatabase Benchmark Results:");
        $this->info("Database Driver: {$this->dbDriver}");

        $headers = ['Operation', 'Driver', 'Records', 'Iterations', 'Avg Time (s)', 'Time Per Record (s)'];
        $rows = [];

        foreach ($this->results as $result) {
            $rows[] = [
                $result['operation'],
                $result['driver'],
                $result['records'] ?? '-',
                $result['iterations'],
                $result['avg_time'],
                $result['avg_per_record'] ?? '-',
            ];
        }

        $this->table($headers, $rows);

        $this->info("\nAll benchmark results have been logged to the Laravel log file.");
    }

    /**
     * Display database connection information
     */
    protected function displayConnectionInfo()
    {
        $connection = config('database.default');
        $config = config('database.connections.' . $connection);

        $this->info("Database Connection Details:");

        $details = [
            'Connection' => $connection,
            'Driver' => $this->dbDriver,
        ];

        // Add driver-specific information
        if ($this->dbDriver === 'mysql') {
            $details['Host'] = $config['host'] ?? 'N/A';
            $details['Port'] = $config['port'] ?? 'N/A';
            $details['Database'] = $config['database'] ?? 'N/A';
            $details['Charset'] = $config['charset'] ?? 'N/A';
            $details['Collation'] = $config['collation'] ?? 'N/A';
            $details['Prefix'] = $config['prefix'] ?: 'None';
            $details['Strict Mode'] = ($config['strict'] ?? false) ? 'Enabled' : 'Disabled';
        } elseif ($this->dbDriver === 'pgsql') {
            $details['Host'] = $config['host'] ?? 'N/A';
            $details['Port'] = $config['port'] ?? 'N/A';
            $details['Database'] = $config['database'] ?? 'N/A';
            $details['Schema'] = $config['schema'] ?? 'public';
            $details['Charset'] = $config['charset'] ?? 'N/A';
            $details['Prefix'] = $config['prefix'] ?: 'None';
        } elseif ($this->dbDriver === 'sqlite') {
            $details['Database'] = $config['database'] ?? 'N/A';
            $details['Prefix'] = $config['prefix'] ?: 'None';
        }

        // Display connection details table
        $rows = [];
        foreach ($details as $key => $value) {
            $rows[] = [$key, $value];
        }

        $this->table(['Setting', 'Value'], $rows);
    }
}
