<?php

namespace Geodro\ErrorReport;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

class ErrorReportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/error-report.php', 'error-report');
        $this->app->singleton(Recorder::class);
        $this->app->bind(Store::class, fn ($app) => new Store(
            $app['config']->get('error-report.path'),
            (int) $app['config']->get('error-report.max_entries'),
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/error-report.php' => config_path('error-report.php')], 'error-report-config');

        if ($this->app->runningInConsole()) {
            $this->commands([Console\ListErrors::class, Console\ShowError::class]);
        }

        if (! $this->app['config']->get('error-report.enabled')) {
            return;
        }

        // Hooking the handler's report path means dontReport and the app's own filters still apply.
        $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e) {
                    $this->app->make(Recorder::class)->record($e);
                });
            }
        });

        Event::listen(JobProcessing::class, fn ($e) => $this->app->make(Recorder::class)->setJob($e->job->resolveName()));
        // A worker that moves on must not blame later errors on the last job, and a failed job never fires JobProcessed.
        Event::listen([JobProcessed::class, Looping::class], fn () => $this->app->make(Recorder::class)->setJob(null));
    }
}
