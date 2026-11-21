<?php

namespace Geodro\ErrorReport\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use RuntimeException;

class ChannelTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('logging.channels.ping', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        $app['config']->set('logging.channels.broken', ['driver' => 'monolog', 'handler' => FailingHandler::class]);
        // A stand-in for a real delivering transport (SES, Postmark...), so tests can read what was sent.
        $app['config']->set('mail.mailers.capture', ['transport' => 'capture']);
        $app['config']->set('mail.default', 'capture');
        $app['config']->set('logging.channels.maillog', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    }

    public function test_a_new_error_sends_the_anonymised_report_to_each_channel(): void
    {
        config(['error-report.channels' => ['ping']]);
        $message = $this->messageWithAnEmail();

        report(new RuntimeException($message));

        $records = $this->sent('ping');
        $this->assertCount(1, $records);
        $this->assertStringContainsString('BEGIN CAPTURED ERROR', $records[0]->message);
        $this->assertStringContainsString('could not charge [email]', $records[0]->message);
        $this->assertStringNotContainsString('jane@example.com', $records[0]->message);
    }

    public function test_repeats_are_sent_only_at_doubling_counts(): void
    {
        config(['error-report.channels' => ['ping'], 'error-report.mail' => ['ops@example.test']]);

        for ($i = 0; $i < 9; $i++) {
            report(new RuntimeException('keeps happening'));
        }

        $sent = array_map(fn ($r) => preg_match('/Seen (\d+) time/', $r->message, $m) ? (int) $m[1] : 0, $this->sent('ping'));
        $this->assertSame([1, 2, 4, 8], $sent);
        $this->assertCount(4, $this->mails());
    }

    public function test_a_new_error_is_mailed_to_each_address(): void
    {
        config(['error-report.mail' => ['ops@example.test', 'dev@example.test']]);
        $message = $this->messageWithAnEmail();

        report(new RuntimeException($message));

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $email = $mails[0]->getOriginalMessage();
        $this->assertSame(['ops@example.test', 'dev@example.test'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertStringContainsString('RuntimeException', $email->getSubject());
        $this->assertStringContainsString('BEGIN CAPTURED ERROR', $email->getTextBody());
        $this->assertStringNotContainsString('jane@example.com', $email->getTextBody().$email->getSubject());
    }

    public function test_the_mail_has_a_formatted_html_part_and_keeps_the_plain_report(): void
    {
        config(['error-report.mail' => ['ops@example.test']]);
        $message = $this->messageWithMarkup();

        report(new RuntimeException($message));

        $email = $this->mails()[0]->getOriginalMessage();
        $html = (string) $email->getHtmlBody();
        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('RuntimeException', $html);
        $this->assertStringContainsString('For the agent fixing this', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('jane@example.com', $html);
        $this->assertStringContainsString('BEGIN CAPTURED ERROR', $email->getTextBody());
    }

    public function test_a_mail_server_that_is_down_does_not_stop_the_channels_or_the_record(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1,
            'error-report.mail' => ['ops@example.test'], 'error-report.channels' => ['ping']]);

        report(new RuntimeException('smtp outage'));

        $this->assertCount(1, $this->sent('ping'));
        $this->assertSame(0, Artisan::call('error-report:show', ['id' => 'latest']));
        $this->assertStringContainsString('smtp outage', Artisan::output());
    }

    public function test_nothing_is_sent_unless_a_channel_is_configured(): void
    {
        report(new RuntimeException('kept on the server'));

        $this->assertCount(0, $this->sent('ping'));
        $this->assertCount(0, $this->mails());
    }

    public function test_a_failing_channel_does_not_stop_the_others_or_the_record(): void
    {
        config(['error-report.channels' => ['broken', 'ping']]);

        report(new RuntimeException('slack outage'));

        $this->assertCount(1, $this->sent('ping'));
        $this->assertSame(0, Artisan::call('error-report:show', ['id' => 'latest']));
        $this->assertStringContainsString('slack outage', Artisan::output());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->captured = new \Illuminate\Mail\Transport\ArrayTransport;
        \Illuminate\Support\Facades\Mail::extend('capture', fn () => $this->captured);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, fn () => $this->attempts++);
    }

    private \Illuminate\Mail\Transport\ArrayTransport $captured;

    private int $attempts = 0;

    private function mails(): array
    {
        return $this->captured->messages()->all();
    }

    public function test_no_mail_is_attempted_when_the_mailer_only_logs(): void
    {
        config(['error-report.mail' => ['ops@example.test'], 'mail.default' => 'log', 'mail.mailers.log.channel' => 'maillog']);

        report(new RuntimeException('no server'));

        $this->assertSame(0, $this->attempts);
        $this->assertCount(0, $this->sent('maillog'));
    }

    public function test_no_mail_is_attempted_when_smtp_has_no_host(): void
    {
        config(['error-report.mail' => ['ops@example.test'], 'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '', 'mail.mailers.smtp.url' => null]);

        report(new RuntimeException('no host'));

        $this->assertSame(0, $this->attempts);
    }

    public function test_no_mail_is_attempted_without_an_address(): void
    {
        report(new RuntimeException('no address'));

        $this->assertSame(0, $this->attempts);
    }

    private function sent(string $channel): array
    {
        return Log::channel($channel)->getLogger()->getHandlers()[0]->getRecords();
    }

    private function messageWithMarkup(): string
    {
        return 'bad input <script>alert(1)</script> from jane@example.com';
    }

    private function messageWithAnEmail(): string
    {
        return 'could not charge jane@example.com';
    }
}
