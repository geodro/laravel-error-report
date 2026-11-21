<?php

namespace Geodro\ErrorReport\Tests;

use Illuminate\Support\Facades\File;
use Geodro\ErrorReport\ErrorReportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $store;

    protected function getPackageProviders($app): array
    {
        return [ErrorReportServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->store = __DIR__.'/.store';
        File::deleteDirectory($this->store);
        $app['config']->set('error-report.path', $this->store);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        parent::tearDown();
    }

    protected function latestReport(): string
    {
        \Illuminate\Support\Facades\Artisan::call('error-report:show', ['id' => 'latest']);

        return \Illuminate\Support\Facades\Artisan::output();
    }
}
