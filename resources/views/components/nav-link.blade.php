@props(['active'])

@php
// Sobre o topo verde (layouts/navigation): texto claro, sublinhado no ativo.
$classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-emerald-400 text-sm font-medium leading-5 text-white focus:outline-none focus:border-emerald-300 transition duration-150 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-emerald-100/80 hover:text-white hover:border-emerald-600 focus:outline-none focus:text-white focus:border-emerald-600 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
