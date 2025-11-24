<?php

use Mary\Traits\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use function Livewire\Volt\{state};

$logout = function () {
    // Déconnecter l'utilisateur
    Auth::logout();
    
    // Invalider la session
    Session::invalidate();
    
    // Régénérer le token CSRF
    Session::regenerateToken();
    
    // Rediriger vers la page de connexion avec un message
    $this->redirect(route('login'), navigate: true);
    
    //session()->flash('success', 'Vous avez été déconnecté avec succès.');
};

?>

<div>
    <x-button wire:click="logout" icon="o-power" class="btn-circle btn-ghost btn-xs" tooltip-left="deconnecter" no-wire-navigate />
</div>