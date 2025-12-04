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
    
    public int $progress = 0;
    public bool $showProgress = false;

    public function login()
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Réinitialiser et afficher la barre de progression
        $this->progress = 0;
        $this->showProgress = true;

        // Simuler la progression
        $this->progress = 10;

        $user = \App\Models\User::where('email', $this->email)->first();
        $this->progress = 30;

        if (!$user) {
            $this->progress = 100;
            sleep(0.5); // Pause pour voir 100%
            $this->showProgress = false;
            $this->error('Ces identifiants ne correspondent pas à nos enregistrements.');
            return;
        }

        // Check if password needs rehashing
        $this->progress = 50;
        
        if (!str_starts_with($user->password, '$2y$')) {
            // Password is not using bcrypt, update it
            $user->password = Hash::make($this->password);
            $user->save();
        }

        $this->progress = 70;

        if (Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            $this->progress = 100;
            sleep(0.5); // Pause pour voir 100%
            session()->regenerate();
            return redirect()->intended('/boutique');
        }

        $this->progress = 100;
        sleep(0.5); // Pause pour voir 100%
        $this->showProgress = false;
        $this->error('Ces identifiants ne correspondent pas à nos enregistrements.');
    }

}; ?>

<div>
    <div class="flex h-screen w-screen">
        <div class="flex-1 flex justify-center items-center bg-white">
            <div class="w-96 max-w-full space-y-6 px-6">
                <!-- Logo -->
                <div class="flex justify-center opacity-50">
                    <a href="/" class="group flex items-center gap-3">
                        <span class="text-xl font-bold text-zinc-800">PRIX</span> <span class="text-xl font-bold text-amber-800">COSMA</span>
                    </a>
                </div>

                <h2 class="text-center text-2xl font-bold text-gray-900">Bienvenue à nouveau</h2>

                <!-- Barre de progression -->
                @if($showProgress)
                <div class="mb-6 transition-all duration-500 ease-out" 
                     x-data="{ progress: @entangle('progress') }"
                     x-init="$watch('progress', value => {
                         const progressBar = $refs.progressBar;
                         const progressText = $refs.progressText;
                         
                         // Animation fluide de la barre
                         progressBar.style.width = value + '%';
                         progressBar.style.transition = 'width 0.3s ease-out';
                         
                         // Animation du texte
                         progressText.textContent = value + '%';
                         progressText.style.opacity = '1';
                         
                         // Effet de pulsation à 100%
                         if (value === 100) {
                             progressBar.classList.add('animate-pulse');
                             setTimeout(() => {
                                 progressBar.classList.remove('animate-pulse');
                             }, 1000);
                         }
                     })">
                    
                    <!-- Conteneur de la barre -->
                    <div class="relative pt-1">
                        <!-- Texte de progression -->
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-xs font-semibold inline-block text-blue-600">
                                Connexion en cours...
                            </span>
                            <span x-ref="progressText" class="text-xs font-semibold inline-block text-blue-600 transition-opacity duration-300">
                                {{ $progress }}%
                            </span>
                        </div>
                        
                        <!-- Barre de progression principale -->
                        <div class="overflow-hidden h-3 mb-4 text-xs flex rounded-full bg-gray-200 shadow-inner">
                            <!-- Barre de remplissage -->
                            <div x-ref="progressBar" 
                                 :style="{ width: '{{ $progress }}%' }"
                                 class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-gradient-to-r from-blue-500 to-purple-600 transition-all duration-300 ease-out rounded-full relative">
                                
                                <!-- Effet de brillance animé -->
                                <div class="absolute top-0 left-0 w-1/3 h-full bg-gradient-to-r from-white/30 to-transparent animate-shine"></div>
                            </div>
                        </div>
                        
                        <!-- Indicateurs de progression -->
                        <div class="flex justify-between text-xs text-gray-500">
                            <span>Authentification</span>
                            <span>Validation</span>
                            <span>Redirection</span>
                        </div>
                    </div>
                </div>
                @endif

                <x-form wire:submit="login">
                    <x-input label="Email" wire:model.live="email" placeholder="" icon="o-user" hint="Votre adresse email" />

                    <x-password label="Mot de passe" wire:model.lazy="password" placeholder="" clearable hint="Votre mot de passe" />

                    <x-slot:actions>
                        <x-button label="Connexion" 
                                  class="w-full bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 text-white font-medium py-3 px-4 rounded-lg text-sm transition-all duration-300 shadow-md hover:shadow-lg"
                                  type="submit" 
                                  spinner="login"
                                  :disabled="$showProgress" />
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

    <style>
        /* Animation pour l'effet de brillance */
        @keyframes shine {
            0% {
                transform: translateX(-100%) skewX(-15deg);
            }
            100% {
                transform: translateX(300%) skewX(-15deg);
            }
        }
        
        .animate-shine {
            animation: shine 2s infinite;
        }
        
        /* Style pour le bouton désactivé */
        button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
    </style>
</div>