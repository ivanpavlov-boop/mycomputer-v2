<?php

namespace App\Console\Commands;

use App\Services\Search\Contracts\SearchServiceInterface;
use Illuminate\Console\Command;

class SearchReindex extends Command
{
    protected $signature = 'search:reindex';

    protected $description = 'Rebuild product and bundle search indexes.';

    public function handle(SearchServiceInterface $search): int
    {
        $count = $search->reindex();

        $this->info("Queued {$count} catalog records for search indexing.");

        return self::SUCCESS;
    }
}
