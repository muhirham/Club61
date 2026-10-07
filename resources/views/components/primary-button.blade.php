<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center h-11 px-5 bg-[#662721] border border-transparent rounded-md font-bold text-sm text-[#F7F0DB] hover:bg-[#511D18] focus:bg-[#511D18] active:bg-[#511D18] focus:outline-none focus:ring-2 focus:ring-[#662721]/40 focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
