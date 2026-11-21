<?php

namespace Geodro\ErrorReport\Tests\Fixtures;

use Illuminate\Http\Request;
use RuntimeException;

class Checkout
{
    public function charge(Order $order, int $userId, ?string $coupon, ?string $giftNote, array $options): void
    {
        throw new RuntimeException('charge failed');
    }

    public function forUser(object $payer, Request $request, string $customerEmail): void
    {
        throw new RuntimeException('lookup failed');
    }
}
