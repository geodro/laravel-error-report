<?php

namespace Geodro\ErrorReport\Console;

use Illuminate\Console\Command;
use Geodro\ErrorReport\ReportFormatter;
use Geodro\ErrorReport\Store;

class ShowError extends Command
{
    protected $signature = 'error-report:show {id=latest : An id from error-report:list, or latest}';

    protected $description = 'Print one recorded error as an anonymised report to paste into a coding agent';

    public function handle(Store $store): int
    {
        try {
            return $this->show($store);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function show(Store $store): int
    {
        $id = $this->argument('id');
        $id = is_string($id) ? $id : 'latest';
        $entry = $store->find($id);
        if (! $entry) {
            $this->error($id === 'latest' ? 'No errors recorded.' : "No recorded error with id {$id}.");

            return self::FAILURE;
        }

        $this->output->write(ReportFormatter::format($entry), false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
