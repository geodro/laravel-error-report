<?php

namespace Geodro\ErrorReport\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;

class RecordingTest extends TestCase
{
    public function test_a_reported_exception_is_printed_as_a_report(): void
    {
        report(new RuntimeException('the payment gateway timed out'));

        $out = $this->latestReport();

        $this->assertStringContainsString('RuntimeException', $out);
        $this->assertStringContainsString('the payment gateway timed out', $out);
        $this->assertStringContainsString('RecordingTest.php:', $out);
    }

    public function test_errors_are_recorded_when_the_handler_is_wrapped(): void
    {
        $this->app->extend(ExceptionHandler::class, fn ($inner) => new Fixtures\DecoratingHandler($inner));
        $this->app->forgetInstance(ExceptionHandler::class);
        $this->app->make(\Geodro\ErrorReport\ErrorReportServiceProvider::class, ['app' => $this->app])->boot();

        report(new RuntimeException('through a wrapped handler'));

        $this->assertStringContainsString('through a wrapped handler', $this->latestReport());
    }

    public function test_an_exception_the_app_does_not_report_is_not_recorded(): void
    {
        $this->app->make(ExceptionHandler::class)->dontReport(RuntimeException::class);

        report(new RuntimeException('ignored'));

        $this->assertSame(1, Artisan::call('error-report:show', ['id' => 'latest']));
        $this->assertStringContainsString('No errors recorded', Artisan::output());
    }

    public function test_the_same_error_twice_is_one_entry_with_a_count(): void
    {
        for ($i = 0; $i < 2; $i++) {
            report(new RuntimeException('same place'));
        }

        Artisan::call('error-report:list');
        $list = Artisan::output();

        $this->assertSame(1, substr_count($list, 'same place'));
        $this->assertStringContainsString('2', $list);
        $this->assertStringContainsString('Seen 2 times', $this->latestReport());
    }

    public function test_one_exception_reported_twice_counts_once(): void
    {
        $e = new RuntimeException('reported by the job and again by the kernel');
        report($e);
        report($e);

        $this->assertStringContainsString('Seen 1 time,', $this->latestReport());
    }

    public function test_different_problems_from_the_same_place_are_separate_entries(): void
    {
        foreach (['Payment gateway timed out', 'Card was declined', 'Currency not supported'] as $problem) {
            $this->failInHelper($problem);
        }

        Artisan::call('error-report:list');
        $list = Artisan::output();

        $this->assertStringContainsString('timed out', $list);
        $this->assertStringContainsString('declined', $list);
        $this->assertStringContainsString('not supported', $list);
    }

    public function test_the_same_problem_with_different_ids_stays_one_entry(): void
    {
        foreach ([7, 42, 315] as $order) {
            $this->failInHelper("Order {$order} could not be charged");
        }

        $this->assertStringContainsString('Seen 3 times', $this->latestReport());
    }

    public function test_old_entries_are_pruned_past_the_limit(): void
    {
        config(['error-report.max_entries' => 2]);

        report(new RuntimeException('first'));
        report(new \LogicException('second'));
        report(new \DomainException('third'));

        Artisan::call('error-report:list');
        $list = Artisan::output();

        $this->assertStringNotContainsString('first', $list);
        $this->assertStringContainsString('third', $list);
    }

    public function test_an_unwritable_store_never_breaks_the_apps_own_reporting(): void
    {
        $file = __DIR__.'/.store-is-a-file';
        file_put_contents($file, 'x');
        config(['error-report.path' => $file.'/nested']);
        Log::spy();

        try {
            report(new RuntimeException('still logged'));
        } finally {
            unlink($file);
        }

        Log::shouldHaveReceived('error')->withArgs(fn ($msg) => str_contains($msg, 'still logged'));
    }

    public function test_an_http_error_names_the_route_pattern_not_the_real_path(): void
    {
        Route::get('/password/reset/{token}', fn () => throw new RuntimeException('boom'))->name('password.reset');

        $this->get($this->resetLink());

        $out = $this->latestReport();
        $this->assertStringContainsString('GET /password/reset/{token}', $out);
        $this->assertStringContainsString('password.reset', $out);
        $this->assertStringNotContainsString('9f8e7d6c5b4a39281706f5e4d3c2b1a0', $out);
        $this->assertStringNotContainsString('jane@example.com', $out);
    }

    public function test_a_store_another_user_created_stays_writable_and_readable(): void
    {
        report(new RuntimeException('first error creates the folder'));

        $this->assertSame('0777', substr(sprintf('%o', fileperms($this->store)), -4));
        foreach (glob($this->store.'/*.json') as $file) {
            $this->assertSame('0666', substr(sprintf('%o', fileperms($file)), -4));
        }
    }

    public function test_an_unreadable_store_refuses_instead_of_looking_empty(): void
    {
        report(new RuntimeException('recorded'));
        $store = $this->app->make(\Geodro\ErrorReport\Store::class);
        chmod($this->store, 0000);
        // Root reads anything, so read as an unprivileged account the way a second user would.
        $asRoot = posix_geteuid() === 0;
        $asRoot && posix_seteuid(65534);

        try {
            $store->all();
            $error = null;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } finally {
            $asRoot && posix_seteuid(0);
            chmod($this->store, 0777);
        }

        $this->assertStringContainsString('Cannot read', (string) $error);
    }

    public function test_the_commands_fail_loudly_when_the_store_refuses(): void
    {
        $this->app->instance(\Geodro\ErrorReport\Store::class, new class($this->store, 1) extends \Geodro\ErrorReport\Store
        {
            public function all(): array
            {
                throw new RuntimeException('Cannot read the store');
            }

            public function find(string $id): ?array
            {
                throw new RuntimeException('Cannot read the store');
            }
        });

        $this->assertSame(1, Artisan::call('error-report:list'));
        $this->assertStringContainsString('Cannot read', Artisan::output());
        $this->assertSame(1, Artisan::call('error-report:show', ['id' => 'latest']));
        $this->assertStringContainsString('Cannot read', Artisan::output());
    }

    public function test_an_error_after_a_worker_moves_on_is_not_blamed_on_the_last_job(): void
    {
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class)->shouldIgnoreMissing();
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendInvoice');
        event(new \Illuminate\Queue\Events\JobProcessing('redis', $job));
        report(new RuntimeException('inside the job'));
        event(new \Illuminate\Queue\Events\Looping('redis', 'default'));

        report(new \LogicException('between jobs'));

        $out = $this->latestReport();
        $this->assertStringContainsString('between jobs', $out);
        $this->assertMatchesRegularExpression('/^Context: console command/m', $out);
    }

    public function test_an_unknown_id_fails_with_a_message(): void
    {
        $this->assertSame(1, Artisan::call('error-report:show', ['id' => 'nope']));
        $this->assertStringContainsString('nope', Artisan::output());
    }

    private function resetLink(): string
    {
        return '/password/reset/9f8e7d6c5b4a39281706f5e4d3c2b1a0?email=jane@example.com';
    }

    // One throw site and one caller, like an app's shared API helper.
    private function failInHelper(string $message): void
    {
        report(new RuntimeException($message));
    }
}
