<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center h-11 px-5 bg-red-700 border border-transparent rounded-md font-bold text-sm text-white hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
