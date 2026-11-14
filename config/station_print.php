<?php

/*
 * Slip pesanan F&B ke printer LAN stasiun (Kitchen, dst.) saat kasir memakai BROWSER (bukan aplikasi Club61).
 * Aplikasi Android Club61 (APK) selalu mengirim sendiri lewat jembatan Club61Print — pengaturan ini tidak mengubahnya.
 *
 * driver:
 *  - tablet : perilaku lama — dari browser slip tidak bisa dikirim (pesan "hanya bisa dari aplikasi"). Bawaan.
 *  - agent  : browser menitipkan slip ke Club61 Print Agent di PC kasir (tools/print-agent), agent yang mengirim ke printer.
 *  - mock   : tidak dikirim ke printer; isi slip ditampilkan & disimpan ke storage/logs/print-mock/ (tes tanpa printer).
 */
return [
    'driver' => env('STATION_PRINT_DRIVER', 'tablet'),

    // Alamat Club61 Print Agent di PC kasir (hanya mendengar di 127.0.0.1) & token yang sama dengan agent.json.
    'agent_url' => rtrim((string) env('STATION_PRINT_AGENT_URL', 'http://127.0.0.1:9120'), '/'),
    'agent_token' => env('STATION_PRINT_AGENT_TOKEN'),

    // Geser slip ke kanan (titik printer, 8 titik = 1mm) untuk kertas 58mm di printer stasiun 80mm — kepala printnya
    // mulai di luar pinggir kiri kertas. Berlaku untuk slip stasiun saja (aplikasi & browser), struk kasir tidak.
    // Maks 192 = 576 titik kepala 80mm - 384 titik isi 32 kolom. 0 = tidak digeser.
    'left_margin' => max(0, min(192, (int) env('STATION_PRINT_LEFT_MARGIN', 0))),
];
