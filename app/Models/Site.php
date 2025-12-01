<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    protected $table = 'web_site';
    
    protected $fillable = [
        'name',
        'url',
        'created_at',
        'updated_at'
    ];
    
    public $timestamps = false;
    
    /**
     * Relation avec les produits scrapés
     */
    public function scrapedProducts()
    {
        return $this->hasMany(Product::class, 'web_site_id');
    }
}
