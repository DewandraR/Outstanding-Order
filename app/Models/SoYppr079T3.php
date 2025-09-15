<?php
// app/Models/SoYppr079T3.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoYppr079T3 extends Model
{
    protected $table = 'so_yppr079_t3';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'NETPR' => 'decimal:2',
        'NETWR' => 'decimal:2',
        'TOTPR' => 'decimal:2',
        'TOTPR2'=> 'decimal:2',
        'EDATU' => 'date',
        'KWMENG'=> 'decimal:3',
        'BMENG' => 'decimal:3',
        'KALAB' => 'decimal:3',
        'KALAB2'=> 'decimal:3',
        'QTY_DELIVERY' => 'decimal:3',
        'QTY_GI'       => 'decimal:3',
        'QTY_BALANCE'  => 'decimal:3',
        'QTY_BALANCE2' => 'decimal:3',
        'MENGX1' => 'decimal:3',
        'MENGX2' => 'decimal:3',
        'MENGE'  => 'decimal:3',
        'ASSYM'  => 'decimal:3',
        'PAINT'  => 'decimal:3',
        'PACKG'  => 'decimal:3',
        'QTYS'   => 'decimal:3',
        'MACHI'  => 'decimal:3',
        'EBDIN'  => 'decimal:3',
        'MACHP'  => 'decimal:3',
        'EBDIP'  => 'decimal:3',
        'DAYX'   => 'integer',
        'fetched_at' => 'datetime',
    ];
}
