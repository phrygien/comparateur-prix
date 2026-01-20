<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comparaison extends Model
{
    protected $table = "list_product";

    protected $fillable = [
        'libelle',
        'status'
    ];
}
