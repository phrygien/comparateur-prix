<?php

use App\Livewire\Boutiques\Boutique;
use Livewire\Volt\Volt;

// Route publique
Volt::route('/', 'auth.login')->name('login');

// Groupe de routes protégées
Route::middleware(['check.auth'])->group(function () {
    Volt::route('/home', 'plateformes.page')->name('home');
    Volt::route('/articles', 'plateformes.article')->name('articles');
    Volt::route('/article/{name}/commparate', 'plateformes.comparateur')->name('article.comparate-prix');
    Volt::route('/sites', 'sites.page')->name('sites');
    Volt::route('/scraped_products', 'scraped_products.page')->name('scraped_products');
    Route::get('/boutique', Boutique::class)->name('boutiques');
});