<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- A plataforma é clara, e só. Sem isto o navegador escurece a
             página por conta própria quando o sistema está em modo escuro, e o
             resultado é um cinza lavado que não é o desenho de ninguém. --}}
        <meta name="color-scheme" content="light">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        {{-- O cartão do login é um recibo: branco, com a borda serrilhada
             embaixo, sobre o verde da marca. --}}
        <div class="flex min-h-screen flex-col items-center justify-center bg-emerald-900 bg-[radial-gradient(ellipse_at_top,_#047857_0%,_#064e3b_60%)] px-4 py-10">
            <a href="/" class="mb-8">
                <x-application-logo grande />
            </a>

            <div class="borda-de-recibo-clara w-full sm:max-w-md">
                <div class="rounded-t-xl bg-white px-6 pb-8 pt-7 shadow-2xl sm:px-8">
                    {{ $slot }}
                </div>
            </div>

            <p class="mt-10 text-xs text-emerald-200/70">Emissão de NFS-e da HelpFlux</p>
        </div>
    </body>
</html>
