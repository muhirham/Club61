<!-- Customer Mobile Bottom Navigation Bar (iOS & Android Style) -->
<nav class="md:hidden" 
     style="position: fixed; bottom: 0; left: 0; right: 0; z-index: 30; background: #662721; border-top: 1px solid #511D18; box-shadow: 0 -8px 24px rgba(79,47,42, 0.18);">
    <div style="max-width: 480px; margin: 0 auto; display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); height: 62px; align-items: center; padding: 0 4px;">
        
        <!-- 1. Beranda -->
        <a href="{{ route('dashboard') }}" 
           class="flex flex-col items-center justify-center py-1 group transition-all {{ request()->routeIs('dashboard') ? 'text-[#F7F0DB]' : 'text-[#F7F0DB]/65 hover:text-[#F7F0DB]' }}">
            <div class="p-1 rounded-xl transition-transform duration-150 {{ request()->routeIs('dashboard') ? 'scale-110 bg-[#F7F0DB] text-[#662721]' : 'group-hover:scale-105' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
            </div>
            <span class="text-[10px] font-bold tracking-tight mt-0.5 {{ request()->routeIs('dashboard') ? 'text-[#F7F0DB]' : '' }}">Home</span>
        </a>

        <!-- 2. Booking Court -->
        <a href="{{ route('customer.booking') }}" 
           class="flex flex-col items-center justify-center py-1 group transition-all {{ request()->routeIs('customer.booking') ? 'text-[#F7F0DB]' : 'text-[#F7F0DB]/65 hover:text-[#F7F0DB]' }}">
            <div class="p-1 rounded-xl transition-transform duration-150 {{ request()->routeIs('customer.booking') ? 'scale-110 bg-[#F7F0DB] text-[#662721]' : 'group-hover:scale-105' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </div>
            <span class="text-[10px] font-bold tracking-tight mt-0.5 {{ request()->routeIs('customer.booking') ? 'text-[#F7F0DB]' : '' }}">Book Court</span>
        </a>

        <!-- 3. My Club (detail membership aktif + katalog beli, bukan langsung halaman jualan) -->
        <a href="{{ route('customer.my-club') }}"
           class="flex flex-col items-center justify-center py-1 group transition-all {{ (request()->routeIs('customer.my-club') || request()->routeIs('customer.membership')) ? 'text-[#F7F0DB]' : 'text-[#F7F0DB]/65 hover:text-[#F7F0DB]' }}">
            <div class="p-1 rounded-xl transition-transform duration-150 {{ (request()->routeIs('customer.my-club') || request()->routeIs('customer.membership')) ? 'scale-110 bg-[#F7F0DB] text-[#662721]' : 'group-hover:scale-105' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
            </div>
            <span class="text-[10px] font-bold tracking-tight mt-0.5 {{ (request()->routeIs('customer.my-club') || request()->routeIs('customer.membership')) ? 'text-[#F7F0DB]' : '' }}">My Club</span>
        </a>

        <!-- 4. Invoice -->
        <a href="{{ route('customer.invoice') }}" 
           class="flex flex-col items-center justify-center py-1 group transition-all {{ request()->routeIs('customer.invoice') ? 'text-[#F7F0DB]' : 'text-[#F7F0DB]/65 hover:text-[#F7F0DB]' }}">
            <div class="p-1 rounded-xl transition-transform duration-150 {{ request()->routeIs('customer.invoice') ? 'scale-110 bg-[#F7F0DB] text-[#662721]' : 'group-hover:scale-105' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            </div>
            <span class="text-[10px] font-bold tracking-tight mt-0.5 {{ request()->routeIs('customer.invoice') ? 'text-[#F7F0DB]' : '' }}">Invoice</span>
        </a>

        <!-- 5. Profil -->
        <a href="{{ route('profile.edit') }}" 
           class="flex flex-col items-center justify-center py-1 group transition-all {{ request()->routeIs('profile.edit') ? 'text-[#F7F0DB]' : 'text-[#F7F0DB]/65 hover:text-[#F7F0DB]' }}">
            <div class="p-1 rounded-xl transition-transform duration-150 {{ request()->routeIs('profile.edit') ? 'scale-110 bg-[#F7F0DB] text-[#662721]' : 'group-hover:scale-105' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
            </div>
            <span class="text-[10px] font-bold tracking-tight mt-0.5 {{ request()->routeIs('profile.edit') ? 'text-[#F7F0DB]' : '' }}">Profile</span>
        </a>

    </div>
</nav>
