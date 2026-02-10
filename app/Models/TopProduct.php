<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TopProduct extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'top_product_cosma';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'histo_import_top_file_id',
        'rank_qty',
        'rank_chriffre_affaire',
        'ean',
        'marque',
        'groupe',
        'designation',
        'freezed_stock',
        'export',
        'supprime',
        'nouveaute',
        'pght',
        'pamp',
        'prix_vente_cosma',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'rank_qty' => 'integer',
        'rank_chriffre_affaire' => 'integer',
        'freezed_stock' => 'integer',
        'pght' => 'decimal:2',
        'pamp' => 'decimal:2',
        'prix_vente_cosma' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the import history file associated with this product.
     */
    public function histoImportTopFile()
    {
        return $this->belongsTo(HistoImportTopFile::class, 'histo_import_top_file_id');
    }
}