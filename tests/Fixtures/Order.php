<?php

namespace Geodro\ErrorReport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $hidden = ['internal_note'];
}
