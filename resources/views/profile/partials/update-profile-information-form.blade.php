<section>
    <header>
        <h2 class="font-display text-xl font-semibold text-[#4F2F2A]">
            {{ __('account.profile.info_title') }}
        </h2>

        <p class="mt-1 text-sm text-[#7A5A52]">
            {{ __('account.profile.info_body') }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('account.profile.name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('account.profile.email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-[#4F2F2A]">
                        {{ __('account.profile.unverified') }}

                        <button form="send-verification" class="underline text-sm text-[#662721] hover:text-[#4F2F2A] rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#662721]/40">
                            {{ __('account.profile.resend_verification') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-green-600">
                            {{ __('account.profile.verification_sent') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        {{-- Modul 33: nomor HP = identitas di kasir & login. Sudah terisi → tidak bisa dikosongkan. --}}
        <div>
            <x-input-label for="phone" :value="__('account.profile.phone')" />
            <x-text-input id="phone" name="phone" type="tel" inputmode="tel" class="mt-1 block w-full" :value="old('phone', $user->phone)"
                          :required="filled($user->phone)" autocomplete="tel" placeholder="0812xxxxxxxx" />
            <p class="mt-1 text-xs text-[#7A5A52]">{{ __('account.profile.phone_hint') }}</p>
            <x-input-error class="mt-2" :messages="$errors->get('phone')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('account.profile.save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-[#7A5A52]"
                >{{ __('account.profile.saved') }}</p>
            @endif
        </div>
    </form>
</section>
