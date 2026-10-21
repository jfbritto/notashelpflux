<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center rounded-lg border border-transparent bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition ease-in-out duration-150 hover:bg-emerald-700 focus:bg-emerald-700 active:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2']) }}>
    {{ $slot }}
</button>
