<?php

use App\Livewire\Boutiques\Boutique;
use Livewire\Volt\Volt;
use Illuminate\Support\Facades\Route;

// Route publique
Volt::route('/', 'auth.login')->name('login');

// Groupe de routes protégées avec cache
Route::middleware(['check.auth'])->group(function () {
    
    // Route home avec cache de 30 minutes
    Volt::route('/home', 'plateformes.page')
        ->name('home')
        ->middleware('cache.response:30');
    
    // Route articles avec cache de 15 minutes
    Volt::route('/articles', 'plateformes.article')
        ->name('articles')
        ->middleware('cache.response:15');
    
    // Route comparateur (pas de cache car dynamique)
    Volt::route('/article/{name}/{id}/{price}/commparate', 'plateformes.comparateur')
        ->name('article.comparate-prix');
    
    // Route sites avec cache de 60 minutes
    Volt::route('/sites', 'sites.page')
        ->name('sites')
        ->middleware('cache.response:60');
    
    // Route scraped_products avec cache de 10 minutes
    Volt::route('/scraped_products', 'scraped_products.page')
        ->name('scraped_products')
        ->middleware('cache.response:10');
    
    // Route boutique sans cache (Livewire component)
    Route::get('/boutique', Boutique::class)
        ->name('boutiques');
});