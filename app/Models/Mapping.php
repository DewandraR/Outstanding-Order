<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mapping extends Model
{
    protected $table = 'maping';
    protected $primaryKey = 'id';   // <-- lowercase, sesuai DB
    public $timestamps = false;
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = ['IV_WERKS', 'IV_AUART', 'Deskription'];
}
