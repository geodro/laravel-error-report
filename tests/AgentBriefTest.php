<?php

namespace Geodro\ErrorReport\Tests;

use Illuminate\Support\Facades\Route;
use RuntimeException;

class AgentBriefTest extends TestCase
{
    public function test_an_http_error_tells_the_agent_which_release_and_route_to_reproduce(): void
    {
        config(['error-report.release' => 'abc123def456']);
        Route::get('/orders/{order}', fn () => throw new RuntimeException('boom'));

        $this->get('/orders/5');
        $out = $this->latestReport();

        $brief = $this->brief($out);
        $this->assertStringContainsString('happened in production 1 time', $brief);
        $this->assertStringContainsString('release abc123def456', $brief);
        $this->assertStringContainsString('lerd worktree', $brief);
        $this->assertStringContainsString('git log abc123def456..', $brief);
        $this->assertStringContainsString('send GET /orders/{order} to the local lerd site', $brief);
        $this->assertStringContainsString('Report back', $brief);
    }

    public function test_an_unknown_release_says_how_to_find_it(): void
    {
        report(new RuntimeException('boom'));

        $brief = $this->brief($this->latestReport());

        $this->assertStringContainsString('release is unknown', $brief);
        $this->assertStringContainsString('APP_RELEASE', $brief);
        $this->assertStringNotContainsString('git log', $brief);
    }

    public function test_a_queue_job_is_reproduced_by_dispatching_it(): void
    {
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class)->shouldIgnoreMissing();
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendInvoice');
        event(new \Illuminate\Queue\Events\JobProcessing('redis', $job));

        report(new RuntimeException('boom'));

        $this->assertStringContainsString('dispatch App\\Jobs\\SendInvoice on the local lerd site', $this->brief($this->latestReport()));
    }

    public function test_a_console_command_is_reproduced_by_running_it(): void
    {
        $argv = $_SERVER['argv'];
        $_SERVER['argv'] = ['artisan', 'orders:sync'];

        try {
            report(new RuntimeException('boom'));
        } finally {
            $_SERVER['argv'] = $argv;
        }

        $this->assertStringContainsString('run php artisan orders:sync on the local lerd site', $this->brief($this->latestReport()));
    }

    public function test_a_cause_chain_points_the_agent_at_the_deepest_cause(): void
    {
        report(new RuntimeException('outer', 0, new \LogicException('middle', 0, new \DomainException('root'))));

        $this->assertStringContainsString('Start from the deepest cause, DomainException', $this->brief($this->latestReport()));
    }

    public function test_no_cause_chain_means_no_cause_hint(): void
    {
        report(new RuntimeException('alone'));

        $this->assertStringNotContainsString('deepest cause', $this->brief($this->latestReport()));
    }

    // The brief is our own text, so it must sit above the captured block, never inside it.
    private function brief(string $report): string
    {
        $this->assertStringContainsString('For the agent fixing this', $report);
        $start = strpos($report, 'For the agent fixing this');
        $this->assertLessThan(strpos($report, 'BEGIN CAPTURED ERROR'), $start);

        return substr($report, $start, strpos($report, 'BEGIN CAPTURED ERROR') - $start);
    }
}
