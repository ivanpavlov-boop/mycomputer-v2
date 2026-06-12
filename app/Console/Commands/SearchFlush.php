<?php

namespace App\Console\Commands;

use App\Services\Search\Contracts\SearchServiceInterface;
use Illuminate\Console\Command;

class SearchFlush extends Command
{
    protected $signature = 'search:flush';

    protected $description = 'Remove all products and bundles from the search indexes.';

    public function handle(SearchServiceInterface $search): int
    {
        $search->flush();

        $this->info('Product and bundle search indexes flushed.');

        return self::SUCCESS;
    }
}
