<?php

namespace App\Console\Commands\Roadmap;

use App\Jobs\Roadmap\PruneRoadmapDataJob;
use Illuminate\Console\Command;

class PruneRoadmapData extends Command
{
    protected $signature = 'roadmap:prune';

    protected $description = 'Null old IP hashes, delete old abuse events and idle anonymous visitors';

    public function handle(): int
    {
        $counts = (new PruneRoadmapDataJob)->handle();

        foreach ($counts as $what => $n) {
            $this->line(str_pad($what, 20).$n);
        }
        $this->info('Roadmap data pruned.');

        return self::SUCCESS;
    }
}
