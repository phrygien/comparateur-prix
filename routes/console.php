<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ============================================================
// Export ventes par pays — tous les lundis à 7h00
// ============================================================
Schedule::command('sales:export-by-country')
    ->weeklyOn(1, '07:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Log::error('sales:export-by-country a échoué.');
    })
    ->appendOutputTo(storage_path('logs/sales-export.log'));


// ============================================================
// Autres exemples selon votre besoin — décommentez un seul
// ============================================================

// Tous les jours à 7h
// Schedule::command('sales:export-by-country')->dailyAt('07:00');

// Le 1er de chaque mois (période = mois précédent)
// Schedule::command(
//     'sales:export-by-country'
//     . ' --date-from=' . now()->subMonth()->startOfMonth()->format('Y-m-d')
//     . ' --date-to='   . now()->subMonth()->endOfMonth()->format('Y-m-d')
// )->monthlyOn(1, '07:00');

// Seulement FR et BE, trié par CA
// Schedule::command('sales:export-by-country --countries=FR,BE --sort=rank_ca')
//     ->weeklyOn(1, '07:00');