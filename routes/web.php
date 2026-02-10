<?php

use App\Livewire\Boutiques\Boutique;
use App\Livewire\Boutiques\NosBoutique;
use Livewire\Volt\Volt;
use App\Models\Product;

// Route publique
Volt::route('/', 'auth.login')->name('login');

// Groupe de routes protégées
Route::middleware(['check.auth', 'boutique.cache'])->group(function () {
    Volt::route('/home', 'plateformes.page')->name('home');
    Volt::route('/articles', 'plateformes.article')->name('articles');
    Volt::route('/article/{name}/{id}/{price}/commparate', 'plateformes.comparateur')->name('article.comparate-prix');
    Volt::route('/sites', 'sites.page')->name('sites');
    Volt::route('/scraped_products', 'scraped_products.page')->name('scraped_products');
    Route::get('/boutique', action: Boutique::class)->name('boutiques');

    // Modular lst
    Volt::route('/top-product', 'boutiques.top-product')->name('boutiques.top-product');
    Volt::route('/top-product/create', 'boutiques.top-product.create')->name('comparaison.create-list');
    Volt::route('/top-product/{id}', 'boutiques.top-product.table')->name('top-product.show');
    Volt::route('/top-product/{id}/edit', 'boutiques.top-product.edit-list')->name('top-product.edit');

    // Executer comparateur
    Route::get('/nos-boutique', NosBoutique::class)->name('nos-boutiques');
    Volt::route('/executer-comparateur/{name}/{id}/{price}/execute', 'plateformes.execute-comparateur')->name('execute-comparateur');

    Volt::route('find-product/{ean}/{id}/{price}/concurent', 'plateformes.comparateur.typesense')->name('find-product-concurent');

    Volt::route('/import-file', 'rankings.import-file')->name('import-file');
    Volt::route('/ranking-result', 'rankings.resultat')->name('ranking-resultat');
});
