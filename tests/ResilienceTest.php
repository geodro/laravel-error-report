<?php

namespace Geodro\ErrorReport\Tests;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class ResilienceTest extends TestCase
{
    public function test_a_job_whose_name_cannot_be_read_still_runs(): void
    {
        $job = \Mockery::mock(Job::class)->shouldIgnoreMissing();
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldReceive('resolveName')->andThrow(new RuntimeException('malformed payload'));

        event(new JobProcessing('redis', $job));
        report(new RuntimeException('the job itself failed'));

        $this->assertSame(0, Artisan::call('error-report:show', ['id' => 'latest']));
        $this->assertStringContainsString('the job itself failed', Artisan::output());
    }

    public function test_a_mail_server_that_never_answers_does_not_hold_the_request(): void
    {
        // Accepts the connection but never sends the SMTP greeting, like a hung server.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.timeout' => null, 'error-report.mail' => ['ops@example.test'], 'error-report.mail_timeout' => 1]);

        $started = microtime(true);
        report(new RuntimeException('while smtp hangs'));
        $elapsed = microtime(true) - $started;
        fclose($server);

        $this->assertLessThan(3, $elapsed, "reporting waited {$elapsed}s on a hung mail server");
        $this->assertSame(0, Artisan::call('error-report:show', ['id' => 'latest']));
    }

    public function test_an_exception_without_a_message_is_recorded_and_mailed(): void
    {
        config(['mail.mailers.capture' => ['transport' => 'capture'], 'mail.default' => 'capture', 'error-report.mail' => ['ops@example.test']]);
        $captured = new \Illuminate\Mail\Transport\ArrayTransport;
        \Illuminate\Support\Facades\Mail::extend('capture', fn () => $captured);

        report(new RuntimeException);

        $this->assertCount(1, $captured->messages());
        $email = $captured->messages()->first()->getOriginalMessage();
        $this->assertSame('[Laravel] RuntimeException: (no message) (seen 1x)', $email->getSubject());
        $this->assertStringContainsString('(no message)', (string) $email->getHtmlBody());
    }

    public function test_the_apps_own_mailer_keeps_its_timeout_after_a_report(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 30, 'error-report.mail' => ['ops@example.test'], 'error-report.mail_timeout' => 1]);

        report(new RuntimeException('smtp refused'));

        $this->assertSame(30.0, app('mailer')->getSymfonyTransport()->getStream()->getTimeout());
    }
}
