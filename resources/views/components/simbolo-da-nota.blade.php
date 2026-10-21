{{-- O símbolo da plataforma: uma nota fiscal com a borda serrilhada do recibo
     e o carimbo de autorizada. Desenhado para fundo escuro (a folha é clara).
     O anel do carimbo tem a cor do fundo para parecer recortado da folha; ela
     muda pela variável --fundo-do-simbolo. O mesmo desenho está em
     public/favicon.svg, sobre um quadrado verde. --}}
<svg {{ $attributes->merge(['viewBox' => '0 0 32 32', 'fill' => 'none', 'aria-hidden' => 'true']) }} xmlns="http://www.w3.org/2000/svg">
    <path d="M9 3h11l5 5v19.5l-2.25-1.5-2.25 1.5-2.25-1.5-2.25 1.5-2.25-1.5-2.25 1.5-2.25-1.5L7 27.5V5a2 2 0 0 1 2-2z" fill="#ffffff"/>
    <path d="M20 3v3.5A1.5 1.5 0 0 0 21.5 8H25z" fill="#a7f3d0"/>
    <path d="M11 12h8M11 16h8M11 20h4" stroke="#047857" stroke-width="1.8" stroke-linecap="round"/>
    <circle cx="23.5" cy="23" r="5.5" fill="#34d399" stroke="var(--fundo-do-simbolo, #064e3b)" stroke-width="2"/>
    <path d="m21.2 23.1 1.6 1.6 3.1-3.3" stroke="#064e3b" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
