<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoImportTopFile extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'histo_import_top_file';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nom_fichier',
        'chemin_fichier',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the top products associated with this import file.
     */
    public function topProducts()
    {
        return $this->hasMany(TopProduct::class, 'histo_import_top_file_id');
    }
}