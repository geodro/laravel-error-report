<?php

namespace Geodro\ErrorReport;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\QueryException;
use Throwable;

// Turns a reported exception into an anonymised occurrence. Records only the
// shape of what happened (route pattern, command name, signed in or not), never
// values a user sent or anything that identifies them or their session.
class Recorder
{
    private ?string $job = null;

    /** @var \WeakMap<Throwable, true> */
    private \WeakMap $seen;

    public function __construct(private Application $app)
    {
        $this->seen = new \WeakMap;
    }

    public function setJob(?string $job): void
    {
        $this->job = $job;
    }

    public function record(Throwable $e): void
    {
        // Laravel reports a failed sync job's exception twice, once in the job and once in the kernel.
        if (isset($this->seen[$e])) {
            return;
        }
        $this->seen[$e] = true;

        try {
            $entry = $this->app->make(Store::class)->save($this->occurrence($e));
        } catch (Throwable) {
            // Recording must never break the app's own error reporting.
            return;
        }

        // Powers of two: an error that keeps happening is announced again, ever less often.
        $count = $entry['count'];
        if (($count & ($count - 1)) === 0) {
            $this->send($entry);
        }
    }

    // A destination that is down (Slack outage, bad webhook, SMTP refusing) must not stop the others.
    /**
     * @param array<string, mixed> $entry
     */
    private function send(array $entry): void
    {
        $report = ReportFormatter::format($entry);
        foreach ((array) $this->config()->get('error-report.channels') as $channel) {
            try {
                $this->app->make('log')->channel($channel)->error($report);
            } catch (Throwable) {
            }
        }

        $to = array_values((array) $this->config()->get('error-report.mail'));
        if (! $to || ! $this->mailerDelivers()) {
            return;
        }
        $error = $entry['last']['error'];
        $subject = '['.$this->config()->get('app.name').'] '.class_basename($error['class']).': '
            .mb_strimwidth(ReportFormatter::headline($error['message']), 0, 80, '…')." (seen {$entry['count']}x)";
        try {
            $this->mail($report, HtmlReport::render($entry, (string) $this->config()->get('app.name')), $to, $subject);
        } catch (Throwable) {
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function occurrence(Throwable $e): array
    {
        $error = $this->describe($e);
        $previous = [];
        for ($p = $e->getPrevious(); $p && count($previous) < 5; $p = $p->getPrevious()) {
            $previous[] = $this->describe($p);
        }
        $top = $error['frames'][0] ?? $error['at'];

        return [
            'id' => substr(sha1($error['class'].'|'.$error['at'].'|'.$top.'|'.self::shape($error['message'])), 0, 10),
            'error' => $error,
            'previous' => $previous,
            'snippet' => $this->snippet($error['at_file'], $error['at_line'], $error['frames_raw'][0] ?? null),
            'context' => $this->context(),
            'release' => $this->release(),
            'php' => PHP_VERSION,
            'laravel' => $this->app->version(),
            'env' => $this->app->environment(),
        ];
    }

    // Sends through the app's own mailer (its sender, events, everything), with the smtp
    // timeout shortened for this one send: a hung server must not hold the request for 60s.
    /**
     * @param list<string> $to
     */
    private function mail(string $report, string $html, array $to, string $subject): void
    {
        $mailer = $this->app->make('mailer');
        $stream = $mailer->getSymfonyTransport() instanceof \Symfony\Component\Mailer\Transport\Smtp\SmtpTransport
            ? $mailer->getSymfonyTransport()->getStream() : null;
        $previous = $stream instanceof \Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream ? $stream->getTimeout() : null;
        if ($previous !== null) {
            $stream->setTimeout(min($previous, (float) $this->config()->get('error-report.mail_timeout')));
        }

        try {
            $mailer->raw($report, fn ($m) => $m->to($to)->subject($subject)->getSymfonyMessage()->html($html));
        } finally {
            if ($previous !== null) {
                $stream->setTimeout($previous);
            }
        }
    }

    // Laravel's default `log` mailer, and `array`, only pretend to send; an smtp mailer needs a host.
    private function mailerDelivers(): bool
    {
        $config = $this->config()->get('mail.mailers.'.$this->config()->get('mail.default'));

        return match ($config['transport'] ?? null) {
            null, 'log', 'array' => false,
            'smtp' => filled($config['host'] ?? null) || filled($config['url'] ?? null),
            default => true,
        };
    }

    private function config(): \Illuminate\Contracts\Config\Repository
    {
        return $this->app->make('config');
    }

    // The message without what varies per occurrence (numbers, quoted values), so different
    // problems from one throw site stay apart while one problem with different ids stays one.
    private static function shape(string $message): string
    {
        return (string) preg_replace(['/\d+/', "/'[^']*'|\"[^\"]*\"/"], ['#', '?'], $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Throwable $e): array
    {
        $message = match (true) {
            $e instanceof QueryException => Anonymiser::driverMessage($e->getPrevious()?->getMessage() ?? '')."\nSQL: ".Anonymiser::sql($e->getSql()),
            $e instanceof \PDOException => Anonymiser::driverMessage($e->getMessage()),
            default => Anonymiser::text($e->getMessage()),
        };

        $raw = [];
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && ! str_contains($frame['file'], '/vendor/') && count($raw) < 20) {
                $raw[] = $frame;
            }
        }

        return [
            'class' => get_class($e),
            'message' => mb_strimwidth($message, 0, 2000, '…'),
            'at' => $this->relative($e->getFile()).':'.$e->getLine(),
            'at_file' => $e->getFile(),
            'at_line' => $e->getLine(),
            'frames' => array_map(fn ($f) => $this->relative($f['file']).':'.($f['line'] ?? 0).' '
                .(isset($f['class']) ? $f['class'].($f['type'] ?? '->') : '').$f['function'].'()', $raw),
            'frames_raw' => array_map(fn ($f) => ['file' => $f['file'], 'line' => $f['line'] ?? 0], $raw),
            'args' => array_map(fn ($f) => isset($f['args']) ? Arguments::forFrame($f) : null, $raw),
            'args_dropped' => (bool) ini_get('zend.exception_ignore_args'),
        ];
    }

    // Source around the throw site, or around the first app frame when it was thrown inside vendor.
    /**
     * @param array<string, mixed>|null $appFrame
     * @return array<string, mixed>
     */
    private function snippet(string $file, int $line, ?array $appFrame): array
    {
        if (str_contains($file, '/vendor/') && $appFrame) {
            [$file, $line] = [$appFrame['file'], $appFrame['line']];
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (! $lines) {
            return [];
        }
        $start = max(1, $line - 4);
        $out = ['file' => $this->relative($file), 'line' => $line, 'lines' => []];
        foreach (array_slice($lines, $start - 1, 9, true) as $i => $code) {
            $out['lines'][$i + 1] = Anonymiser::text(mb_strimwidth($code, 0, 200, '…'));
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        if ($this->job) {
            return ['type' => 'job', 'job' => $this->job];
        }
        // A matched route wins over runningInConsole(), which is also true under the test kernel.
        $request = $this->app->make('request');
        $route = $request->route();
        if (! $route && $this->app->runningInConsole()) {
            return ['type' => 'cli', 'command' => $_SERVER['argv'][1] ?? null];
        }

        return [
            'type' => 'http',
            'method' => $request->method(),
            'route' => $route ? '/'.ltrim($route->uri(), '/') : null,
            'route_name' => $route?->getName(),
            'action' => $route?->getActionName(),
            'auth' => $this->signedIn() ? 'signed in' : 'guest',
        ];
    }

    // hasUser() reads the already-resolved user, so no database lookup runs while the app is failing.
    private function signedIn(): bool
    {
        try {
            $guard = $this->app->make('auth')->guard();

            return $guard->hasUser();
        } catch (Throwable) {
            return false;
        }
    }

    private function release(): ?string
    {
        if ($release = $this->config()->get('error-report.release')) {
            return (string) $release;
        }
        $head = trim((string) @file_get_contents($this->app->basePath('.git/HEAD')));
        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $head = trim((string) @file_get_contents($this->app->basePath('.git/'.$ref)));
        }

        return preg_match('/^[0-9a-f]{40}$/', $head) ? substr($head, 0, 12) : null;
    }

    private function relative(string $file): string
    {
        $base = rtrim($this->app->basePath(), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
