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

    public function login()
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = \App\Models\User::where('email', $this->email)->first();

        if (!$user) {
            $this->error('Ces identifiants ne correspondent pas à nos enregistrements.');
            return;
        }

        // Check if password needs rehashing
        if (!str_starts_with($user->password, '$2y$')) {
            $user->password = Hash::make($this->password);
            $user->save();
        }

        if (Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            session()->regenerate();
            return $this->redirect('/boutique', navigate: true);
        }

        $this->error('Ces identifiants ne correspondent pas à nos enregistrements.');
    }

}; ?>

<div class="flex h-screen w-screen">
    <div class="flex-1 flex justify-center items-center bg-gradient-to-br from-white via-gray-50/50 to-blue-50/30">
        <div class="w-96 max-w-full space-y-8 px-6">
            <!-- Logo amélioré - Version élégante -->
            <div class="flex justify-center">
                <a href="/" class="group relative">
                    <!-- Effet de fond lumineux -->
                    <div class="absolute -inset-3 bg-gradient-to-r from-blue-100/40 via-amber-100/30 to-blue-100/40 rounded-2xl blur-lg opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
                    
                    <!-- Conteneur principal du logo -->
                    <div class="relative flex items-center gap-0 bg-white/80 backdrop-blur-sm rounded-xl p-3 shadow-lg border border-gray-100 group-hover:shadow-xl transition-all duration-300">
                        <!-- Icône décorative gauche -->
                        <div class="px-2">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center shadow-sm">
                                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5 2a1 1 0 011 1v1h1a1 1 0 010 2H6v1a1 1 0 01-2 0V6H3a1 1 0 010-2h1V3a1 1 0 011-1zm0 10a1 1 0 011 1v1h1a1 1 0 110 2H6v1a1 1 0 11-2 0v-1H3a1 1 0 110-2h1v-1a1 1 0 011-1zM12 2a1 1 0 01.967.744L14.146 7.2 17.5 9.134a1 1 0 010 1.732l-3.354 1.935-1.18 4.455a1 1 0 01-1.933 0L9.854 12.2 6.5 10.266a1 1 0 010-1.732l3.354-1.935 1.18-4.455A1 1 0 0112 2z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                        </div>
                        
                        <!-- Séparateur -->
                        <div class="w-px h-10 bg-gradient-to-b from-transparent via-gray-200 to-transparent mx-2"></div>
                        
                        <!-- Texte du logo -->
                        <div class="px-2">
                            <div class="flex items-baseline gap-1">
                                <span class="text-3xl font-black bg-gradient-to-r from-gray-800 via-gray-900 to-black bg-clip-text text-transparent tracking-tight leading-none">
                                    PRIX
                                </span>
                                <span class="text-3xl font-black bg-gradient-to-r from-amber-600 via-amber-700 to-amber-800 bg-clip-text text-transparent tracking-tight leading-none">
                                    COSMA
                                </span>
                            </div>
                            <div class="h-0.5 w-full bg-gradient-to-r from-blue-400/50 via-amber-400/50 to-blue-400/50 rounded-full mt-1"></div>
                        </div>
                        
                        <!-- Icône décorative droite -->
                        {{-- <div class="px-2">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-amber-500 to-amber-600 flex items-center justify-center shadow-sm">
                                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                        </div> --}}
                    </div>
                    
                    <!-- Badge premium subtil -->
                    {{-- <div class="absolute -top-2 -right-2">
                        <div class="px-1.5 py-0.5 bg-gradient-to-r from-blue-500 to-blue-600 rounded-full text-[9px] font-bold text-white shadow-md">
                            PRO
                        </div>
                    </div> --}}
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