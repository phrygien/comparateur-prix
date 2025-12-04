<?php

use App\Livewire\Boutiques\Boutique;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Route publique
Volt::route('/', 'auth.login')->name('login');

// Groupe de routes protégées avec cache navigateur
Route::middleware(['check.auth'])->group(function () {
    
    // Cache navigateur : 1 heure
    Volt::route('/home', 'plateformes.page')
        ->name('home')
        ->middleware('cache.headers:public;max_age=3600');
    
    // Cache navigateur : 30 minutes
    Volt::route('/articles', 'plateformes.article')
        ->name('articles')
        ->middleware('cache.headers:public;max_age=1800');
    
    // Cache navigateur : 10 minutes (données dynamiques)
    Volt::route('/article/{name}/{id}/{price}/commparate', 'plateformes.comparateur')
        ->name('article.comparate-prix')
        ->middleware('cache.headers:public;max_age=600');
    
    // Cache navigateur : 1 heure
    Volt::route('/sites', 'sites.page')
        ->name('sites')
        ->middleware('cache.headers:public;max_age=3600');
    
    // Cache navigateur : 5 minutes (mis à jour fréquemment)
    Volt::route('/scraped_products', 'scraped_products.page')
        ->name('scraped_products')
        ->middleware('cache.headers:public;max_age=300');
    
    // Cache navigateur : 30 minutes
    Route::get('/boutique', Boutique::class)
        ->name('boutiques')
        ->middleware('cache.headers:public;max_age=1800');
});