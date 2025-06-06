<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class PingDigitalOcean extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ping-digital-ocean {times=20}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        for ($i = 0; $i < $this->argument('times'); $i++) {
            $response = $this->pingDigitalOcean();
            if ($response->successful()) {
                $this->info("Ping #$i: Success");
            } else {
                $this->error("Ping #$i: Failed with status code " . $response->status());
            }
        }
    }

    private function pingDigitalOcean()
    {
        return Http::timeout(5)->get('https://calvin-app-platform-test-sco5k.ondigitalocean.app/api/random-data');
    }
}
