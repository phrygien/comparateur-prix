<?php

use App\Livewire\Boutiques\Boutique;
use Livewire\Volt\Volt;

//Volt::route('/', 'users.index');

Volt::route('/auth', 'auth.login')->name('login');

Volt::route('/', 'plateformes.page')->name('home');

Volt::route('/articles', 'plateformes.article')->name('articles');

Volt::route('/article/{id}/commparate', 'plateformes.comparateur')->name('article.comparate-prix');

Volt::route('/sites', 'sites.page')->name('sites');

Volt::route('/scraped_products', 'scraped_products.page')->name('scraped_products');

// Routes boutique
Route::get('/boutique', Boutique::class)->name('boutiques');
//Volt::route('/boutiques', 'boutiques.page')->name('boutiques');