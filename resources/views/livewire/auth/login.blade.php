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

<div class="flex min-h-screen bg-gradient-to-br from-gray-50 to-blue-50">
    <!-- Partie gauche - Formulaire -->
    <div class="w-full lg:w-1/2 flex justify-center items-center p-4 md:p-8">
        <div class="w-full max-w-md space-y-8 p-6 md:p-8 bg-white rounded-2xl shadow-xl login-transition">
            <!-- Logo amélioré -->
            <div class="flex justify-center">
                <a href="/" class="group flex items-center gap-2">
                    <div class="relative">
                        <div class="absolute -inset-1 bg-gradient-to-r from-blue-500 to-amber-500 rounded-lg blur opacity-25 group-hover:opacity-40 transition duration-300"></div>
                        <div class="relative flex items-center gap-2 bg-white px-4 py-2 rounded-lg">
                            <span class="text-2xl font-bold bg-gradient-to-r from-zinc-800 to-zinc-900 bg-clip-text text-transparent">PRIX</span>
                            <span class="text-2xl font-bold bg-gradient-to-r from-amber-700 to-amber-800 bg-clip-text text-transparent">COSMA</span>
                        </div>
                    </div>
                </a>
            </div>

            <div class="text-center">
                <h2 class="text-3xl font-bold text-gray-900 mb-2">Bienvenue à nouveau</h2>
                <p class="text-gray-500">Connectez-vous pour accéder à votre compte</p>
            </div>

            <x-form wire:submit="login" class="space-y-6">
                <div class="space-y-4">
                    <div>
                        <x-input 
                            label="Email" 
                            wire:model.live="email" 
                            placeholder="votre@email.com" 
                            icon="o-envelope" 
                            hint="Votre adresse email"
                            class="input-focus rounded-lg border-gray-300 py-3"
                            input-class="py-3"
                        />
                    </div>
                    
                    <div>
                        <x-password 
                            label="Mot de passe" 
                            wire:model.lazy="password" 
                            placeholder="••••••••" 
                            clearable 
                            hint="Votre mot de passe"
                            class="input-focus rounded-lg border-gray-300 py-3"
                            input-class="py-3"
                        />
                        <div class="mt-2 flex justify-end">
                            <a href="#" class="text-sm text-blue-600 hover:text-blue-800 transition-colors">Mot de passe oublié ?</a>
                        </div>
                    </div>
                </div>

                <x-slot:actions>
                    <x-button 
                        label="Connexion" 
                        class="w-full bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-semibold py-3 px-4 rounded-lg text-base login-transition shadow-md hover:shadow-lg transform hover:-translate-y-0.5" 
                        type="submit" 
                        spinner="login"
                        wire:loading.attr="disabled" />
                </x-slot:actions>
            </x-form>

            <div class="pt-6 border-t border-gray-100 text-center">
                <p class="text-gray-500 text-sm">
                    Pas encore de compte ? 
                    <a href="#" class="font-medium text-blue-600 hover:text-blue-800 ml-1">Créer un compte</a>
                </p>
            </div>
        </div>
    </div>

    <!-- Partie droite - Image avec onde -->
    <div class="hidden lg:flex lg:w-1/2 relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-br from-zinc-900/90 via-zinc-900/80 to-zinc-900/90 z-10"></div>
        
        <!-- Onde animée -->
        <div class="absolute inset-0 z-20 wave-container">
            <div class="wave"></div>
            <div class="wave"></div>
            <div class="wave"></div>
        </div>
        
        <!-- Image de fond -->
        <div class="absolute inset-0 z-0"
             style="background-image: url('https://images.pexels.com/photos/1888026/pexels-photo-1888026.jpeg'); background-size: cover; background-position: center; background-attachment: fixed;">
        </div>
        
        <!-- Contenu flottant -->
        <div class="relative z-30 w-full flex flex-col justify-end items-start p-12 lg:p-16 xl:p-20 float-animation">
            <!-- Quote amélioré -->
            <div class="glass-effect rounded-2xl p-8 mb-8 max-w-xl">
                <svg class="w-10 h-10 text-amber-400 mb-4" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/>
                </svg>
                <blockquote class="text-2xl xl:text-3xl font-light italic leading-relaxed text-white mb-4">
                    "Toujours le meilleur prix, par rapport aux autres"
                </blockquote>
                <div class="h-1 w-16 bg-amber-400 rounded-full mb-4"></div>
                <p class="text-zinc-300 font-medium">Notre engagement envers vous</p>
            </div>

            <!-- Signature améliorée -->
            <div class="flex items-center gap-4 glass-effect rounded-2xl p-6">
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-amber-400 to-amber-600 flex items-center justify-center shadow-lg">
                    <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 11l-3 3-1.5-1.5L3 16l3 3 5-5-2-2zm0-6l-3 3-1.5-1.5L3 10l3 3 5-5-2-2zm5 2h7v2h-7V7zm0 6h7v2h-7v-2zm0 6h7v2h-7v-2z"/>
                    </svg>
                </div>
                <div>
                    <div class="text-xl font-bold text-white">PrixCosma</div>
                    <div class="text-amber-200 text-sm font-medium">Astucom - Communication - LTD</div>
                    <div class="text-zinc-300 text-sm mt-1">Depuis 2015 • Plus de 500 partenaires</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Indicateur responsive -->
    <div class="lg:hidden absolute bottom-4 left-0 right-0 flex justify-center">
        <div class="bg-black/70 text-white text-xs px-3 py-1 rounded-full backdrop-blur-sm">
            <i class="fas fa-rotate mr-1"></i> Tournez l'appareil pour voir l'image
        </div>
    </div>
</div>

<style>
    @keyframes wave {
        0% { transform: translateY(0) translateX(0) rotate(0deg); opacity: 0.5; }
        25% { transform: translateY(-20px) translateX(15px) rotate(90deg); opacity: 0.3; }
        50% { transform: translateY(0) translateX(30px) rotate(180deg); opacity: 0.5; }
        75% { transform: translateY(20px) translateX(15px) rotate(270deg); opacity: 0.3; }
        100% { transform: translateY(0) translateX(0) rotate(360deg); opacity: 0.5; }
    }
    
    @keyframes float {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-15px); }
    }
    
    .wave-container { position: relative; overflow: hidden; }
    .wave {
        position: absolute;
        border-radius: 45%;
        background: rgba(251, 191, 36, 0.15);
        animation: wave 20s infinite linear;
    }
    .wave:nth-child(1) {
        width: 400px; height: 400px;
        top: -200px; right: -150px;
        animation-duration: 20s;
    }
    .wave:nth-child(2) {
        width: 500px; height: 500px;
        top: -250px; right: -200px;
        animation-duration: 25s;
        animation-delay: -5s;
    }
    .wave:nth-child(3) {
        width: 600px; height: 600px;
        top: -300px; right: -250px;
        animation-duration: 30s;
        animation-delay: -10s;
    }
    .float-animation { animation: float 8s ease-in-out infinite; }
    .glass-effect {
        background: rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.15);
    }
    .input-focus:focus-within { 
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        border-color: rgba(59, 130, 246, 0.5);
    }
    .login-transition { transition: all 0.3s ease; }
</style>