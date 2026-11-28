{{--
    Dashboard Monitoring Sistem Club 61 (Laravel Pulse, /pulse). Hanya pemegang izin view_server_health (Gate viewPulse).
    Kartu bawaan Pulse berbahasa Inggris; kartu "... Club 61" buatan sendiri (app/Livewire/Monitoring).
    Cache sengaja tidak ditampilkan (recorder-nya dimatikan di config/pulse.php). Usage = akun paling aktif, hanya
    aktivitas orang (App\Support\Monitoring\UsageRequestFilter).
--}}
<x-pulse>
    <livewire:pulse.servers cols="full" />

    <livewire:monitoring.payments-card cols="full" />

    <livewire:monitoring.storage-card cols="6" rows="2" />

    <livewire:monitoring.services-card cols="6" />

    <livewire:pulse.exceptions cols="6" />

    <livewire:pulse.slow-outgoing-requests cols="6" />

    <livewire:pulse.slow-requests cols="6" />

    <livewire:pulse.slow-queries cols="8" />

    <livewire:pulse.queues cols="4" />

    <livewire:pulse.slow-jobs cols="6" />

    <livewire:pulse.usage type="requests" cols="6" />
</x-pulse>
