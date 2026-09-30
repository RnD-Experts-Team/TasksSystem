<?php

namespace App\Console\Commands\Roadmap;

use App\Services\Roadmap\PostCounters;
use Illuminate\Console\Command;

class RecountRoadmap extends Command
{
    protected $signature = 'roadmap:recount';

    protected $description = 'Recompute votes_count / comments_count of every post and the counters of every visitor';

    public function handle(PostCounters $counters): int
    {
        $counters->recountAll();
        $this->info('Roadmap counters recomputed.');

        return self::SUCCESS;
    }
}
