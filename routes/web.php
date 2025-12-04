<?php

use App\Livewire\Boutiques\Boutique;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Route publique (pas de cache pour login)
Volt::route('/', 'auth.login')->name('login');

// Groupe de routes protégées avec cache personnalisé
Route::middleware(['check.auth'])->group(function () {
    
    // Cache serveur pour 60 minutes + cache navigateur
    Volt::route('/home', 'plateformes.page')
        ->name('home')
        ->middleware(['cache.response:60', 'cache.headers:public;max_age=3600;etag']);
    
    // Cache serveur pour 30 minutes
    Volt::route('/articles', 'plateformes.article')
        ->name('articles')
        ->middleware(['cache.response:30', 'cache.headers:public;max_age=1800']);
    
    // Route avec paramètres - cache serveur 10 minutes
    Volt::route('/article/{name}/{id}/{price}/commparate', 'plateformes.comparateur')
        ->name('article.comparate-prix')
        ->middleware(['cache.response:10', 'cache.headers:public;max_age=600']);
    
    // Cache serveur pour 60 minutes
    Volt::route('/sites', 'sites.page')
        ->name('sites')
        ->middleware(['cache.response:60', 'cache.headers:public;max_age=3600']);
    
    // Cache serveur pour 5 minutes (données fréquemment mises à jour)
    Volt::route('/scraped_products', 'scraped_products.page')
        ->name('scraped_products')
        ->middleware(['cache.response:5', 'cache.headers:public;max_age=300']);
    
    // Cache serveur pour 30 minutes
    Route::get('/boutique', Boutique::class)
        ->name('boutiques')
        ->middleware(['cache.response:30', 'cache.headers:public;max_age=1800']);
});