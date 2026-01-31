<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">

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
</head>

<body class="min-h-screen font-sans antialiased bg-base-200">

    {{-- Off-canvas menu for mobile --}}
    <div class="relative z-50 lg:hidden" role="dialog" aria-modal="true" x-data="{ open: false }">
        {{-- Backdrop --}}
        <div x-show="open" x-transition:enter="transition-opacity ease-linear duration-300"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-linear duration-300" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" class="fixed inset-0 bg-gray-900/80" aria-hidden="true"
            @click="open = false">
        </div>

        {{-- Sidebar --}}
        <div x-show="open" x-transition:enter="transition ease-in-out duration-300 transform"
            x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in-out duration-300 transform" x-transition:leave-start="translate-x-0"
            x-transition:leave-end="-translate-x-full" class="fixed inset-0 flex">
            <div class="relative mr-16 flex w-full max-w-xs flex-1">
                {{-- Close button --}}
                <div class="absolute top-0 left-full flex w-16 justify-center pt-5">
                    <button type="button" class="-m-2.5 p-2.5" @click="open = false">
                        <span class="sr-only">Close sidebar</span>
                        <svg class="size-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {{-- Sidebar content --}}
                <div class="flex grow flex-col gap-y-5 overflow-y-auto bg-base-100 px-6 pb-2">
                    {{-- Brand --}}
                    <div class="flex h-16 shrink-0 items-center">
                        <x-app-brand />
                    </div>

                    {{-- Menu --}}
                    <nav class="flex flex-1 flex-col">
                        <ul role="list" class="flex flex-1 flex-col gap-y-7">
                            <li>
                                <ul role="list" class="-mx-2 space-y-1">
                                    {{-- Vos liens de menu --}}
                                    <x-menu-item-mobile title="Boutiques" icon="o-building-storefront"
                                        link="/boutique" />
                                    <x-menu-item-mobile title="Créer une liste personnalisée"
                                        icon="o-clipboard-document-list" link="/top-product" />
                                    <x-menu-item-mobile title="Parcourir les concurrents" icon="o-magnifying-glass"
                                        link="/scraped_products" />
                                </ul>
                            </li>
                            {{-- User profile --}}
                            @if($user = auth()->user())
                                <li class="mt-auto">
                                    <a href="#"
                                        class="flex items-center gap-x-4 px-2 py-3 text-sm/6 font-semibold rounded-md hover:bg-base-300">
                                        <img class="size-8 rounded-full bg-base-300"
                                            src="https://ui-avatars.com/api/?name={{ urlencode($user->name) }}&color=7F9CF5&background=EBF4FF"
                                            alt="">
                                        <div>
                                            <span class="block">{{ $user->name }}</span>
                                            <span class="block text-xs text-base-content/70">{{ $user->email }}</span>
                                        </div>
                                        <livewire:auth.logout class="ml-auto" />
                                    </a>
                                </li>
                            @endif
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>

    {{-- Static sidebar for desktop --}}
    <div class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-72 lg:flex-col">
        <div class="flex grow flex-col gap-y-5 overflow-y-auto bg-base-100 px-6">
            {{-- Brand --}}
            <div class="flex h-16 shrink-0 items-center">
                <x-app-brand />
            </div>

            {{-- Menu --}}
            <nav class="flex flex-1 flex-col">
                <ul role="list" class="flex flex-1 flex-col gap-y-7">
                    <li>
                        <ul role="list" class="-mx-2 space-y-1">
                            {{-- Vos liens de menu --}}
                            <x-menu-item-desktop title="Boutiques" icon="o-building-storefront" link="/boutique" />
                            <x-menu-item-desktop title="Créer une liste personnalisée" icon="o-clipboard-document-list"
                                link="/top-product" />
                            <x-menu-item-desktop title="Parcourir les concurrents" icon="o-magnifying-glass"
                                link="/scraped_products" />
                        </ul>
                    </li>
                    {{-- User profile --}}
                    @if($user = auth()->user())
                        <li class="mt-auto">
                            <a href="#"
                                class="flex items-center gap-x-4 px-2 py-3 text-sm/6 font-semibold rounded-md hover:bg-base-300 mb-6">
                                <img class="size-8 rounded-full bg-base-300"
                                    src="https://ui-avatars.com/api/?name={{ urlencode($user->name) }}&color=7F9CF5&background=EBF4FF"
                                    alt="">
                                <div class="flex-1">
                                    <span class="block">{{ $user->name }}</span>
                                    <span class="block text-xs text-base-content/70">{{ $user->email }}</span>
                                </div>
                                <livewire:auth.logout />
                            </a>
                        </li>
                    @endif
                </ul>
            </nav>
        </div>
    </div>

    {{-- Mobile header --}}
    <div class="sticky top-0 z-40 flex items-center gap-x-6 bg-base-100 px-4 py-4 shadow-sm sm:px-6 lg:hidden">
        <button type="button" class="-m-2.5 p-2.5 text-base-content lg:hidden" @click="open = true">
            <span class="sr-only">Open sidebar</span>
            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
        </button>
        <div class="flex-1 text-sm/6 font-semibold">
            <x-app-brand />
        </div>
        @if($user = auth()->user())
            <a href="#">
                <span class="sr-only">Your profile</span>
                <img class="size-8 rounded-full bg-base-300"
                    src="https://ui-avatars.com/api/?name={{ urlencode($user->name) }}&color=7F9CF5&background=EBF4FF"
                    alt="">
            </a>
        @endif
    </div>

    {{-- Main content --}}
    <main class="py-10 lg:pl-72">
        <div class="px-4 sm:px-6 lg:px-8">
            {{ $slot }}
        </div>
    </main>

    {{-- TOAST area --}}
    <x-toast />

    {{-- Alpine.js pour gérer l'état du menu mobile --}}
    <script src="//unpkg.com/alpinejs" defer></script>
</body>

</html>