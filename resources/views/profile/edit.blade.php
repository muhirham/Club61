{{-- Profile (Modul 23): milik saya — kepala profil, kartu member aktif, menu (Voucher Saya, riwayat, tim corporate),
     pengaturan akun (form Breeze yang sudah ada, logikanya tidak diubah), logout. --}}
<x-app-layout>
    @php
        $initial = mb_strtoupper(mb_substr($user->name, 0, 1));
        $hasRealEmail = ! \App\Support\PlaceholderEmail::is($user->email);
        $menuItem = 'bk-tap flex items-center gap-3 p-4 rounded-xl bg-white/95 border border-[#E6DAC0] hover:border-[#662721] hover:shadow-[0_10px_25px_rgba(102,39,33,0.15)] transition-all';
        $menuIcon = 'w-10 h-10 rounded-lg bg-[#F7F0DB] border border-[#E6DAC0] text-[#662721] flex items-center justify-center shrink-0';
    @endphp

    <div class="py-6 sm:py-8 text-[#4F2F2A]">
        <div class="w-full px-4 sm:px-8 lg:px-12 2xl:px-16 space-y-6">

            {{-- 1. Kepala profil --}}
            <div class="flex flex-col sm:flex-row sm:items-center gap-4 p-5 rounded-xl bg-white/95 border border-[#E6DAC0] shadow-sm">
                <div class="flex items-center gap-4 min-w-0 flex-1">
                    <span class="w-14 h-14 rounded-full bg-[#662721] text-[#F7F0DB] flex items-center justify-center text-xl font-black shrink-0">{{ $initial }}</span>
                    <div class="min-w-0">
                        <h1 class="font-display font-black text-xl sm:text-2xl truncate">{{ $user->name }}</h1>
                        <div class="text-xs text-[#7A5A52] mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5">
                            @if($hasRealEmail)<span class="truncate">{{ $user->email }}</span>@endif
                            @if($user->phone)<span>{{ $user->phone }}</span>@endif
                        </div>
                        <div class="mt-1.5 inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase {{ $membership ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-[#F7F0DB] text-[#7A5A52] border border-[#E6DAC0]' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $membership ? 'bg-emerald-500' : 'bg-[#C9B99A]' }}"></span>
                            {{ $membership ? ($membership->plan?->name ?? __('account.profile.member_fallback')) : __('nav.not_member') }}
                        </div>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="sm:shrink-0">
                    @csrf
                    <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 h-11 px-5 rounded-lg border border-rose-200 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-black uppercase tracking-wider transition-colors cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
                        {{ __('nav.logout') }}
                    </button>
                </form>
            </div>

            <div class="grid grid-cols-1 2xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] gap-6 items-start">
                {{-- 2. Kartu membership aktif / ajakan jadi member --}}
                <section class="space-y-3">
                    <h2 class="px-1 text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('account.profile.my_membership') }}</h2>
                    @if($membership)
                        @include('customer.partials.member-pass', ['membership' => $membership])
                    @else
                        <div class="flex flex-col sm:flex-row sm:items-center gap-4 p-5 rounded-xl border-2 border-dashed border-[#E6DAC0] bg-[#FCF8EE]">
                            <div class="flex-1">
                                <div class="font-display font-black text-lg">{{ __('account.profile.become_member') }}</div>
                                <p class="text-xs text-[#7A5A52] mt-1">{{ __('account.profile.become_member_body') }}</p>
                            </div>
                            <a href="{{ route('customer.membership') }}" class="bk-tap inline-flex items-center justify-center h-11 px-5 rounded-lg bg-[#662721] hover:bg-[#511D18] text-[#F7F0DB] text-xs font-black uppercase tracking-wider shadow-md transition-colors">
                                {{ __('account.profile.see_plans') }}
                            </a>
                        </div>
                    @endif
                </section>

                {{-- 3. Menu --}}
                <section class="space-y-3">
                    <h2 class="px-1 text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('account.profile.my_account') }}</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 2xl:grid-cols-1 gap-3">
                        <a href="{{ route('profile.vouchers') }}" class="{{ $menuItem }}">
                            <span class="{{ $menuIcon }}"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" /></svg></span>
                            <span class="flex-1 min-w-0">
                                <span class="block text-sm font-extrabold">{{ __('nav.my_vouchers') }}</span>
                                <span class="block text-[11px] text-[#7A5A52]">{{ $voucherCount > 0 ? __('account.profile.vouchers_available', ['count' => $voucherCount]) : __('account.profile.vouchers_sub') }}</span>
                            </span>
                            @if($voucherCount > 0)
                                <span class="min-w-[24px] h-6 px-2 rounded-full bg-[#662721] text-[#F7F0DB] text-[11px] font-black flex items-center justify-center">{{ $voucherCount }}</span>
                            @endif
                        </a>
                        <a href="{{ route('customer.invoice') }}" class="{{ $menuItem }}">
                            <span class="{{ $menuIcon }}"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg></span>
                            <span class="flex-1 min-w-0">
                                <span class="block text-sm font-extrabold">{{ __('account.profile.history') }}</span>
                                <span class="block text-[11px] text-[#7A5A52]">{{ __('account.profile.history_sub') }}</span>
                            </span>
                        </a>
                        @if($isSponsorPic)
                            <a href="{{ route('customer.corporate') }}" class="{{ $menuItem }}">
                                <span class="{{ $menuIcon }}"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg></span>
                                <span class="flex-1 min-w-0">
                                    <span class="block text-sm font-extrabold">{{ __('account.profile.corporate') }}</span>
                                    <span class="block text-[11px] text-[#7A5A52]">{{ __('account.profile.corporate_sub') }}</span>
                                </span>
                            </a>
                        @endif
                    </div>
                </section>
            </div>

            {{-- 4. Pengaturan akun --}}
            <section class="space-y-3">
                <h2 class="px-1 text-[11px] font-extrabold uppercase tracking-wider text-[#662721]">{{ __('account.profile.settings') }}</h2>
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
                    <div class="p-6 sm:p-8 rounded-xl bg-[#FCF8EE] border border-[#E6DAC0]">
                        @include('profile.partials.update-profile-information-form')
                    </div>
                    <div class="p-6 sm:p-8 rounded-xl bg-[#FCF8EE] border border-[#E6DAC0]">
                        @include('profile.partials.update-password-form')
                    </div>
                    {{-- Hapus akun mandiri ditiadakan (Modul 33): riwayat booking, invoice & membership tetap melekat ke akun. --}}
                </div>
            </section>

        </div>
    </div>
</x-app-layout>
