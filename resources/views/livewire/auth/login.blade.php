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

<div class="flex min-h-screen bg-gradient-to-br from-white via-gray-50 to-blue-50/30">
    <!-- Partie gauche - Formulaire -->
    <div class="w-full lg:w-1/2 flex justify-center items-center p-4 md:p-8">
        <div class="w-full max-w-md space-y-8 p-8 md:p-10 bg-white/90 backdrop-blur-sm rounded-3xl shadow-2xl border border-gray-100">
            <!-- Logo élégant -->
            <div class="flex justify-center mb-2">
                <a href="/" class="group relative flex items-center justify-center">
                    <!-- Effet de halo -->
                    <div class="absolute -inset-4 bg-gradient-to-r from-blue-500/20 via-amber-500/20 to-blue-500/20 rounded-2xl blur-xl group-hover:blur-2xl transition-all duration-500 opacity-70 group-hover:opacity-100"></div>
                    
                    <!-- Conteneur du logo -->
                    <div class="relative flex items-center gap-0 px-6 py-4 bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 group-hover:shadow-xl transition-all duration-300">
                        <!-- Icône décorative -->
                        <div class="absolute -left-3 -top-3 w-8 h-8 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center shadow-md">
                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        
                        <!-- Texte du logo avec effets -->
                        <div class="relative overflow-hidden">
                            <div class="flex items-center gap-2">
                                <span class="text-3xl font-black bg-gradient-to-r from-gray-800 via-gray-900 to-black bg-clip-text text-transparent tracking-tight">
                                    PRIX
                                </span>
                                <div class="w-px h-8 bg-gradient-to-b from-transparent via-gray-300 to-transparent"></div>
                                <span class="text-3xl font-black bg-gradient-to-r from-amber-600 via-amber-700 to-amber-800 bg-clip-text text-transparent tracking-tight">
                                    COSMA
                                </span>
                            </div>
                            <div class="absolute -bottom-1 left-0 w-full h-px bg-gradient-to-r from-transparent via-blue-500/50 to-transparent"></div>
                        </div>
                    </div>
                    
                    <!-- Badge premium -->
                    <div class="absolute -right-2 -top-2">
                        <div class="px-2 py-1 bg-gradient-to-r from-amber-500 to-amber-600 rounded-full text-[10px] font-bold text-white shadow-md">
                            PRO
                        </div>
                    </div>
                </a>
            </div>

            <!-- Titre -->
            <div class="text-center mb-2">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">Bienvenue à nouveau</h1>
                <p class="text-gray-500 font-light">Connectez-vous pour accéder à votre espace</p>
            </div>

            <!-- Formulaire -->
            <x-form wire:submit="login" class="space-y-6">
                <div class="space-y-5">
                    <div class="relative">
                        <x-input 
                            label="Adresse email" 
                            wire:model.live="email" 
                            placeholder="exemple@prixcosma.com" 
                            icon="o-envelope" 
                            hint=""
                            class="rounded-xl border-gray-200 bg-gray-50/50 hover:bg-white transition-all duration-300 focus-within:border-blue-400 focus-within:bg-white focus-within:ring-2 focus-within:ring-blue-100"
                            input-class="py-3 pl-12 text-gray-800 placeholder-gray-400"
                        />
                        <div class="absolute left-4 top-10 text-gray-400">
                            <i class="fas fa-envelope"></i>
                        </div>
                    </div>
                    
                    <div class="relative">
                        <x-password 
                            label="Mot de passe" 
                            wire:model.lazy="password" 
                            placeholder="Votre mot de passe" 
                            clearable 
                            hint=""
                            class="rounded-xl border-gray-200 bg-gray-50/50 hover:bg-white transition-all duration-300 focus-within:border-blue-400 focus-within:bg-white focus-within:ring-2 focus-within:ring-blue-100"
                            input-class="py-3 pl-12 text-gray-800 placeholder-gray-400"
                        />
                        <div class="absolute left-4 top-10 text-gray-400">
                            <i class="fas fa-lock"></i>
                        </div>
                        <div class="mt-3 flex justify-between items-center">
                            <label class="flex items-center space-x-2 cursor-pointer">
                                <input type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-sm text-gray-600">Se souvenir de moi</span>
                            </label>
                            <a href="#" class="text-sm font-medium text-blue-600 hover:text-blue-800 transition-colors">
                                Mot de passe oublié ?
                            </a>
                        </div>
                    </div>
                </div>

                <x-slot:actions>
                    <x-button 
                        label="Se connecter" 
                        class="w-full group relative overflow-hidden bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-bold py-3.5 px-4 rounded-xl text-base transition-all duration-300 shadow-lg hover:shadow-xl hover:-translate-y-0.5" 
                        type="submit" 
                        spinner="login"
                        wire:loading.attr="disabled">
                        <span class="relative z-10 flex items-center justify-center gap-2">
                            <i class="fas fa-sign-in-alt"></i>
                            Connexion
                        </span>
                        <div class="absolute inset-0 bg-gradient-to-r from-blue-700 to-blue-800 translate-y-full group-hover:translate-y-0 transition-transform duration-300"></div>
                    </x-button>
                </x-slot:actions>
            </x-form>

            <!-- Lien d'inscription -->
            <div class="pt-6 border-t border-gray-100 text-center">
                <p class="text-gray-600">
                    Nouveau chez PrixCosma ? 
                    <a href="#" class="font-semibold text-blue-600 hover:text-blue-800 ml-1 inline-flex items-center gap-1 group">
                        Créer un compte
                        <i class="fas fa-arrow-right text-sm transform group-hover:translate-x-1 transition-transform"></i>
                    </a>
                </p>
            </div>
        </div>
    </div>

    <!-- Partie droite - Présentation élégante -->
    <div class="hidden lg:flex lg:w-1/2 relative overflow-hidden">
        <!-- Image de fond avec overlay gradient -->
        <div class="absolute inset-0 z-0">
            <!-- Multiples images en overlay pour un effet riche -->
            <div class="absolute inset-0" 
                 style="background-image: linear-gradient(rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.95)), 
                        url('https://images.pexels.com/photos/1888026/pexels-photo-1888026.jpeg');
                        background-size: cover;
                        background-position: center;
                        filter: saturate(1.1) contrast(1.1);">
            </div>
            
            <!-- Pattern subtil -->
            <div class="absolute inset-0 opacity-5"
                 style="background-image: url('data:image/svg+xml,%3Csvg width="60" height="60" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"%3E%3Cg fill="none" fill-rule="evenodd"%3E%3Cg fill="%23ffffff" fill-opacity="1"%3E%3Cpath d="M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z"/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');">
            </div>
        </div>
        
        <!-- Effets de lumière -->
        <div class="absolute inset-0 z-10">
            <!-- Spotlights -->
            <div class="absolute top-1/4 left-1/4 w-64 h-64 bg-blue-500/10 rounded-full blur-3xl"></div>
            <div class="absolute bottom-1/3 right-1/4 w-96 h-96 bg-amber-500/5 rounded-full blur-3xl"></div>
            
            <!-- Particules flottantes -->
            <div class="absolute inset-0">
                <div class="particle" style="top:20%; left:10%;"></div>
                <div class="particle" style="top:40%; left:80%;"></div>
                <div class="particle" style="top:60%; left:20%;"></div>
                <div class="particle" style="top:80%; left:70%;"></div>
            </div>
        </div>
        
        <!-- Contenu -->
        <div class="relative z-20 w-full flex flex-col justify-between p-12 lg:p-16 xl:p-20">
            <!-- En-tête -->
            <div class="mb-auto">
                <div class="inline-flex items-center gap-3 px-4 py-2 rounded-full bg-white/10 backdrop-blur-sm border border-white/20 mb-6">
                    <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                    <span class="text-sm font-medium text-white/90">Service Premium Actif</span>
                </div>
                
                <h2 class="text-4xl lg:text-5xl font-bold text-white mb-4 leading-tight">
                    L'excellence<br>
                    <span class="bg-gradient-to-r from-amber-300 to-amber-400 bg-clip-text text-transparent">à chaque transaction</span>
                </h2>
            </div>
            
            <!-- Citation -->
            <div class="mb-12 max-w-xl">
                <div class="relative">
                    <svg class="absolute -left-6 -top-4 w-12 h-12 text-amber-400/30" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/>
                    </svg>
                    
                    <blockquote class="text-2xl xl:text-3xl font-light italic leading-relaxed text-white/95 pl-8 border-l-4 border-amber-400/50 py-4">
                        "Toujours le meilleur prix, par rapport aux autres, avec une transparence absolue et un service exceptionnel."
                    </blockquote>
                    
                    <div class="flex items-center gap-4 mt-6">
                        <div class="flex -space-x-3">
                            <div class="w-10 h-10 rounded-full border-2 border-white bg-gradient-to-br from-blue-500 to-blue-600"></div>
                            <div class="w-10 h-10 rounded-full border-2 border-white bg-gradient-to-br from-green-500 to-green-600"></div>
                            <div class="w-10 h-10 rounded-full border-2 border-white bg-gradient-to-br from-amber-500 to-amber-600"></div>
                        </div>
                        <div class="text-sm text-white/70">
                            Rejoignez <span class="font-bold text-white">500+ entreprises</span> satisfaites
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Signature -->
            <div class="glass-card rounded-2xl p-6">
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <div class="w-16 h-16 rounded-full bg-gradient-to-br from-white to-gray-100 flex items-center justify-center shadow-xl">
                            <svg class="w-8 h-8 text-zinc-800" fill="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 11l-3 3-1.5-1.5L3 16l3 3 5-5-2-2zm0-6l-3 3-1.5-1.5L3 10l3 3 5-5-2-2zm5 2h7v2h-7V7zm0 6h7v2h-7v-2zm0 6h7v2h-7v-2z"/>
                            </svg>
                        </div>
                        <div class="absolute -bottom-1 -right-1 w-6 h-6 bg-gradient-to-br from-amber-400 to-amber-500 rounded-full flex items-center justify-center">
                            <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                    </div>
                    
                    <div>
                        <div class="text-xl font-bold text-white">PrixCosma Enterprise</div>
                        <div class="text-amber-200 text-sm font-medium">Astucom - Communication - LTD</div>
                        <div class="flex items-center gap-2 mt-1">
                            <div class="flex text-amber-400">
                                <i class="fas fa-star text-xs"></i>
                                <i class="fas fa-star text-xs"></i>
                                <i class="fas fa-star text-xs"></i>
                                <i class="fas fa-star text-xs"></i>
                                <i class="fas fa-star-half-alt text-xs"></i>
                            </div>
                            <span class="text-xs text-white/60">4.8/5 (1.2k avis)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .glass-card {
        background: rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(15px);
        border: 1px solid rgba(255, 255, 255, 0.15);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    }
    
    .particle {
        position: absolute;
        width: 2px;
        height: 2px;
        background: rgba(255, 255, 255, 0.4);
        border-radius: 50%;
        animation: float 20s infinite linear;
    }
    
    .particle:nth-child(1) { animation-delay: 0s; }
    .particle:nth-child(2) { animation-delay: 5s; }
    .particle:nth-child(3) { animation-delay: 10s; }
    .particle:nth-child(4) { animation-delay: 15s; }
    
    @keyframes float {
        0% { transform: translateY(0) translateX(0); opacity: 0; }
        10% { opacity: 1; }
        90% { opacity: 1; }
        100% { transform: translateY(-100px) translateX(50px); opacity: 0; }
    }
    
    @keyframes gradient-shift {
        0% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
        100% { background-position: 0% 50%; }
    }
    
    .gradient-text {
        background-size: 200% auto;
        animation: gradient-shift 3s ease infinite;
    }
    
    /* Animation du logo au survol */
    .group:hover .logo-text {
        background-size: 200% auto;
        animation: gradient-shift 2s ease infinite;
    }
</style>