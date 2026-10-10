{{--
    Cetak struk thermal: club61PrintReceipt('#id-struk' | elemen). Gaya struk POS umum (ESB, Moka): 32 kolom, label kiri -
    nilai kanan, nominal rata kanan, TOTAL tebal dobel-tinggi.

    - Android (tablet / HP / aplikasi Flutter): struk dikirim sebagai TEKS ESC/POS ke aplikasi RawBT lewat link
      "rawbt:base64,…" dan dicetak dengan font bawaan printer — paling tajam, tanpa dialog cetak.
    - Selain Android (PC kasir): baris yang sama dicetak sebagai teks (bukan gambar) lewat iframe tersembunyi, kertas
      58mm x tinggi struk. Teks tetap tajam walaupun Chrome menyekala halaman; gambar yang diperbesar/dikecilkan Chrome
      jadi blur & belang.
    - Aplikasi Android Club61 (window.Club61Print): selalu RawBT; aplikasinya menangkap link itu dan mencetak langsung ke
      printer Bluetooth. Hanya di sini struk Bayar Otomatis dicetak otomatis begitu lunas (event 'club61-auto-print').
    - Paksa salah satu cara di perangkat tertentu: localStorage.setItem('club61_print_mode', 'rawbt' | 'browser').
--}}
@php($paper = \App\Support\ReceiptPaper::class)
<script>
    if (! window.club61PrintReceipt) {
        // Aplikasi Android Club61 (WebView) menyediakan window.Club61Print dan menangkap link RawBT dari navigasi halaman
        // utama → langsung dicetak ke printer Bluetooth 58mm tanpa popup.
        window.club61InApp = function () { return typeof window.Club61Print !== 'undefined'; };

        window.club61PrintMode = function () {
            if (window.club61InApp()) { return 'rawbt'; }
            try {
                const forced = localStorage.getItem('club61_print_mode');
                if (forced === 'rawbt' || forced === 'browser') { return forced; }
            } catch (e) {}
            return /Android/i.test(navigator.userAgent) ? 'rawbt' : 'browser';
        };

        /**
         * Struk di layar → daftar baris. Membaca tampilan yang sudah dirender (getComputedStyle), jadi berlaku untuk
         * semua struk tanpa menulis ulang isinya: baris flex "space-between" = label kiri + nominal kanan, text-align =
         * rata, font-weight >= 600 = tebal, ukuran huruf relatif terhadap isi struk, garis border = garis pemisah.
         */
        window.club61ReceiptLines = function (receipt) {
            // Kartu struk di layar (bingkainya bukan garis struk). Ukuran huruf isinya jadi patokan skala.
            const card = receipt.matches('[id^="printable-"], #fnbpos-receipt') ? receipt : (receipt.querySelector('[id^="printable-"], #fnbpos-receipt') || receipt);
            const baseSize = parseFloat(getComputedStyle(card).fontSize) || 12;
            const lines = [];

            const tidy = (s) => String(s || '').replace(/ /g, ' ').replace(/[ \t]+/g, ' ').trim();
            const hidden = (el, cs) => el.classList.contains('no-print') || cs.display === 'none' || cs.visibility === 'hidden';
            const isBold = (cs) => (parseInt(cs.fontWeight, 10) || 400) >= 600;
            const scaleOf = (cs) => Math.round((parseFloat(cs.fontSize) / baseSize) * 100) / 100;
            const alignOf = (cs) => (cs.textAlign === 'center' ? 'center' : ((cs.textAlign === 'right' || cs.textAlign === 'end') ? 'right' : 'left'));
            const hasBorder = (style, width) => style !== 'none' && style !== 'hidden' && parseFloat(width) > 0;
            const rule = () => { if (lines.length && lines[lines.length - 1].kind !== 'rule') { lines.push({ kind: 'rule' }); } };
            const text = (t, cs) => { t = tidy(t); if (t) { lines.push({ kind: 'text', text: t, align: alignOf(cs), bold: isBold(cs), scale: scaleOf(cs) }); } };

            const walk = (el) => {
                const cs = getComputedStyle(el);
                if (hidden(el, cs)) { return; }
                if (hasBorder(cs.borderTopStyle, cs.borderTopWidth)) { rule(); }

                const kids = Array.from(el.children).filter((k) => ! hidden(k, getComputedStyle(k)));
                const isRowFlex = cs.display.indexOf('flex') !== -1 && cs.flexDirection.indexOf('column') === -1;

                if (isRowFlex && kids.length >= 2 && cs.justifyContent.indexOf('space-between') !== -1) {
                    const last = kids[kids.length - 1];
                    const styles = kids.map((k) => getComputedStyle(k));
                    lines.push({
                        kind: 'row',
                        left: tidy(kids.slice(0, -1).map((k) => k.innerText || k.textContent).join(' ')),
                        right: tidy(last.innerText || last.textContent),
                        // Tebal hanya kalau labelnya tebal — nilai yang ditebalkan di layar (No. Order, Kasir) dicetak biasa.
                        bold: isBold(cs) || isBold(styles[0]),
                        scale: Math.max(scaleOf(cs), ...styles.map(scaleOf)),
                    });
                } else if (isRowFlex || kids.length === 0 || kids.every((k) => getComputedStyle(k).display.indexOf('inline') === 0 || k.tagName === 'BR')) {
                    String(el.innerText || el.textContent || '').split('\n').forEach((t) => text(t, cs));
                } else {
                    // Campuran: teks lepas di antara blok dicetak sebagai barisnya sendiri.
                    for (const node of el.childNodes) {
                        if (node.nodeType === Node.TEXT_NODE) { text(node.textContent, cs); }
                        else if (node.nodeType === Node.ELEMENT_NODE) { walk(node); }
                    }
                }

                if (hasBorder(cs.borderBottomStyle, cs.borderBottomWidth)) { rule(); }
            };

            for (const node of card.childNodes) {
                if (node.nodeType === Node.ELEMENT_NODE) { walk(node); }
                else if (node.nodeType === Node.TEXT_NODE) { text(node.textContent, getComputedStyle(card)); }
            }
            while (lines.length && lines[lines.length - 1].kind === 'rule') { lines.pop(); }
            return lines;
        };

        /**
         * Daftar baris → baris struk {{ $paper::COLUMNS }} kolom: { text, bold, big (dobel tinggi), align }. Satu tata letak untuk
         * RawBT dan PC, jadi hasil cetak keduanya sama.
         */
        window.club61ReceiptLayout = function (receipt, columns) {
            const COLS = columns || {{ $paper::COLUMNS }};
            const out = [];
            // Font printer hanya ASCII: titik tengah / tanda pisah → "-", huruf beraksen → huruf biasa, sisanya dibuang.
            const ascii = (s) => String(s || '').replace(/[•·–—]/g, '-').replace(/[“”]/g, '"').replace(/[‘’]/g, "'")
                .normalize('NFKD').replace(/[^\x20-\x7E]/g, '').replace(/ +/g, ' ').trim();
            const wrap = (s, width) => {
                const lines = [];
                let line = '';
                for (let word of s.split(' ')) {
                    while (word.length > width) { if (line) { lines.push(line); line = ''; } lines.push(word.slice(0, width)); word = word.slice(width); }
                    if (! word) { continue; }
                    if (! line) { line = word; } else if (line.length + 1 + word.length <= width) { line += ' ' + word; } else { lines.push(line); line = word; }
                }
                if (line) { lines.push(line); }
                return lines.length ? lines : [''];
            };
            const pad = (left, right) => left + ' '.repeat(Math.max(1, COLS - left.length - right.length)) + right;
            const push = (text, o) => out.push({ text, bold: !! o.bold, big: !! o.big, align: o.align || 'left' });

            // Label kiri + nominal kanan. Kalau tidak muat sebaris, nama barang turun dan nominal menempel di baris
            // terakhirnya ("x2              Rp 70.000"), bukan dipotong di tengah.
            const twoCol = (left, right, o) => {
                if (! right) { wrap(left, COLS).forEach((t) => push(t, o)); return; }
                if (left.length + 1 + right.length <= COLS) { push(pad(left, right), o); return; }
                let lines = wrap(left, COLS);
                if (lines[lines.length - 1].length + 1 + right.length > COLS) {
                    const words = lines[lines.length - 1].split(' ');
                    const tail = words.pop();
                    // Hanya jumlah barang ("x2") yang turun menemani nominal; label lain ("Masa Aktif") tetap utuh dan
                    // nilainya rata kanan di baris berikutnya.
                    if (! words.length || ! /^x\d+$/i.test(tail) || tail.length + 1 + right.length > COLS) {
                        lines.forEach((t) => push(t, o));
                        push(right, { ...o, align: 'right' });
                        return;
                    }
                    lines = [...lines.slice(0, -1), words.join(' '), tail];
                }
                lines.slice(0, -1).forEach((t) => push(t, o));
                push(pad(lines[lines.length - 1], right), o);
            };

            for (const l of window.club61ReceiptLines(receipt)) {
                if (l.kind === 'rule') { push('-'.repeat(COLS), {}); continue; }
                // Judul & baris TOTAL dicetak dobel tinggi.
                const big = (l.scale || 1) >= 1.15 || (l.kind === 'row' && /^total\b/i.test(l.left));
                const o = { bold: l.bold || big, big, align: l.align };
                if (l.kind === 'row') { twoCol(ascii(l.left), ascii(l.right), { ...o, align: 'left' }); continue; }
                const text = ascii(l.text);
                // "Kasir: Super Admin" → label kiri, nilai rata kanan (gaya struk POS).
                const m = l.align === 'left' && ! big ? text.match(/^([^:]{1,16}):\s+(.+)$/) : null;
                if (m && m[1].length + 2 + m[2].length <= COLS) { push(pad(m[1] + ':', m[2]), o); continue; }
                wrap(text, COLS).forEach((t) => push(t, o));
            }
            return out;
        };

        /** Baris struk → byte ESC/POS teks (font A bawaan printer, {{ $paper::COLUMNS }} kolom). */
        window.club61LayoutToEscPos = function (layout) {
            const ESC = 0x1B, GS = 0x1D;
            const align = { left: 0, center: 1, right: 2 };
            const out = [ESC, 0x40, ESC, 0x74, 0x00];
            for (const l of layout) {
                out.push(ESC, 0x61, align[l.align] || 0, ESC, 0x45, l.bold ? 1 : 0, GS, 0x21, l.big ? 0x01 : 0x00);
                for (const ch of l.text) { out.push(ch.charCodeAt(0) & 0x7F); }
                out.push(0x0A);
            }
            out.push(ESC, 0x61, 0, ESC, 0x45, 0, GS, 0x21, 0);
            out.push(ESC, 0x64, {{ $paper::FEED_LINES }});   // sisa kertas untuk disobek
            out.push(GS, 0x56, 0x42, 0x00);                    // potong (diabaikan printer tanpa pemotong)
            return out;
        };

        // el = satu struk atau beberapa (mis. slip beberapa stasiun): dikirim SEKALI, tiap slip diakhiri jarak sobek.
        window.club61PrintRawBt = function (el) {
            const bytes = [].concat(...(Array.isArray(el) ? el : [el]).map((one) => window.club61LayoutToEscPos(window.club61ReceiptLayout(one))));
            let binary = '';
            for (let i = 0; i < bytes.length; i += 4096) {
                binary += String.fromCharCode.apply(null, bytes.slice(i, i + 4096));
            }
            // Navigasi halaman utama (bukan window.open / iframe / fetch): hanya ini yang ditangkap aplikasi Club61 & RawBT.
            window.location.href = 'rawbt:base64,' + btoa(binary);
            // Di aplikasi Club61 link ditangkap aplikasinya sendiri (halaman tidak kehilangan fokus) — tidak perlu cek RawBT.
            if (window.club61InApp()) { return; }

            // RawBT terbuka = halaman kehilangan fokus. Kalau tidak (aplikasi RawBT belum terpasang, aplikasi Flutter belum
            // meneruskan link rawbt:, atau Chrome PC dalam mode emulasi Android), kasir diberi tahu — tidak gagal diam-diam.
            let launched = false;
            const mark = () => { launched = true; };
            window.addEventListener('blur', mark, { once: true });
            document.addEventListener('visibilitychange', mark, { once: true });
            setTimeout(() => {
                window.removeEventListener('blur', mark);
                document.removeEventListener('visibilitychange', mark);
                if (! launched && document.visibilityState === 'visible') { window.club61RawBtMissing(el); }
            }, 2000);
        };

        window.club61RawBtMissing = function (el) {
            const old = document.getElementById('club61-rawbt-missing');
            if (old) { old.remove(); }
            const box = document.createElement('div');
            box.id = 'club61-rawbt-missing';
            box.setAttribute('role', 'alert');
            box.style.cssText = 'position:fixed;left:50%;bottom:1.5rem;transform:translateX(-50%);z-index:100000;max-width:min(92vw,420px);'
                + 'background:#FFFFFF;border:1.5px solid #FCA5A5;border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,0.25);padding:0.9rem 1rem;'
                + 'font:600 13px/1.45 system-ui,sans-serif;color:#7F1D1D;';
            const msg = document.createElement('div');
            msg.textContent = 'Aplikasi RawBT tidak terbuka. Pastikan RawBT terpasang & printer sudah dipilih di RawBT.';
            const actions = document.createElement('div');
            actions.style.cssText = 'display:flex;gap:0.5rem;justify-content:flex-end;margin-top:0.6rem;';
            const button = (label, primary, onClick) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = label;
                b.style.cssText = 'padding:0.45rem 0.8rem;border-radius:9px;font-weight:700;font-size:12px;cursor:pointer;'
                    + (primary ? 'background:#7F1D1D;color:#FFFFFF;border:none;' : 'background:#FFFFFF;color:#7F1D1D;border:1px solid #FCA5A5;');
                b.addEventListener('click', onClick);
                return b;
            };
            actions.appendChild(button('Tutup', false, () => box.remove()));
            actions.appendChild(button('Cetak lewat browser', true, () => { box.remove(); window.club61PrintBrowser(el); }));
            box.appendChild(msg);
            box.appendChild(actions);
            document.body.appendChild(box);
            setTimeout(() => box.remove(), 15000);
        };

        /**
         * PC kasir: baris yang sama dicetak sebagai teks monospace (JetBrains Mono) lewat iframe tersembunyi,
         * {{ $paper::PC_COLUMNS }} karakter tepat selebar kepala print. Judul & TOTAL sedikit dipanjangkan ke atas (dobel tinggi di printer).
         */
        window.club61PrintBrowser = function (el) {
            // Beberapa slip → satu lembar panjang, dipisah jarak sobek.
            const gap = Array.from({ length: {{ $paper::FEED_LINES }} }, () => ({ text: '', bold: false, big: false, align: 'left' }));
            const layout = [].concat(...(Array.isArray(el) ? el : [el]).map((one, i) => (i ? gap : []).concat(window.club61ReceiptLayout(one, {{ $paper::PC_COLUMNS }}))));
            const lineMm = {{ $paper::LINE_MM }};
            const bigMm = lineMm * {{ $paper::BIG_STRETCH_Y }};
            const contentMm = layout.reduce((n, l) => n + (l.big ? bigMm : lineMm), 0) + {{ $paper::FEED_LINES }} * lineMm;
            const heightMm = Math.max({{ $paper::MIN_LENGTH_MM }}, Math.ceil(2 + contentMm));

            const old = document.getElementById('club61-receipt-frame');
            if (old) { old.remove(); }
            const frame = document.createElement('iframe');
            frame.id = 'club61-receipt-frame';
            frame.setAttribute('aria-hidden', 'true');
            frame.style.cssText = 'position:fixed;right:0;bottom:0;width:{{ $paper::PAPER_MM }}mm;height:0;border:0;opacity:0;pointer-events:none;';
            document.body.appendChild(frame);

            // Dokumen dibangun lewat DOM, bukan document.write berisi tag head/body/html: Livewire menyisipkan script-nya
            // sebelum tag penutup body PERTAMA di respons — kalau tag itu ada di string JS ini, script terpotong (error).
            const doc = frame.contentDocument;
            doc.open();
            doc.write('<!doctype html>');
            doc.close();
            const font = doc.createElement('link');
            font.rel = 'stylesheet';
            font.href = @js($paper::FONT_URL);
            doc.head.appendChild(font);
            const style = doc.createElement('style');
            style.textContent = '@page { size: {{ $paper::PAPER_MM }}mm ' + heightMm + 'mm; margin: 0; }'
                + ' html, body { margin: 0; padding: 0; background: #FFFFFF; }'
                + ' .r { width: {{ $paper::TEXT_MM }}mm; margin: 0 auto; padding-top: 2mm; color: #000000; font-family: ' + @js($paper::FONT_STACK) + '; font-weight: 400; }'
                + ' .l { height: ' + lineMm + 'mm; line-height: ' + lineMm + 'mm; white-space: pre; overflow: visible; }'
                + ' .g { height: ' + bigMm + 'mm; }'
                + ' .g > span { display: inline-block; transform: scaleY({{ $paper::BIG_STRETCH_Y }}); transform-origin: 0 0; }'
                + ' .b { font-weight: 700; }';
            doc.head.appendChild(style);
            const root = doc.createElement('div');
            root.className = 'r';
            for (const l of layout) {
                const row = doc.createElement('div');
                row.className = 'l' + (l.bold ? ' b' : '') + (l.big ? ' g' : '');
                row.style.textAlign = l.align;
                const span = doc.createElement('span');
                span.textContent = l.text || ' ';
                row.appendChild(span);
                root.appendChild(row);
            }
            doc.body.appendChild(root);

            const go = () => {
                // {{ $paper::PC_COLUMNS }} karakter tepat selebar area teks (diukur setelah font termuat; tanpa internet jatuh ke Consolas).
                const probe = doc.createElement('span');
                probe.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;font-size:20px;font-weight:700;';
                probe.textContent = 'M'.repeat({{ $paper::PC_COLUMNS }});
                root.appendChild(probe);
                root.style.fontSize = (20 * root.clientWidth / probe.getBoundingClientRect().width) + 'px';
                probe.remove();
                setTimeout(() => {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                    setTimeout(() => frame.remove(), 2000);
                }, 50);
            };
            const fontLoaded = new Promise((resolve) => {
                font.onload = resolve;
                font.onerror = resolve;
                setTimeout(resolve, 2000);
            }).then(() => Promise.all([
                doc.fonts.load("400 16px 'JetBrains Mono'"),
                doc.fonts.load("700 16px 'JetBrains Mono'"),
            ]).catch(() => null));
            Promise.race([fontLoaded, new Promise((r) => setTimeout(r, 3000))]).then(go);
        };

        window.club61PrintReceipt = function (source) {
            const el = typeof source === 'string' ? document.querySelector(source) : source;
            if (! el || (Array.isArray(el) && ! el.length)) { window.print(); return; }

            if (window.club61PrintMode() === 'rawbt') {
                window.club61PrintRawBt(el);
            } else {
                window.club61PrintBrowser(el);
            }
        };

        /** Struk dari HTML (partial yang sama dengan modal) → cetak tanpa membuka modal. Dirender di luar layar karena
         *  pembaca struk memakai tampilan yang sudah dirender (getComputedStyle). */
        window.club61PrintReceiptHtml = function (html) {
            const host = document.createElement('div');
            host.setAttribute('aria-hidden', 'true');
            host.style.cssText = 'position:fixed;left:-10000px;top:0;width:420px;pointer-events:none;';
            host.innerHTML = html;
            document.body.appendChild(host);
            // Beberapa slip ([data-print-slip], mis. slip stasiun yang gagal terkirim) dicetak berurutan dalam satu kali cetak.
            const slips = Array.from(host.querySelectorAll('[data-print-slip]'));
            window.club61PrintReceipt(slips.length ? slips : (host.querySelector('[id^="printable-"], #fnbpos-receipt') || host.firstElementChild));
            setTimeout(() => host.remove(), 20000);
        };

        window.club61Toast = function (message) {
            const box = document.createElement('div');
            box.setAttribute('role', 'status');
            box.style.cssText = 'position:fixed;left:50%;bottom:1.5rem;transform:translateX(-50%);z-index:100000;max-width:min(92vw,420px);'
                + 'background:#065F46;color:#FFFFFF;border-radius:12px;box-shadow:0 12px 30px rgba(0,0,0,0.25);padding:0.75rem 1.1rem;'
                + 'font:700 14px/1.4 system-ui,sans-serif;text-align:center;';
            box.textContent = message;
            document.body.appendChild(box);
            setTimeout(() => box.remove(), 3500);
        };

        /**
         * Bayar Otomatis lunas (dipastikan server: webhook / cek status Midtrans) → server mengirim 'club61-auto-print'
         * { key: order id, wireId, html: struk, next } HANYA ke layar kasir yang membuat transaksinya
         * (App\Livewire\Concerns\AutoPrintsReceipts). Hanya di aplikasi Club61; sekali per order: penanda di server
         * (orders.receipt_printed_at lewat claimAutoPrint) + localStorage. Refresh / buka ulang order tidak mencetak lagi.
         */
        window.addEventListener('club61-auto-print', async (event) => {
            const detail = event.detail || {};
            if (! detail.key || ! detail.html || ! window.club61InApp()) { return; }

            const mark = 'club61_auto_printed_' + detail.key;
            try { if (localStorage.getItem(mark)) { return; } } catch (e) {}

            // Livewire.find() mengembalikan $wire komponen kasirnya.
            const wire = window.Livewire && window.Livewire.find(detail.wireId);
            if (! wire) { return; }

            let claimed = false;
            try { claimed = await wire.claimAutoPrint(detail.key); } catch (e) { claimed = false; }
            if (! claimed) { return; }
            try { localStorage.setItem(mark, String(Date.now())); } catch (e) {}

            window.club61PrintReceiptHtml(detail.html);
            window.club61Toast('Pembayaran lunas — struk dicetak');
            // Siapkan layar untuk transaksi berikutnya (alur yang sama dengan tombol Transaksi Baru / Selesai).
            if (detail.next) { setTimeout(() => wire.call(detail.next), 400); }
        });

        /**
         * Slip pesanan ke printer LAN stasiun (Kitchen, dst.). Browser tidak bisa membuka koneksi ke IP printer, jadi
         * aplikasi Android Club61 yang mengirim: pesan { action: 'lan_print', id, host, port, data (ESC/POS base64) }
         * lewat window.Club61Print.postMessage, lalu aplikasinya memanggil window.club61LanPrintResult(id, ok, error).
         * Aplikasi yang sudah mendukung memasang window.Club61Caps = { lanPrint: true } (docs/FLUTTER_LAN_PRINT.md).
         */
        window.club61LanPending = {};
        window.club61LanPrintResult = function (id, ok, error) {
            const done = window.club61LanPending[id];
            if (done) { delete window.club61LanPending[id]; done({ ok: !! ok, error: error || null }); }
        };

        /** HTML slip → byte ESC/POS base64 (tata letak yang sama dengan struk RawBT). */
        window.club61HtmlToEscPosBase64 = function (html) {
            const host = document.createElement('div');
            host.setAttribute('aria-hidden', 'true');
            host.style.cssText = 'position:fixed;left:-10000px;top:0;width:420px;pointer-events:none;';
            host.innerHTML = html;
            document.body.appendChild(host);
            try {
                const slips = Array.from(host.querySelectorAll('[data-print-slip]'));
                const bytes = [].concat(...(slips.length ? slips : [host.firstElementChild]).map((one) => window.club61LayoutToEscPos(window.club61ReceiptLayout(one))));
                let binary = '';
                for (let i = 0; i < bytes.length; i += 4096) { binary += String.fromCharCode.apply(null, bytes.slice(i, i + 4096)); }
                return btoa(binary);
            } finally {
                host.remove();
            }
        };

        /** Satu slip → printer LAN stasiunnya. Selalu selesai dengan { ok, error } (tidak pernah menggantung). */
        window.club61SendToStation = function (job) {
            return new Promise((resolve) => {
                if (! job.host) { resolve({ ok: false, error: 'IP printer stasiun belum diatur (Menu F&B > Stasiun & Printer).' }); return; }
                if (! window.club61InApp()) { resolve({ ok: false, error: 'Kirim ke printer stasiun hanya bisa dari aplikasi Club61 di tablet kasir.' }); return; }
                if (! (window.Club61Caps && window.Club61Caps.lanPrint)) { resolve({ ok: false, error: 'Aplikasi Club61 belum versi terbaru (belum bisa kirim ke printer LAN).' }); return; }

                let data;
                try { data = window.club61HtmlToEscPosBase64(job.html); } catch (e) { resolve({ ok: false, error: 'Slip gagal disiapkan.' }); return; }

                const id = 'lan-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
                const timer = setTimeout(() => window.club61LanPrintResult(id, false, 'Printer ' + job.station_name + ' tidak merespons — cek printer menyala & satu WiFi dengan tablet.'), 10000);
                window.club61LanPending[id] = (result) => { clearTimeout(timer); resolve(result); };
                try {
                    window.Club61Print.postMessage(JSON.stringify({ action: 'lan_print', id, host: job.host, port: job.port || 9100, data }));
                } catch (e) {
                    window.club61LanPrintResult(id, false, 'Aplikasi Club61 menolak perintah cetak.');
                }
            });
        };

        /** Slip yang gagal terkirim → peringatan tetap di layar sampai ditutup: coba lagi / cetak di printer kasir. */
        window.club61StationFailed = function (failed, wire, queueNumber) {
            const old = document.getElementById('club61-station-failed');
            if (old) { old.remove(); }
            const box = document.createElement('div');
            box.id = 'club61-station-failed';
            box.setAttribute('role', 'alert');
            box.style.cssText = 'position:fixed;left:50%;bottom:1.5rem;transform:translateX(-50%);z-index:100001;width:min(92vw,440px);'
                + 'background:#FFFFFF;border:1.5px solid #FCA5A5;border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,0.25);padding:0.9rem 1rem;'
                + 'font:600 13px/1.45 system-ui,sans-serif;color:#7F1D1D;';
            const title = document.createElement('div');
            title.style.cssText = 'font-weight:800;font-size:14px;';
            title.textContent = 'Slip pesanan' + (queueNumber ? ' antrian ' + String(queueNumber).padStart(3, '0') : '') + ' BELUM tercetak';
            box.appendChild(title);
            failed.forEach(({ job, error }) => {
                const line = document.createElement('div');
                line.style.cssText = 'margin-top:0.3rem;';
                line.textContent = job.station_name + ': ' + error;
                box.appendChild(line);
            });
            const actions = document.createElement('div');
            actions.style.cssText = 'display:flex;gap:0.5rem;justify-content:flex-end;flex-wrap:wrap;margin-top:0.7rem;';
            const button = (label, primary, onClick) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = label;
                b.style.cssText = 'padding:0.45rem 0.8rem;border-radius:9px;font-weight:700;font-size:12px;cursor:pointer;'
                    + (primary ? 'background:#7F1D1D;color:#FFFFFF;border:none;' : 'background:#FFFFFF;color:#7F1D1D;border:1px solid #FCA5A5;');
                b.addEventListener('click', onClick);
                return b;
            };
            actions.appendChild(button('Tutup', false, () => box.remove()));
            actions.appendChild(button('Cetak di Printer Kasir', false, () => {
                box.remove();
                window.club61PrintReceiptHtml(failed.map(({ job }) => job.html).join(''));
            }));
            actions.appendChild(button('Coba Lagi', true, () => {
                box.remove();
                window.club61SendStationJobs(failed.map(({ job }) => job), wire, queueNumber);
            }));
            box.appendChild(actions);
            document.body.appendChild(box);
        };

        window.club61SendStationJobs = async function (jobs, wire, queueNumber) {
            const failed = [];
            for (const job of jobs) {
                const result = await window.club61SendToStation(job);
                try { if (wire) { await wire.reportStationPrint(job.ticket_id, result.ok, result.error); } } catch (e) {}
                if (! result.ok) { failed.push({ job, error: result.error || 'Gagal mengirim.' }); }
            }
            if (failed.length) {
                window.club61StationFailed(failed, wire, queueNumber);
            } else if (jobs.length) {
                window.club61Toast('Slip ' + jobs.map((job) => job.station_name).join(' & ') + ' terkirim');
            }
        };

        // Order F&B lunas / tombol "Kirim Ulang ke Stasiun" → { wireId, queueNumber, jobs: [{ ticket_id, station_name, host, port, html }] }.
        window.addEventListener('club61-station-print', (event) => {
            const detail = event.detail || {};
            if (! Array.isArray(detail.jobs) || ! detail.jobs.length) { return; }
            const wire = window.Livewire && window.Livewire.find(detail.wireId);
            window.club61SendStationJobs(detail.jobs, wire, detail.queueNumber);
        });
    }
</script>
