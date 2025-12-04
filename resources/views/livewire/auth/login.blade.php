<?php

use Mary\Traits\Toast;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\{Layout, Title};

new
    #[Layout('components.layouts.guest')]
    #[Title('Login')]
    class extends Component {

    use Toast;

    #[Validate('required')] 
    public string $email = '';

    #[Validate('required')] 
    public string $password = '';

    public int $loadingProgress = 0;
    public bool $isLoading = false;

    public function login()
    {
        $this->isLoading = true;
        $this->loadingProgress = 0;

        // Étape 1: Validation
        sleep(0.3);
        $this->loadingProgress = 25;

        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Étape 2: Recherche utilisateur
        sleep(0.3);
        $this->loadingProgress = 50;

        $user = \App\Models\User::where('email', $this->email)->first();

        if (!$user) {
            $this->isLoading = false;
            $this->loadingProgress = 0;
            $this->error('Ces identifiants ne correspondent pas à nos enregistrements.');
            return;
        }

        // Étape 3: Vérification mot de passe
        sleep(0.3);
        $this->loadingProgress = 75;

        // Check if password needs rehashing
        if (!str_starts_with($user->password, '$2y$')) {
            $user->password = Hash::make($this->password);
            $user->save();
        }

        if (Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            // Étape 4: Connexion réussie
            $this->loadingProgress = 100;
            sleep(0.2);
            
            session()->regenerate();
            $this->isLoading = false;
            return $this->redirect('/boutique', navigate: true);
        }

        $this->isLoading = false;
        $this->loadingProgress = 0;
        $this->error('Ces identifiants ne correspondent pas à nos enregistrements.');
    }

}; ?>

<div class="flex h-screen w-screen relative">
    <!-- Loading Overlay avec cercle de progression -->
    <div x-data="{ isLoading: @entangle('isLoading'), progress: @entangle('loadingProgress') }"
         x-show="isLoading"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center"
         style="display: none;">
        
        <div class="bg-white rounded-2xl p-8 shadow-2xl flex flex-col items-center space-y-6">
            <!-- Cercle de progression -->
            <div class="relative w-32 h-32">
                <!-- Cercle de fond -->
                <svg class="transform -rotate-90 w-32 h-32">
                    <circle cx="64" cy="64" r="56" 
                            stroke="currentColor" 
                            stroke-width="8" 
                            fill="none" 
                            class="text-gray-200" />
                    <!-- Cercle de progression -->
                    <circle cx="64" cy="64" r="56" 
                            stroke="currentColor" 
                            stroke-width="8" 
                            fill="none" 
                            class="text-blue-600 transition-all duration-300 ease-out"
                            :style="'stroke-dasharray: ' + (2 * Math.PI * 56) + '; stroke-dashoffset: ' + (2 * Math.PI * 56 * (1 - progress / 100))" 
                            stroke-linecap="round" />
                </svg>
                
                <!-- Pourcentage au centre -->
                <div class="absolute inset-0 flex items-center justify-center">
                    <span class="text-3xl font-bold text-blue-600" x-text="progress + '%'"></span>
                </div>
            </div>
            
            <!-- Texte de chargement -->
            <div class="text-center">
                <h3 class="text-xl font-semibold text-gray-900 mb-1">Connexion en cours</h3>
                <p class="text-sm text-gray-500">Veuillez patienter...</p>
            </div>
        </div>
    </div>

    <div class="flex-1 flex justify-center items-center bg-white">
        <div class="w-96 max-w-full space-y-6 px-6">
            <!-- Logo -->
            <div class="flex justify-center opacity-50">
                <a href="/" class="group flex items-center gap-3">
                    <span class="text-xl font-bold text-zinc-800">PRIX</span> <span class="text-xl font-bold text-amber-800">COSMA</span>
                </a>
            </div>

            <h2 class="text-center text-2xl font-bold text-gray-900">Bienvenue à nouveau</h2>

            <x-form wire:submit="login">
                <x-input label="Email" wire:model.live="email" placeholder="" icon="o-user" hint="Votre adresse email" />

                <x-password label="Mot de passe" wire:model.lazy="password" placeholder="" clearable hint="Votre mot de passe" />

                <x-slot:actions>
                    <x-button 
                        label="Connexion" 
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md text-sm disabled:opacity-50 disabled:cursor-not-allowed" 
                        type="submit" 
                        spinner="login"
                        wire:loading.attr="disabled" />
                </x-slot:actions>
            </x-form>

        </div>
    </div>

    <div class="flex-1 hidden lg:flex">
        <div class="relative h-full w-full bg-zinc-900 text-white flex flex-col justify-end items-start p-16"
             style="background-image: url('https://images.pexels.com/photos/1888026/pexels-photo-1888026.jpeg'); background-size: cover; background-position: center;">

            <blockquote class="mb-6 italic font-light text-2xl xl:text-3xl">
                "Toujours le meilleur prix, par rapport aux autres"
            </blockquote>

            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-full bg-white flex items-center justify-center">
                    <svg class="w-8 h-8 text-zinc-800" fill="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 11l-3 3-1.5-1.5L3 16l3 3 5-5-2-2zm0-6l-3 3-1.5-1.5L3 10l3 3 5-5-2-2zm5 2h7v2h-7V7zm0 6h7v2h-7v-2zm0 6h7v2h-7v-2z"/>
                    </svg>
                </div>

                <div>
                    <div class="text-lg font-medium">PrixCosma</div>
                    <div class="text-sm text-zinc-300">Astucom - Communication - LTD</div>
                </div>
            </div>
        </div>
    </div>
</div>