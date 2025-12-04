<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cache;

// Route publique
Volt::route('/', 'auth.login')->name('login');

// Groupe de routes protégées
Route::middleware(['check.auth', 'cache.headers:public;max_age=3600'])->group(function () {
    Volt::route('/home', 'plateformes.page')->name('home');
    
    // Route article avec cache spécifique
    Route::get('/article/{name}/{id}/{price}/comparate', function ($name, $id, $price) {
        $cacheKey = "article_comparate_{$id}_{$price}";
        
        return Cache::remember($cacheKey, now()->addHours(2), function () use ($name, $id, $price) {
            return view('livewire.plateformes.comparateur', [
                'name' => $name,
                'id' => $id,
                'price' => $price
            ]);
        });
    })->name('article.comparate-prix');
    
    // Autres routes avec cache middleware
    Volt::route('/articles', 'plateformes.article')
        ->name('articles')
        ->middleware('cache.headers:public;max_age=1800');
        
    Volt::route('/sites', 'sites.page')
        ->name('sites')
        ->middleware('cache.headers:public;max_age=1800');
});