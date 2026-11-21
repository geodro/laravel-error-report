<?php

namespace Geodro\ErrorReport\Console;

use Illuminate\Console\Command;
use Geodro\ErrorReport\Store;

class ListErrors extends Command
{
    protected $signature = 'error-report:list {--clear : Delete every recorded error}';

    protected $description = 'List the errors recorded on this server, newest first';

    public function handle(Store $store): int
    {
        try {
            return $this->report($store);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function report(Store $store): int
    {
        if ($this->option('clear')) {
            if (! $this->confirm('Delete every recorded error?', true)) {
                return self::FAILURE;
            }
            $store->clear();
            $this->info('Recorded errors deleted.');

            return self::SUCCESS;
        }

        $entries = $store->all();
        if (! $entries) {
            $this->info('No errors recorded.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Count', 'Last seen', 'Error'], array_map(fn ($e) => [
            $e['id'],
            $e['count'],
            $e['last_seen'],
            mb_strimwidth($e['last']['error']['class'].': '.strtok($e['last']['error']['message'], "\n"), 0, 90, '…'),
        ], $entries));
        $this->line('Print one for your agent with: php artisan error-report:show <id>');

        return self::SUCCESS;
    }
}
