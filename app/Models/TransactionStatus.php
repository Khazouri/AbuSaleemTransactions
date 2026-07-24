<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionStatus extends Model
{
    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'color',
    ];
}
