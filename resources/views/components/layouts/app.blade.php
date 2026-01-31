<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light" class="h-full bg-white">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title . ' - ' . config('app.name') : config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Andika:ital,wght@0,400;0,700;1,400;1,700&display=swap"
        rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
</head>

<body class="h-full" x-data="{ sidebarOpen: false }">
    <!-- Off-canvas menu for mobile -->
    <div class="relative z-50 lg:hidden" role="dialog" aria-modal="true" x-show="sidebarOpen" x-cloak>
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-gray-900/80" x-show="sidebarOpen"
            x-transition:enter="transition-opacity ease-linear duration-300" x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity ease-linear duration-300"
            x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="sidebarOpen = false"
            aria-hidden="true">
        </div>

        <div class="fixed inset-0 flex" x-show="sidebarOpen">
            <div class="relative mr-16 flex w-full max-w-xs flex-1" x-show="sidebarOpen"
                x-transition:enter="transition ease-in-out duration-300 transform"
                x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-in-out duration-300 transform"
                x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full">
                <!-- Close button -->
                <div class="absolute top-0 left-full flex w-16 justify-center pt-5">
                    <button type="button" class="-m-2.5 p-2.5" @click="sidebarOpen = false">
                        <span class="sr-only">Close sidebar</span>
                        <svg class="size-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Sidebar content -->
                <div class="flex grow flex-col gap-y-5 overflow-y-auto bg-base-100 px-6 pb-2">
                    <!-- Brand -->
                    <div class="flex h-16 shrink-0 items-center">
                        <img class="h-8 w-auto" src="{{ asset('logo.png') }}" alt="{{ config('app.name') }}">
                    </div>

                    <!-- Navigation -->
                    <nav class="flex flex-1 flex-col">
                        <ul role="list" class="flex flex-1 flex-col gap-y-7">
                            <li>
                                <ul role="list" class="-mx-2 space-y-1">
                                    <!-- Boutiques -->
                                    <li>
                                        <a href="/boutique"
                                            class="group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold {{ request()->is('boutique*') ? 'bg-primary text-primary-content' : 'text-base-content hover:bg-base-300' }}">
                                            <svg class="size-6 shrink-0 {{ request()->is('boutique*') ? 'text-primary-content' : 'text-base-content group-hover:text-base-content' }}"
                                                fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                                aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" />
                                            </svg>
                                            Boutiques
                                        </a>
                                    </li>

                                    <!-- Créer une liste personnalisée -->
                                    <li>
                                        <a href="/top-product"
                                            class="group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold {{ request()->is('top-product*') ? 'bg-primary text-primary-content' : 'text-base-content hover:bg-base-300' }}">
                                            <svg class="size-6 shrink-0 {{ request()->is('top-product*') ? 'text-primary-content' : 'text-base-content group-hover:text-base-content' }}"
                                                fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                                aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15a2.25 2.25 0 0 1 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                                            </svg>
                                            Créer une liste personnalisée
                                        </a>
                                    </li>

                                    <!-- Parcourir les concurrents -->
                                    <li>
                                        <a href="/scraped_products"
                                            class="group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold {{ request()->is('scraped_products*') ? 'bg-primary text-primary-content' : 'text-base-content hover:bg-base-300' }}">
                                            <svg class="size-6 shrink-0 {{ request()->is('scraped_products*') ? 'text-primary-content' : 'text-base-content group-hover:text-base-content' }}"
                                                fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                                aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                            </svg>
                                            Parcourir les concurrents
                                        </a>
                                    </li>
                                </ul>
                            </li>

                            <!-- User profile -->
                            @if($user = auth()->user())
                                <li class="mt-auto">
                                    <div
                                        class="flex items-center gap-x-4 px-2 py-3 text-sm/6 font-semibold rounded-md hover:bg-base-300">
                                        <img class="size-8 rounded-full bg-base-300"
                                            src="https://ui-avatars.com/api/?name={{ urlencode($user->name) }}&color=7F9CF5&background=EBF4FF"
                                            alt="{{ $user->name }}">
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-base-content">{{ $user->name }}</p>
                                            <p class="text-xs text-base-content/70 truncate">{{ $user->email }}</p>
                                        </div>
                                        <!-- Logout -->
                                        <form method="POST" action="{{ route('logout') }}" class="inline">
                                            @csrf
                                            <button type="submit" class="p-1 rounded hover:bg-base-400" title="Déconnexion">
                                                <svg class="size-5 text-base-content" fill="none" viewBox="0 0 24 24"
                                                    stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </li>
                            @endif
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>

    <!-- Static sidebar for desktop -->
    <div class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-72 lg:flex-col">
        <div class="flex grow flex-col gap-y-5 overflow-y-auto bg-base-100 px-6">
            <!-- Brand -->
            <div class="flex h-16 shrink-0 items-center">
                <img class="h-8 w-auto" src="{{ asset('logo.png') }}" alt="{{ config('app.name') }}">
            </div>

            <!-- Navigation -->
            <nav class="flex flex-1 flex-col">
                <ul role="list" class="flex flex-1 flex-col gap-y-7">
                    <li>
                        <ul role="list" class="-mx-2 space-y-1">
                            <!-- Boutiques -->
                            <li>
                                <a href="/boutique"
                                    class="group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold {{ request()->is('boutique*') ? 'bg-primary text-primary-content' : 'text-base-content hover:bg-base-300' }}">
                                    <svg class="size-6 shrink-0 {{ request()->is('boutique*') ? 'text-primary-content' : 'text-base-content group-hover:text-base-content' }}"
                                        fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                        aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" />
                                    </svg>
                                    Boutiques
                                </a>
                            </li>

                            <!-- Créer une liste personnalisée -->
                            <li>
                                <a href="/top-product"
                                    class="group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold {{ request()->is('top-product*') ? 'bg-primary text-primary-content' : 'text-base-content hover:bg-base-300' }}">
                                    <svg class="size-6 shrink-0 {{ request()->is('top-product*') ? 'text-primary-content' : 'text-base-content group-hover:text-base-content' }}"
                                        fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                        aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15a2.25 2.25 0 0 1 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                                    </svg>
                                    Créer une liste personnalisée
                                </a>
                            </li>

                            <!-- Parcourir les concurrents -->
                            <li>
                                <a href="/scraped_products"
                                    class="group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold {{ request()->is('scraped_products*') ? 'bg-primary text-primary-content' : 'text-base-content hover:bg-base-300' }}">
                                    <svg class="size-6 shrink-0 {{ request()->is('scraped_products*') ? 'text-primary-content' : 'text-base-content group-hover:text-base-content' }}"
                                        fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                        aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                    </svg>
                                    Parcourir les concurrents
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- User profile -->
                    @if($user = auth()->user())
                        <li class="mt-auto">
                            <div
                                class="flex items-center gap-x-4 px-2 py-3 text-sm/6 font-semibold rounded-md hover:bg-base-300 mb-6">
                                <img class="size-8 rounded-full bg-base-300"
                                    src="https://ui-avatars.com/api/?name={{ urlencode($user->name) }}&color=7F9CF5&background=EBF4FF"
                                    alt="{{ $user->name }}">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-base-content">{{ $user->name }}</p>
                                    <p class="text-xs text-base-content/70 truncate">{{ $user->email }}</p>
                                </div>
                                <!-- Logout -->
                                <form method="POST" action="{{ route('logout') }}" class="inline">
                                    @csrf
                                    <button type="submit" class="p-1 rounded hover:bg-base-400" title="Déconnexion">
                                        <svg class="size-5 text-base-content" fill="none" viewBox="0 0 24 24"
                                            stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </li>
                    @endif
                </ul>
            </nav>
        </div>
    </div>

    <!-- Mobile header -->
    <div class="sticky top-0 z-40 flex items-center gap-x-6 bg-base-100 px-4 py-4 shadow-sm sm:px-6 lg:hidden">
        <button type="button" class="-m-2.5 p-2.5 text-base-content lg:hidden" @click="sidebarOpen = true">
            <span class="sr-only">Open sidebar</span>
            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
        </button>
        <div class="flex-1 text-sm/6 font-semibold">
            <img class="h-6 w-auto" src="{{ asset('logo.png') }}" alt="{{ config('app.name') }}">
        </div>
        @if($user = auth()->user())
            <a href="#" class="relative">
                <span class="sr-only">Your profile</span>
                <img class="size-8 rounded-full bg-base-300"
                    src="https://ui-avatars.com/api/?name={{ urlencode($user->name) }}&color=7F9CF5&background=EBF4FF"
                    alt="{{ $user->name }}">
            </a>
        @endif
    </div>

    <!-- Main content -->
    <main class="py-10 lg:pl-72">
        <div class="px-4 sm:px-6 lg:px-8">
            {{ $slot }}
        </div>
    </main>

    <!-- TOAST area -->
    <x-toast />

    <!-- Alpine.js -->
    <script src="//unpkg.com/alpinejs" defer></script>
</body>

</html>