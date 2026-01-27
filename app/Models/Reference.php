<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reference extends Model
{
    protected $table = 'scrap_reference'; // ou le nom de votre table
    
    public function products()
    {
        return $this->hasMany(Product::class, 'scrap_reference_id');
    }
}