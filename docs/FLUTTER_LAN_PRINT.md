# Cetak Slip Stasiun ke Printer LAN — Aplikasi Flutter Club61

Begitu pesanan F&B lunas:

1. **Struk customer** keluar di printer kasir (Bluetooth via RawBT), sama seperti sebelumnya.
2. **Slip pesanan** tiap stasiun (Kitchen, nanti Bar, dst.) dikirim tablet kasir langsung ke **printer LAN** stasiun itu.

Server tidak bisa menjangkau printer di jaringan venue, dan browser tidak bisa membuka koneksi ke IP printer. Karena itu yang mengirim adalah aplikasi Flutter di tablet kasir, yang satu WiFi dengan printer.

Nama stasiun dan IP printernya diatur di **Admin → Menu F&B → tab Stasiun & Printer**.

## Kontrak web ↔ aplikasi

| Arah | Isi |
|---|---|
| Aplikasi → web (setelah halaman selesai dimuat) | `window.Club61Caps = { lanPrint: true }` — tanda aplikasi sudah bisa kirim ke printer LAN. Tanpa ini web langsung menganggap gagal dan memunculkan peringatan "Aplikasi Club61 belum versi terbaru". |
| Web → aplikasi | `Club61Print.postMessage('{"action":"lan_print","id":"lan-…","host":"192.168.1.50","port":9100,"data":"<ESC/POS base64>"}')` |
| Aplikasi → web (setelah kirim) | `window.club61LanPrintResult(id, true, null)` kalau berhasil, atau `window.club61LanPrintResult(id, false, "pesan error")` kalau gagal. |

Catatan:
- `data` sudah berupa byte ESC/POS lengkap (teks, tebal, dobel tinggi, potong kertas). Aplikasi cukup mengirimnya apa adanya ke `host:port`.
- Kalau dalam 10 detik tidak ada balasan, web menganggap gagal ("printer tidak merespons").
- Hasil berhasil/gagal dicatat di server. Kalau gagal, kasir dapat peringatan dengan tombol **Coba Lagi** / **Cetak di Printer Kasir**.

## Kode Dart (webview_flutter)

Tambahkan ke channel `Club61Print` yang sudah ada. Pesan lama (selain `lan_print`) tetap diproses seperti biasa.

```dart
import 'dart:convert';
import 'dart:io';

import 'package:webview_flutter/webview_flutter.dart';

/// Hanya IP jaringan lokal yang boleh dituju (printer di venue) — bukan alamat internet.
bool _isPrivateLanHost(String host) {
  final ip = InternetAddress.tryParse(host);
  if (ip == null || ip.type != InternetAddressType.IPv4) return false;
  final b = ip.rawAddress;
  return b[0] == 10 ||
      (b[0] == 172 && b[1] >= 16 && b[1] <= 31) ||
      (b[0] == 192 && b[1] == 168);
}

Future<void> _lanPrint(WebViewController controller, Map<String, dynamic> msg) async {
  final id = jsonEncode(msg['id']);
  try {
    final host = (msg['host'] as String).trim();
    final port = (msg['port'] as num?)?.toInt() ?? 9100;
    if (!_isPrivateLanHost(host)) {
      throw 'IP printer harus IP jaringan lokal (mis. 192.168.x.x).';
    }
    final bytes = base64Decode(msg['data'] as String);

    final socket = await Socket.connect(host, port, timeout: const Duration(seconds: 5));
    socket.add(bytes);
    await socket.flush();
    await socket.close();

    await controller.runJavaScript('window.club61LanPrintResult($id, true, null)');
  } catch (e) {
    final error = jsonEncode('Gagal kirim ke printer ${msg['host']}: $e');
    await controller.runJavaScript('window.club61LanPrintResult($id, false, $error)');
  }
}

// Saat membuat WebViewController:
controller
  ..addJavaScriptChannel(
    'Club61Print',
    onMessageReceived: (JavaScriptMessage message) {
      Map<String, dynamic>? msg;
      try {
        msg = jsonDecode(message.message) as Map<String, dynamic>;
      } catch (_) {}

      if (msg != null && msg['action'] == 'lan_print') {
        _lanPrint(controller, msg);
        return;
      }

      // ... penanganan pesan Club61Print yang sudah ada sebelumnya ...
    },
  )
  ..setNavigationDelegate(NavigationDelegate(
    onPageFinished: (_) {
      // Tanda ke web bahwa versi aplikasi ini sudah bisa kirim slip ke printer LAN.
      controller.runJavaScript('window.Club61Caps = Object.assign(window.Club61Caps || {}, { lanPrint: true });');
    },
    // ... onNavigationRequest untuk link rawbt: yang sudah ada tetap dipakai ...
  ));
```

Kalau `NavigationDelegate` sudah dipasang di aplikasi, cukup tambahkan baris `runJavaScript` di `onPageFinished` yang sudah ada. Jangan membuat delegate baru, karena delegate yang lama (penangkap link `rawbt:`) akan tertimpa.

Android: pastikan `AndroidManifest.xml` punya `<uses-permission android:name="android.permission.INTERNET" />` (biasanya sudah ada untuk WebView).

## Checklist pasang di venue

1. Printer LAN kitchen tersambung ke router yang sama dengan WiFi tablet kasir.
2. IP printer **tetap**: atur DHCP reservation di router, atau set IP statis dari menu printer. Cetak self-test printer untuk melihat IP-nya.
3. Isi IP dan port (umumnya 9100) di Admin → Menu F&B → Stasiun & Printer → Kitchen.
4. Di form menu, set **Stasiun Produksi**: makanan → Kitchen, minuman → *Kasir (tanpa slip)*.
5. Pasang APK versi baru di tablet kasir, lalu tes satu transaksi berisi menu Kitchen.

Menambah stasiun lagi (mis. Bar): tambah stasiun "Bar" dan IP printernya, lalu pindahkan menu minuman ke Bar. Tidak perlu ubah kode atau aplikasi.
