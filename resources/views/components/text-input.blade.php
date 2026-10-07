@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'h-12 px-3.5 bg-white border-[#E6DAC0] text-[#4F2F2A] placeholder-[#A08F86] focus:border-[#662721] focus:ring-2 focus:ring-[#662721]/20 rounded-md text-[15px]']) }}>
