<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateRedisTraffic extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'redis:generate-traffic {--kb-write=5000 : KB to write} {--kb-read=1000 : KB to read}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate specified amount of traffic with Redis';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = microtime(true);
        $kbToWrite = $this->option('kb-write');
        $kbToRead = $this->option('kb-read');

        // Generate payloads of specific sizes
        $writePayloadSize = $kbToWrite * 1024; // Convert to bytes
        $readPayloadSize = $kbToRead * 1024;   // Convert to bytes

        // Metrics tracking
        $actualBytesWritten = 0;
        $actualBytesRead = 0;

        // ========= WRITE OPERATION =========
        $this->info("Starting to write {$kbToWrite}KB to Redis...");

        // Create data chunks of 100KB to write
        $chunkSize = 100 * 1024; // 100KB chunks
        $uniquePrefix = Str::random(8) . ':' . time() . ':';
        $chunksCreated = 0;

        while ($actualBytesWritten < $writePayloadSize) {
            // Generate random data for this chunk
            $remainingBytes = $writePayloadSize - $actualBytesWritten;
            $currentChunkSize = min($chunkSize, $remainingBytes);
            $data = Str::random(intval($currentChunkSize / 2)); // Str::random produces ~2 bytes per character

            $key = $uniquePrefix . $chunksCreated;
            Redis::set($key, $data);
            Redis::expire($key, 300); // Expire in 5 minutes to avoid filling memory

            $bytesInThisChunk = strlen($key) + strlen($data);
            $actualBytesWritten += $bytesInThisChunk;
            $chunksCreated++;

            // Progress indicator
            if ($chunksCreated % 10 == 0) {
                $this->info("Written {$chunksCreated} chunks, approximately " .
                    round($actualBytesWritten / 1024 / 1024, 2) . "MB so far");
            }
        }

        // ========= READ OPERATION =========
        $this->info("Starting to read {$kbToRead}KB from Redis...");

        // Read back some of the data we just wrote
        $keysToRead = min($chunksCreated, ceil($readPayloadSize / $chunkSize));

        for ($i = 0; $i < $keysToRead; $i++) {
            $key = $uniquePrefix . $i;
            $data = Redis::get($key);

            if ($data) {
                $bytesInThisRead = strlen($key) + strlen($data);
                $actualBytesRead += $bytesInThisRead;
            }

            // Progress indicator
            if (($i + 1) % 10 == 0) {
                $this->info("Read { $i + 1 } chunks, approximately " .
                    round($actualBytesRead / 1024 / 1024, 2) . "MB so far");
            }
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Summary
        $writtenMB = round($actualBytesWritten / 1024 / 1024, 2);
        $readMB = round($actualBytesRead / 1024 / 1024, 2);

        $summary = [
            'timestamp' => now()->toDateTimeString(),
            'execution_time' => round($executionTime, 2) . 's',
            'written_bytes' => $actualBytesWritten,
            'written_kb' => round($actualBytesWritten / 1024, 2),
            'written_mb' => $writtenMB,
            'read_bytes' => $actualBytesRead,
            'read_kb' => round($actualBytesRead / 1024, 2),
            'read_mb' => $readMB,
            'chunks_created' => $chunksCreated,
            'chunks_read' => $keysToRead,
        ];

        // Log the results
        Log::info('Redis traffic metrics', $summary);

        $this->table(
            ['Metric', 'Value'],
            collect($summary)->map(fn($value, $key) => ['Metric' => $key, 'Value' => $value])->toArray()
        );

        return Command::SUCCESS;
    }
}
