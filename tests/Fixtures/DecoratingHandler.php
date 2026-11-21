<?php

namespace Geodro\ErrorReport\Tests\Fixtures;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

// Wraps the app's handler the way Collision does in every new Laravel app.
class DecoratingHandler implements ExceptionHandler
{
    public function __construct(private ExceptionHandler $inner) {}

    public function reportable(callable $callback): mixed
    {
        return $this->inner->reportable($callback);
    }

    public function report(Throwable $e): void
    {
        $this->inner->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }
}
