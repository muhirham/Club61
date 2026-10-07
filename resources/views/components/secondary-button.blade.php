<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center h-11 px-5 bg-[#FCF8EE] border border-[#E6DAC0] rounded-md font-bold text-sm text-[#662721] hover:border-[#662721] focus:outline-none focus:ring-2 focus:ring-[#662721]/30 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
