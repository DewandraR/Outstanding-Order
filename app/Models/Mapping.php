<?php
// app/Models/Mapping.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mapping extends Model
{
    protected $table = 'maping';          // sesuai nama tabel di DB kamu
    protected $primaryKey = 'Id';         // kolom PK
    public $timestamps = false;           // tabel kamu tidak ada created_at/updated_at

    protected $fillable = [
        'IV_WERKS','IV_AUART','Deskription'
    ];
}
