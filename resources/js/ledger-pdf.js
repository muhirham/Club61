/**
 * Export PDF Buku Transaksi — dirakit di BROWSER dengan tinypdf (bukan dompdf di server).
 *
 * Server (LedgerExportController, format=pdf) hanya mengirim data JSON: ringkasan, rekap per kategori, dan semua
 * transaksi. Dulu dompdf di server butuh ±200 MB untuk 400 baris → error 500 di batas memory_limit 128 MB.
 *
 * Catatan tinypdf: hanya font Helvetica (tanpa bold) dengan encoding WinAnsi; karakter di luar WinAnsi membuat
 * tinypdf melempar error, jadi SEMUA teks lewat safe(). Koordinat PDF dihitung dari kiri bawah halaman.
 * Dimuat hanya saat tombol Export → PDF ditekan (import dinamis dari BukuTransaksi::pdfClickHandler()).
 */
import { pdf, measureText } from 'tinypdf';

const PAGE_W = 841.89; // A4 landscape (pt)
const PAGE_H = 595.28;
const MARGIN_X = 26;
const MARGIN_TOP = 24;
const FOOTER_Y = 14;
const CONTENT_BOTTOM = 30;
const CONTENT_W = PAGE_W - MARGIN_X * 2;

const C = {
    text: '#1F170D',
    muted: '#7A643E',
    label: '#8C754E',
    head: '#5C410F',
    headBg: '#F5E5BE',
    headLine: '#D9BE84',
    rowLine: '#EFE4C8',
    zebra: '#FFFCF4',
    cardLine: '#E2D3AE',
    cardHi: '#FAF2DE',
    white: '#FFFFFF',
    neg: '#B42318',
};

// Tabel transaksi — urutan = LedgerExportController::pdfRow(). Lebar total = CONTENT_W.
const COLUMNS = [
    { title: 'Waktu', w: 55 },
    { title: 'No. Order', w: 88 },
    { title: 'Sumber', w: 56 },
    { title: 'Kategori', w: 76 },
    { title: 'Customer', w: 80 },
    { title: 'Kasir', w: 62 },
    { title: 'Metode', w: 70 },
    { title: 'Bersih', w: 52, money: true },
    { title: 'Layanan', w: 40, money: true },
    { title: 'Pajak', w: 44, money: true },
    { title: 'Total', w: 56, money: true },
];
COLUMNS.push({ title: 'Status', w: CONTENT_W - COLUMNS.reduce((sum, col) => sum + col.w, 0) });

const ROW_H = 11;
const HEAD_H = 13;
const CELL_PAD = 3;
const FONT = 7;

// Karakter yang sering muncul tapi tidak ada di WinAnsi → padanan ASCII.
const REPLACE = { '−': '-', '‐': '-', '‑': '-', ' ': ' ', ' ': ' ', ' ': ' ' };
const WIN_ANSI_EXTRA = new Set([8364, 8218, 402, 8222, 8230, 8224, 8225, 710, 8240, 352, 8249, 338, 381, 8216, 8217, 8220, 8221, 8226, 8211, 8212, 732, 8482, 353, 8250, 339, 382, 376]);

/** Teks aman untuk Helvetica/WinAnsi: aksen latin tetap, huruf lain diganti padanan dasarnya atau "?". */
export function safe(value) {
    let out = '';
    for (const ch of String(value ?? '')) {
        const mapped = REPLACE[ch] ?? ch;
        const code = mapped.codePointAt(0);
        if ((code >= 32 && code <= 126) || (code >= 160 && code <= 255) || WIN_ANSI_EXTRA.has(code)) {
            out += mapped;
            continue;
        }
        if (code < 32) {
            out += ' ';
            continue;
        }
        const base = mapped.normalize('NFKD').replace(/[̀-ͯ]/g, '');
        out += /^[\x20-\x7E]+$/.test(base) ? base : '?';
    }

    return out;
}

/** Potong teks supaya muat di lebar kolom (diakhiri "..."). */
function fit(text, size, maxWidth) {
    const value = safe(text);
    if (measureText(value, size) <= maxWidth) {
        return value;
    }
    let cut = value;
    while (cut.length > 0 && measureText(cut + '...', size) > maxWidth) {
        cut = cut.slice(0, -1);
    }

    return cut.length ? cut.trimEnd() + '...' : '';
}

function rupiah(value) {
    const n = Math.round(Number(value) || 0);
    const digits = Math.abs(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return (n < 0 ? '-Rp ' : 'Rp ') + digits;
}

function count(value) {
    return Math.round(Number(value) || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

/** Bingkai 1 pt: tinypdf hanya punya kotak isi, jadi garis tepi = kotak warna garis + kotak isi di dalamnya. */
function box(ctx, x, y, w, h, line, fill) {
    ctx.rect(x, y, w, h, line);
    ctx.rect(x + 0.75, y + 0.75, w - 1.5, h - 1.5, fill);
}

/** Logo PNG → JPEG lewat canvas (tinypdf hanya menerima JPEG). Gagal = PDF tanpa logo. */
async function loadLogo(url) {
    if (!url) {
        return null;
    }
    try {
        const img = new Image();
        img.decoding = 'async';
        img.src = url;
        await img.decode();
        const canvas = document.createElement('canvas');
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        const g = canvas.getContext('2d');
        g.fillStyle = '#FFFFFF';
        g.fillRect(0, 0, canvas.width, canvas.height);
        g.drawImage(img, 0, 0);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
        if (!blob) {
            return null;
        }

        return { bytes: new Uint8Array(await blob.arrayBuffer()), ratio: img.naturalWidth / img.naturalHeight };
    } catch {
        return null;
    }
}

/** Kepala halaman 1: judul, periode, kartu ringkasan, rekap kategori. Mengembalikan y (atas) tempat tabel dimulai. */
function drawFirstPageHeader(ctx, data, logo) {
    const s = data.summary || {};
    let y = PAGE_H - MARGIN_TOP;
    let titleX = MARGIN_X;

    if (logo) {
        const h = 32;
        const w = h * logo.ratio;
        ctx.image(logo.bytes, MARGIN_X, y - h, w, h);
        titleX += w + 8;
    }
    ctx.text('Riwayat Transaksi', titleX, y - 16, 15, { color: C.text });
    ctx.text(fit('Club 61 Padel & Wellness' + (data.address ? ' · ' + data.address : ''), 8, 420), titleX, y - 28, 8, { color: C.muted });

    const rightW = 300;
    const rightX = PAGE_W - MARGIN_X - rightW;
    ctx.text(fit(data.period, 10, rightW), rightX, y - 12, 10, { align: 'right', width: rightW, color: C.text });
    ctx.text('Tanggal = waktu uang diterima (WIB) · angka setelah refund', rightX, y - 23, 7, { align: 'right', width: rightW, color: C.muted });
    ctx.text('Pendapatan = penjualan bersih sebelum pajak', rightX, y - 32, 7, { align: 'right', width: rightW, color: C.muted });

    // Kartu ringkasan.
    y -= 44;
    const cardH = 44;
    const cardW = CONTENT_W / 5;
    const cards = [
        ['PENJUALAN BERSIH', rupiah(s.net), 'Harga item ' + rupiah(s.gross) + ' - diskon ' + rupiah(s.discount), false],
        ['BIAYA LAYANAN', rupiah(s.service), '', false],
        ['PAJAK TERKUMPUL', rupiah(s.tax), 'Bukan pendapatan', false],
        ['REFUND', rupiah(s.refunds), count(s.refunds_count) + ' refund · sudah dikurangkan', true],
        ['TOTAL UANG MASUK (BERSIH)', rupiah(s.money_net), '= Bersih + Layanan + Pajak', false],
    ];
    cards.forEach(([label, value, note, negative], i) => {
        const x = MARGIN_X + i * cardW;
        box(ctx, x, y - cardH, cardW, cardH, C.cardLine, i === 4 ? C.cardHi : C.white);
        ctx.text(fit(label, 6.5, cardW - 12), x + 6, y - 11, 6.5, { color: C.label });
        ctx.text(fit(value, 11, cardW - 12), x + 6, y - 25, 11, { color: negative ? C.neg : C.text });
        if (note) {
            ctx.text(fit(note, 6.5, cardW - 12), x + 6, y - 37, 6.5, { color: C.muted });
        }
    });
    y -= cardH + 10;

    const line = count(s.payments_count) + ' pembayaran (uang diterima ' + rupiah(s.money_in) + ') · Kuota member / voucher terpakai (non-tunai): '
        + rupiah(s.benefit) + ' · Selisih reschedule hangus: ' + rupiah(s.forfeited) + ' · Refund menunggu diproses: '
        + count(s.pending_refund_count) + ' (' + rupiah(s.pending_refund_amount) + ')';
    ctx.text(fit(line, 7, CONTENT_W), MARGIN_X, y, 7, { color: C.muted });
    y -= 16;

    // Rekap per kategori.
    ctx.text('REKAP PER KATEGORI', MARGIN_X, y, 8.5, { color: C.head });
    y -= 6;
    const recapW = CONTENT_W * 0.7;
    const recap = [
        { title: 'Kategori', w: recapW * 0.28 },
        { title: 'Pembayaran', w: recapW * 0.12, money: true },
        { title: 'Penjualan Bersih', w: recapW * 0.17, money: true },
        { title: 'Biaya Layanan', w: recapW * 0.14, money: true },
        { title: 'Pajak', w: recapW * 0.12, money: true },
        { title: 'Total (setelah refund)', w: recapW * 0.17, money: true },
    ];
    y = drawHeaderRow(ctx, recap, y);
    const categories = data.categories || [];
    if (!categories.length) {
        ctx.text('Tidak ada transaksi.', MARGIN_X + CELL_PAD, y - 8, FONT, { color: C.muted });
        y -= ROW_H;
    }
    categories.forEach((cat, i) => {
        y = drawRow(ctx, recap, [cat.name, count(cat.payments), rupiah(cat.net), rupiah(cat.service), rupiah(cat.tax), rupiah(cat.total)], y, i, false);
    });
    y -= 14;

    ctx.text('DAFTAR TRANSAKSI', MARGIN_X, y, 8.5, { color: C.head });
    y -= 4;
    const shown = (data.rows || []).length;
    if (data.total > shown) {
        y -= 8;
        ctx.text(fit('Hanya ' + count(shown) + ' dari ' + count(data.total) + ' transaksi terbaru yang ditampilkan (batas ' + count(data.limit)
            + ' baris). Ringkasan di atas tetap menghitung semuanya - gunakan export Excel untuk daftar lengkap.', 7, CONTENT_W), MARGIN_X, y, 7, { color: C.neg });
        y -= 4;
    }

    return y - 2;
}

function drawHeaderRow(ctx, columns, yTop) {
    const width = columns.reduce((sum, col) => sum + col.w, 0);
    ctx.rect(MARGIN_X, yTop - HEAD_H, width, HEAD_H, C.headBg);
    ctx.line(MARGIN_X, yTop - HEAD_H, MARGIN_X + width, yTop - HEAD_H, C.headLine, 0.75);
    let x = MARGIN_X;
    for (const col of columns) {
        const label = fit(col.title, 6.5, col.w - CELL_PAD * 2);
        ctx.text(label, x + CELL_PAD, yTop - 9, 6.5, col.money ? { align: 'right', width: col.w - CELL_PAD * 2, color: C.head } : { color: C.head });
        x += col.w;
    }

    return yTop - HEAD_H;
}

function drawRow(ctx, columns, values, yTop, index, highlightTotal) {
    const width = columns.reduce((sum, col) => sum + col.w, 0);
    if (index % 2 === 1) {
        ctx.rect(MARGIN_X, yTop - ROW_H, width, ROW_H, C.zebra);
    }
    let x = MARGIN_X;
    columns.forEach((col, i) => {
        const raw = values[i];
        const text = fit(raw, FONT, col.w - CELL_PAD * 2);
        const negative = highlightTotal && col.title === 'Total' && String(raw).startsWith('-');
        const opts = { color: negative ? C.neg : C.text };
        if (col.money) {
            Object.assign(opts, { align: 'right', width: col.w - CELL_PAD * 2 });
        }
        ctx.text(text, x + CELL_PAD, yTop - 8, FONT, opts);
        x += col.w;
    });
    ctx.line(MARGIN_X, yTop - ROW_H, MARGIN_X + width, yTop - ROW_H, C.rowLine, 0.5);

    return yTop - ROW_H;
}

function drawFooter(ctx, data, pageNo, pageCount) {
    const text = 'Club 61 · Riwayat Transaksi · dicetak ' + data.printed_at + ' oleh ' + data.printed_by + ' · halaman ' + pageNo + ' dari ' + pageCount;
    ctx.text(fit(text, 7, CONTENT_W), MARGIN_X, FOOTER_Y, 7, { color: C.label });
}

function transactionCells(row) {
    return row.map((value, i) => (COLUMNS[i]?.money ? rupiah(value) : value));
}

/** Rakit PDF (Uint8Array). Dua tahap: hitung pembagian halaman dulu (untuk "halaman X dari Y"), lalu gambar. */
export function buildLedgerPdf(data, logo = null) {
    const rows = data.rows || [];

    // Tahap 1 — berapa baris muat di halaman 1 (setelah kepala laporan, digambar ke "kanvas kosong") dan berikutnya.
    const firstTableTop = drawFirstPageHeader({ text() {}, rect() {}, line() {}, image() {} }, data, logo);
    const perFirst = Math.max(0, Math.floor((firstTableTop - HEAD_H - CONTENT_BOTTOM) / ROW_H));
    const perNext = Math.floor((PAGE_H - MARGIN_TOP - HEAD_H - CONTENT_BOTTOM) / ROW_H);
    const remaining = Math.max(0, rows.length - perFirst);
    const pageCount = 1 + Math.ceil(remaining / perNext);

    return renderPages(data, logo, rows, perFirst, perNext, pageCount);
}

function renderPages(data, logo, rows, perFirst, perNext, pageCount) {
    const doc = pdf();
    let cursor = 0;

    doc.page(PAGE_W, PAGE_H, (ctx) => {
        let y = drawFirstPageHeader(ctx, data, logo);
        if (!rows.length) {
            y = drawHeaderRow(ctx, COLUMNS, y);
            ctx.text('Tidak ada transaksi pada filter ini.', MARGIN_X + CELL_PAD, y - 8, FONT, { color: C.muted });
        } else if (perFirst > 0) {
            y = drawHeaderRow(ctx, COLUMNS, y);
            for (; cursor < Math.min(rows.length, perFirst); cursor++) {
                y = drawRow(ctx, COLUMNS, transactionCells(rows[cursor]), y, cursor, true);
            }
        }
        drawFooter(ctx, data, 1, pageCount);
    });

    for (let pageNo = 2; pageNo <= pageCount; pageNo++) {
        doc.page(PAGE_W, PAGE_H, (ctx) => {
            let y = drawHeaderRow(ctx, COLUMNS, PAGE_H - MARGIN_TOP);
            const end = Math.min(rows.length, cursor + perNext);
            for (; cursor < end; cursor++) {
                y = drawRow(ctx, COLUMNS, transactionCells(rows[cursor]), y, cursor, true);
            }
            drawFooter(ctx, data, pageNo, pageCount);
        });
    }

    return doc.build();
}

function notify(title, body, status) {
    if (window.FilamentNotification) {
        const n = new window.FilamentNotification().title(title);
        if (body) n.body(body);
        n[status]().send();
    } else if (status === 'danger') {
        window.alert(title + (body ? '\n' + body : ''));
    }
}

let busy = false;

/** Dipanggil tombol Export → PDF di Buku Transaksi: ambil data sesuai filter aktif, rakit PDF, unduh. */
export async function exportLedgerPdf(url) {
    if (busy) {
        return;
    }
    busy = true;
    notify('Menyiapkan PDF...', 'Mohon tunggu, laporan sedang disusun.', 'info');
    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) {
            let message = 'Server menolak permintaan (kode ' + response.status + ').';
            try {
                const body = await response.json();
                message = body.message || message;
            } catch {
                // respons bukan JSON
            }
            throw new Error(message);
        }
        const data = await response.json();
        const logo = await loadLogo(data.logo_url);
        const bytes = buildLedgerPdf(data, logo);

        const link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
        link.download = data.file_name || 'riwayat-transaksi.pdf';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 10000);
        notify('PDF siap', count((data.rows || []).length) + ' transaksi diunduh.', 'success');
    } catch (error) {
        notify('PDF gagal dibuat', error?.message || String(error), 'danger');
    } finally {
        busy = false;
    }
}

// Build Vite (entry aplikasi) membuang export modul, jadi fungsi dipasang ke window — dipanggil setelah import dinamis.
if (typeof window !== 'undefined') {
    window.Club61LedgerPdf = { exportLedgerPdf };
}
