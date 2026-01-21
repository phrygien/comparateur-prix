<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetailProduct extends Model
{
    protected $table = "details_list_product";


    protected $fillable = [
        'list_product_id',
        'EAN',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Comparaison::class, 'list_product_id');
    }
}
