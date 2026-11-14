# Club61 Print Agent — Slip Kitchen dari Browser PC Kasir

Begitu pesanan F&B lunas, slip pesanan dikirim ke printer LAN stasiun (Kitchen, `10.10.2.137:9100`).

| Kasir memakai | Cara slip sampai ke printer |
|---|---|
| Aplikasi Android Club61 (APK) | Aplikasi mengirim langsung (`docs/FLUTTER_LAN_PRINT.md`). **Tidak berubah.** |
| Browser di PC/laptop (Chrome, termasuk PWA di PC) | Lewat **Club61 Print Agent** di PC yang sama |
| Browser / PWA di HP atau tablet | Tidak bisa. Pakai APK, atau tombol **Cetak di Printer Kasir** |

Browser tidak diizinkan membuka koneksi langsung ke printer, dan server (VPS) tidak berada di jaringan venue. Karena itu
halaman kasir menitipkan slip ke agent di `http://127.0.0.1:9120`, lalu agent meneruskannya ke printer. Isi slip dibuat
oleh kode yang sama dengan aplikasi Android (`club61HtmlToEscPosBase64`), jadi hasil cetaknya identik. Kertas tetap 58mm
(32 kolom). Bentuk slip (gaya slip dapur): judul, `MEJA n  ANTRIAN nnn` besar, Waktu/Order/Nama, item tebal dengan
catatan `*` menjorok, `Jumlah item`, `Oleh` — `resources/views/pos/receipts/fnb-station-slip.blade.php`.

## Pengaturan `.env`

| | Local (tanpa printer) | Local / Sandbox (printer asli) | Production |
|---|---|---|---|
| `STATION_PRINT_DRIVER` | `mock` | `agent` | `tablet` (tetap — ubah sendiri kalau PC kasir mau dipakai) |
| `STATION_PRINT_AGENT_URL` | – | `http://127.0.0.1:9120` | – |
| `STATION_PRINT_AGENT_TOKEN` | – | token acak, **sama dengan `agent.json`** | – |
| `STATION_PRINT_LEFT_MARGIN` | – | `156` (kertas 58mm di printer Kitchen 80mm) | `156` kalau Kitchen memakai kertas 58mm |

`STATION_PRINT_LEFT_MARGIN`: printer Kitchen venue adalah printer **80mm** (self-test: *Print width 80mm*). Kertas 58mm
duduk di tengah, sehingga tanpa geser huruf awal slip tercetak di luar kertas (terpotong kiri). Nilai dalam titik printer
(8 titik = 1mm, 0–192); hasil kalibrasi venue = **156**. Hanya slip stasiun yang digeser (struk kasir tidak), dan berlaku
juga untuk aplikasi Android karena printernya sama. Pakai sekat 58mm di printer supaya posisi gulungan tidak bergeser.
Kalau printer diganti atau memakai kertas 80mm: kembalikan ke `0`.

- `tablet`: perilaku lama — dari browser muncul "hanya bisa dari aplikasi Club61".
- `mock`: slip tidak dikirim ke printer. Isinya muncul di layar dan tersimpan di `storage/logs/print-mock/`
  (`.bin` = byte yang akan dikirim, `.txt` = versi yang bisa dibaca). Slip ditandai terkirim.
- `agent`: lewat Club61 Print Agent.

Setelah mengubah `.env`: `php artisan config:clear` (atau `config:cache` di server).

Buat token acak (PowerShell): `-join ((48..57)+(65..90)+(97..122) | Get-Random -Count 40 | ForEach-Object {[char]$_})`

## Pasang agent di PC kasir

Syarat: PC satu jaringan dengan printer Kitchen.

1. Build (sekali, di laptop developer): `cd tools/print-agent` lalu
   `GOOS=windows GOARCH=amd64 go build -trimpath -ldflags "-s -w -H windowsgui" -o dist/club61-print-agent.exe .`
2. Buat folder di PC kasir, mis. `C:\Club61PrintAgent`, salin `club61-print-agent.exe`, `install-autostart.ps1`,
   `uninstall-autostart.ps1`, dan `agent.example.json`.
3. Salin `agent.example.json` → `agent.json`, isi:
   - `token`: sama dengan `STATION_PRINT_AGENT_TOKEN`;
   - `allowed_origins`: alamat Club61 yang dibuka di PC ini (production, sandbox, local — persis dengan `https://…`,
     tanpa `/` di akhir);
   - `printers`: IP:port printer stasiun, mis. `10.10.2.137:9100`.
4. Jalankan sekali (PowerShell, di folder itu): `powershell -ExecutionPolicy Bypass -File .\install-autostart.ps1`
   → agent jalan sekarang dan otomatis tiap user Windows ini login. Pesan hijau "OK - Club61 Print Agent … aktif" = siap.
5. Cek kapan saja: buka `http://127.0.0.1:9120/health` di Chrome → `{"ok":true,"name":"club61-print-agent",…}`.
6. Berhenti / hapus: `uninstall-autostart.ps1`. Riwayat ada di `agent.log` (folder yang sama).

Chrome versi baru bisa sekali bertanya **"Izinkan situs ini mengakses perangkat di jaringan lokal?"** → pilih **Izinkan**.

### Keamanan agent
- Hanya mendengar di `127.0.0.1`: perangkat lain di WiFi tidak bisa memanggilnya.
- `/print` wajib token; permintaan dari browser hanya diterima dari `allowed_origins`.
- Tujuan cetak hanya `printers` yang terdaftar — tidak bisa dipakai mengirim ke alamat lain.
- Tidak menyimpan data, tidak mengakses internet. Token hanya dikirim ke halaman panel admin saat driver `agent` **dan**
  user sudah login (panel tidak punya halaman login sendiri; tamu diarahkan ke `/login` yang tidak memuat skrip cetak).

## Tes Cetak

Menu F&B → **Stasiun & Printer** → tombol **Tes Cetak** di baris stasiun (muncul kalau IP printer sudah diisi; izin
`manage_fnb_menu`). Slip "TES PRINTER" dikirim lewat jalur yang sama dengan slip pesanan (APK / agent / mode tes) memakai
IP & port yang **sudah disimpan** dan geser `STATION_PRINT_LEFT_MARGIN`. Tidak membuat order atau slip di database.
Garis `|------------------------------|` di slip harus terlihat utuh di kedua ujung; kalau terpotong, ubah angka geser.

## Checklist uji (berurutan)

**1. Local — mock (tanpa printer)**
- [ ] `.env` local: `STATION_PRINT_DRIVER=mock`, `php artisan config:clear`.
- [ ] Buka POS F&B di Chrome laptop, buka shift, jual 1 menu yang Stasiun Produksi-nya **Kitchen**, bayar sampai lunas.
- [ ] Muncul jendela "Mode tes — slip Kitchen" berisi slip (PESANAN KITCHEN, nomor antrian, item, catatan).
- [ ] File `.bin` & `.txt` ada di `storage/logs/print-mock/`.

**2. Local — agent ke printer asli**
- [ ] Agent terpasang di laptop (bagian di atas), `printers` berisi `10.10.2.137:9100`, `allowed_origins` berisi alamat
      local (mis. `http://localhost:8000`).
- [ ] `.env` local: `STATION_PRINT_DRIVER=agent` + token, `php artisan config:clear`.
- [ ] Menu F&B → Stasiun & Printer → Kitchen = `10.10.2.137`, port `9100`.
- [ ] Jual 1 menu Kitchen → slip keluar di printer Kitchen, kasir dapat toast "Slip Kitchen terkirim".
- [ ] Matikan agent (`uninstall-autostart.ps1`) → jual lagi → peringatan "Print agent belum aktif…" dengan tombol
      **Coba Lagi** & **Cetak di Printer Kasir**. Nyalakan lagi → **Coba Lagi** → slip keluar.
- [ ] Riwayat → buka transaksi → **Kirim Ulang ke Stasiun** → slip keluar lagi.

**3. Sandbox — agent ke printer asli**
- [ ] `.env` sandbox (di server, oleh Anda): `STATION_PRINT_DRIVER=agent`, `STATION_PRINT_AGENT_TOKEN=<token>`,
      `php artisan config:cache`.
- [ ] Domain sandbox (`https://…`) ada di `allowed_origins` agent.json, agent di-restart.
- [ ] Ulangi langkah uji bagian 2 dari domain sandbox. Beritahu dapur bahwa slip ini **tes**.

**4. Production** — hanya setelah Anda setujui: `.env` production tetap `tablet` sampai diubah sendiri.
- [ ] Tablet APK tetap mengirim slip Kitchen seperti biasa (tidak ada perubahan).
- [ ] Kalau PC kasir production mau dipakai: pasang agent dengan origin production, ubah `.env` → `agent`, `config:cache`.

## Kode
- Web: `resources/views/pos/partials/receipt-print-script.blade.php` (`club61SendToStation` → `club61SendFromBrowser`;
  jalur aplikasi Android tidak diubah), `config/station_print.php`.
- Mode tes: `App\Services\Fnb\MockStationPrinter`, `FnbCashierTerminal::saveMockStationPrint()`.
- Agent: `tools/print-agent/` (Go, `go test ./...`).
- Test: `tests/Feature/FnbPosCheckoutTest.php` (mock, config), `tools/print-agent/main_test.go` (token, origin, whitelist,
  preflight, byte identik ke printer palsu).
