@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-semibold text-sm text-[#4F2F2A]']) }}>
    {{ $value ?? $slot }}
</label>
