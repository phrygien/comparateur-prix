<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    // scraped product table
    protected $table = 'scraped_product';

    public function website(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'web_site_id');
    }

    public function scraped_reference(): BelongsTo
    {
        return $this->belongsTo(Reference::class, 'scrap_reference_id');
    }

    // Dans le modèle Product
    public function scopeFullTextSearch($query, string $searchQuery)
    {
        return $query->whereRaw('MATCH(name, vendor, type, variation) 
                                AGAINST(? IN BOOLEAN MODE)', [$searchQuery]);
    }

}
