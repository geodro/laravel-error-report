<?php

namespace Geodro\ErrorReport\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Geodro\ErrorReport\Tests\Fixtures\Checkout;
use Geodro\ErrorReport\Tests\Fixtures\Order;
use Throwable;

class ArgumentsTest extends TestCase
{
    public function test_argument_values_are_recorded_and_user_fields_anonymised(): void
    {
        $this->reportFrom(fn () => (new Checkout)->charge($this->order(), 4242, 'SPRING', null,
            ['qty' => 3, 'password' => 'hunter2', 'note' => 'ring jane@example.com']));

        $out = $this->latestReport();

        $this->assertStringContainsString('Checkout->charge()', $out);
        $this->assertStringContainsString("\$coupon = 'SPRING'", $out);
        $this->assertStringContainsString('$giftNote = null', $out);
        $this->assertStringContainsString('Order #48213', $out);
        $this->assertStringContainsString("status: 'pending'", $out);
        $this->assertStringContainsString('total: 1999', $out);
        $this->assertStringContainsString('qty: 3', $out);
        $this->assertStringContainsString('$userId = [user]', $out);
        foreach (['4242', 'jane@example.com', 'Jane Doe', 'hunter2', 'staff only'] as $secret) {
            $this->assertStringNotContainsString($secret, $out, "report leaks {$secret}");
        }
    }

    public function test_the_user_model_and_the_request_are_never_expanded(): void
    {
        $user = (new User)->forceFill(['id' => 4242, 'email' => 'jane@example.com']);
        $request = Request::create('/x?coupon=SECRETCOUPON', 'POST', ['password' => 'hunter2']);

        $this->reportFrom(fn () => (new Checkout)->forUser($user, $request, 'jane@example.com'));

        $out = $this->latestReport();

        $this->assertStringContainsString('$payer = Illuminate\Foundation\Auth\User [user]', $out);
        $this->assertStringContainsString('$request = Illuminate\Http\Request [omitted]', $out);
        $this->assertStringContainsString('$customerEmail = [user]', $out);
        foreach (['4242', 'jane@example.com', 'SECRETCOUPON', 'hunter2'] as $secret) {
            $this->assertStringNotContainsString($secret, $out, "report leaks {$secret}");
        }
    }

    public function test_the_report_says_so_when_php_drops_arguments(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '1');

        try {
            $this->reportFrom(fn () => (new Checkout)->charge($this->order(), 1, 'SPRING', null, []));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }

        $out = $this->latestReport();
        $this->assertStringContainsString('zend.exception_ignore_args', $out);
        $this->assertStringNotContainsString('SPRING', $out);
    }

    private function reportFrom(callable $call): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function order(): Order
    {
        $order = new Order;
        $order->setRawAttributes(['id' => 48213, 'status' => 'pending', 'total' => 1999,
            'customer_name' => 'Jane Doe', 'email' => 'jane@example.com', 'internal_note' => 'staff only'], true);
        $order->exists = true;

        return $order;
    }
}
