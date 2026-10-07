<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg flex items-center justify-center shadow-md font-bold text-sm tracking-wider"
                     style="background: #662721; color: #F7F0DB;">
                    61
                </div>
                <div>
                    <h2 class="font-display font-extrabold text-xl text-[#4F2F2A] tracking-wide">
                        Member Profile Settings
                    </h2>
                    <p class="text-xs text-[#7A5A52]">Manage your account profile, password security, and account preferences.</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-8 min-h-screen text-[#4F2F2A]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="p-6 sm:p-8"
                 style="background: #FCF8EE; border: 1px solid #E6DAC0; border-radius: 12px;">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="p-6 sm:p-8"
                 style="background: #FCF8EE; border: 1px solid #E6DAC0; border-radius: 12px;">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="p-6 sm:p-8"
                 style="background: #FCF8EE; border: 1px solid #E6DAC0; border-radius: 12px;">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
