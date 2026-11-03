{{--
    Pemutar Signature (Modul 26) — dipakai halaman TV (/signature/{token}) dan Preview admin. Kanvas tetap 1920×1080
    yang diskalakan ke layar, jadi tampilan di TV 96 inch sama dengan Preview. JavaScript sengaja ditulis tanpa fitur
    baru (optional chaining, dll.) supaya jalan di browser Smart TV versi lama. Semua teks dari data dipasang lewat
    textContent (bukan innerHTML). Semua teks bawaan layar berbahasa Inggris; teks yang diisi admin (nama layar, lapangan,
    running text, sponsor) tampil apa adanya.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;600;700;800;900&family=Source+Serif+4:wght@600;700&family=Montserrat:wght@500;700;800&family=Poppins:wght@500;700;800&family=Bebas+Neue&family=JetBrains+Mono:wght@500;700&display=swap">
    <style>
        :root { --bg: #1E120F; --panel: #2A1A16; --accent: #662721; --text: #F7F0DB; --font: 'Archivo', system-ui, sans-serif; }
        html, body { margin: 0; height: 100%; background: #000; overflow: hidden; cursor: none; }
        #sig-root { position: fixed; top: 0; right: 0; bottom: 0; left: 0; background: #000; }
        #stage { position: absolute; top: 50%; left: 50%; width: 1920px; height: 1080px; transform-origin: center center; background: var(--bg); color: var(--text); font-family: var(--font); overflow: hidden; }
        .zone { position: absolute; overflow: hidden; }
        .slide { position: absolute; top: 0; right: 0; bottom: 0; left: 0; opacity: 0; -webkit-transition: opacity 700ms ease; transition: opacity 700ms ease; display: flex; }
        .slide.on { opacity: 1; }
        /* Transisi antar konten (pengaturan template). Prefix -webkit- untuk browser Smart TV lama. */
        .tr-slide .slide { opacity: 1; -webkit-transform: translateX(100%); transform: translateX(100%); -webkit-transition: -webkit-transform 800ms cubic-bezier(.2,.8,.2,1); transition: transform 800ms cubic-bezier(.2,.8,.2,1); }
        .tr-slide .slide.on { -webkit-transform: translateX(0); transform: translateX(0); }
        .tr-slide .slide.off { -webkit-transform: translateX(-100%); transform: translateX(-100%); }
        .tr-zoom .slide { -webkit-transform: scale(1.08); transform: scale(1.08); -webkit-transition: opacity 700ms ease, -webkit-transform 900ms ease; transition: opacity 700ms ease, transform 900ms ease; }
        .tr-zoom .slide.on { -webkit-transform: scale(1); transform: scale(1); }
        .tr-zoom .slide.off { -webkit-transform: scale(.94); transform: scale(.94); }
        .tr-none .slide { -webkit-transition: none; transition: none; }
        .slide img, .slide video { width: 100%; height: 100%; object-fit: contain; background: #000; }
        /* YouTube: pemutar memenuhi slide; tidak bisa diklik (TV tanpa mouse, kontrol disembunyikan). */
        .yt { position: absolute; top: 0; right: 0; bottom: 0; left: 0; background: #000; pointer-events: none; }
        .yt iframe { width: 100%; height: 100%; border: 0; display: block; }
        /* Courtsee: halaman watch dibuka 1920×1080 lalu dipotong ke kotak pemutarnya. Bisa diklik — pemutarnya tidak
           berjalan otomatis, Play ditekan dengan pointer remote / mouse. */
        .cs { position: absolute; top: 0; right: 0; bottom: 0; left: 0; background: #000; }
        .cs-clip { position: absolute; overflow: hidden; background: #000; }
        .cs-clip iframe { position: absolute; top: 0; left: 0; border: 0; -webkit-transform-origin: 0 0; transform-origin: 0 0; }
        .muted { opacity: .7; }

        /* Monitoring lapangan */
        .mon { flex: 1; display: flex; flex-direction: column; padding: 44px 52px; gap: 28px; box-sizing: border-box; }
        .mon-head { display: flex; align-items: baseline; justify-content: space-between; }
        .mon-title { font-size: 54px; font-weight: 900; letter-spacing: .01em; }
        .mon-sub { font-size: 26px; opacity: .7; font-weight: 600; }
        .mon-grid { flex: 1; display: grid; gap: 24px; }
        .court { background: var(--panel); border-radius: 26px; padding: 30px 34px; display: flex; flex-direction: column; border: 3px solid transparent; position: relative; overflow: hidden; }
        .court.PLAYING { border-color: #10B981; }
        .court.WAITING { border-color: #F59E0B; }
        .court-top { display: flex; flex-direction: column; align-items: flex-start; gap: 12px; }
        .court-name { font-size: 40px; font-weight: 900; }
        .badge { font-size: 20px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; padding: 8px 18px; border-radius: 999px; white-space: nowrap; }
        .badge.PLAYING { background: #10B981; color: #04291D; }
        .badge.WAITING { background: #F59E0B; color: #3A2503; }
        .badge.FREE { background: rgba(255,255,255,.12); color: var(--text); }
        .court-mid { flex: 1; display: flex; flex-direction: column; justify-content: center; text-align: center; }
        .who { font-size: 62px; font-weight: 900; line-height: 1.05; }
        .time { font-size: 34px; font-weight: 600; opacity: .85; margin-top: 12px; }
        .cd-label { font-size: 22px; font-weight: 800; letter-spacing: .2em; text-transform: uppercase; opacity: .6; margin-top: 34px; }
        .countdown { font-family: 'JetBrains Mono', monospace; font-size: 84px; font-weight: 700; line-height: 1.05; color: #34D399; white-space: nowrap; }
        .mon-grid.cols-1 .countdown, .mon-grid.cols-2 .countdown { font-size: 128px; }
        .mon-grid.cols-1 .who, .mon-grid.cols-2 .who { font-size: 76px; }
        .court.WAITING .countdown { color: #FCD34D; }
        .free-big { font-size: 74px; font-weight: 900; color: #6EE7B7; }
        .free-cta { font-size: 32px; font-weight: 700; opacity: .85; margin-top: 12px; }
        .next { font-size: 26px; opacity: .85; border-top: 2px solid rgba(255,255,255,.12); padding-top: 16px; }
        .next b { display: block; font-size: 32px; margin-top: 4px; }

        /* Jadwal hari ini: timeline per lapangan (kolom) dengan blok booking bernama */
        .sch { flex: 1; padding: 40px 48px 30px; box-sizing: border-box; display: flex; flex-direction: column; gap: 22px; min-width: 0; }
        .sch-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; }
        .sch-head .mon-sub { margin-top: 6px; }
        .sch-stats { display: flex; gap: 14px; }
        .sch-stat { background: var(--panel); border-radius: 16px; padding: 10px 20px; text-align: center; min-width: 120px; }
        .sch-stat b { display: block; font-size: 40px; font-weight: 900; line-height: 1.05; }
        .sch-stat span { font-size: 17px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; opacity: .7; white-space: nowrap; }
        .sch-stat.play b { color: #34D399; }
        .sch-stat.free b { color: #6EE7B7; }
        .sch-board { flex: 1; display: flex; gap: 14px; min-height: 0; }
        .sch-gutter { width: 92px; display: flex; flex-direction: column; flex-shrink: 0; }
        .sch-gutter-body { flex: 1; position: relative; }
        .sch-hour { position: absolute; right: 6px; font-size: 22px; font-weight: 700; opacity: .6; transform: translateY(-50%); font-family: 'JetBrains Mono', monospace; }
        .sch-now-tag { position: absolute; right: 0; transform: translateY(-50%); background: #EF4444; color: #fff; font: 700 21px 'JetBrains Mono', monospace; padding: 4px 9px; border-radius: 8px; z-index: 3; }
        .sch-cols { flex: 1; display: grid; gap: 14px; min-width: 0; }
        .sch-col { display: flex; flex-direction: column; min-width: 0; }
        .sch-colhead, .sch-gutter-head { height: 108px; flex-shrink: 0; box-sizing: border-box; }
        .sch-colhead { background: var(--panel); border-radius: 18px 18px 0 0; padding: 12px 22px; display: flex; flex-direction: column; justify-content: center; border-bottom: 4px solid var(--accent); }
        .sch-colhead.live { border-bottom-color: #10B981; }
        /* Nama panjang: font mengecil (diatur JS) dan boleh 2 baris. */
        .sch-colname { font-size: 30px; font-weight: 900; line-height: 1.1; overflow: hidden; max-height: 2.2em; }
        .sch-colmeta { font-size: 19px; font-weight: 700; opacity: .7; margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sch-track { flex: 1; position: relative; background: rgba(255,255,255,.035); border-radius: 0 0 18px 18px; overflow: hidden; }
        .sch-line { position: absolute; left: 0; right: 0; height: 0; border-top: 2px solid rgba(255,255,255,.07); }
        .sch-past { position: absolute; left: 0; right: 0; top: 0; background: rgba(0,0,0,.28); }
        .sch-closed { position: absolute; left: 0; right: 0; background: rgba(0,0,0,.45); }
        .sch-now { position: absolute; left: 0; right: 0; height: 4px; margin-top: -2px; background: #EF4444; box-shadow: 0 0 14px rgba(239,68,68,.8); z-index: 2; }
        .sch-blk { position: absolute; left: 8px; right: 8px; box-sizing: border-box; border-radius: 14px; border-left: 8px solid rgba(255,255,255,.55); background: var(--accent); color: var(--text); padding: 6px 16px; overflow: hidden; display: flex; flex-direction: column; justify-content: center; gap: 4px; box-shadow: 0 6px 18px rgba(0,0,0,.25); }
        .sch-blk.PLAYING { background: #0F5A44; border-left-color: #34D399; }
        .sch-blk.WAITING { background: #6A4A10; border-left-color: #FCD34D; }
        .sch-blk.DONE { background: rgba(255,255,255,.07); border-left-color: rgba(255,255,255,.2); box-shadow: none; color: rgba(255,255,255,.5); }
        .sch-blk-name { font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.1; }
        .sch-blk-time { font-weight: 700; opacity: .85; white-space: nowrap; font-family: 'JetBrains Mono', monospace; }
        .sch-blk-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .sch-blk.lg .sch-blk-name { font-size: 34px; }
        .sch-blk.lg .sch-blk-time { font-size: 22px; }
        .sch-blk.wrap .sch-blk-name { white-space: normal; max-height: 2.2em; }
        .sch-blk.md { gap: 2px; }
        .sch-blk.md .sch-blk-name { font-size: 26px; }
        .sch-blk.md .sch-blk-time { font-size: 17px; }
        .sch-blk.md .sch-pill { font-size: 12px; padding: 2px 9px; }
        .sch-blk.sm { flex-direction: row; align-items: center; justify-content: space-between; gap: 12px; padding: 2px 12px; border-radius: 10px; }
        .sch-blk.sm .sch-blk-name { font-size: 21px; }
        .sch-blk.sm .sch-blk-time { font-size: 16px; }
        .sch-pill { font-size: 15px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; padding: 4px 11px; border-radius: 999px; white-space: nowrap; background: rgba(0,0,0,.28); }
        .sch-blk.PLAYING .sch-pill { background: #34D399; color: #04291D; }
        .sch-blk.WAITING .sch-pill { background: #FCD34D; color: #3A2503; }
        .sch-free { position: absolute; left: 8px; right: 8px; box-sizing: border-box; border-radius: 14px; border: 2px dashed rgba(110,231,183,.45); background: rgba(16,185,129,.07); color: #6EE7B7; display: flex; align-items: center; justify-content: center; gap: 12px; overflow: hidden; font-weight: 800; white-space: nowrap; }
        .sch-free span { opacity: .75; font-weight: 700; font-family: 'JetBrains Mono', monospace; }
        .legend { display: flex; gap: 30px; font-size: 21px; font-weight: 600; opacity: .85; padding-left: 106px; }
        .legend i { display: inline-block; width: 20px; height: 20px; border-radius: 6px; vertical-align: -3px; margin-right: 10px; box-sizing: border-box; }

        /* Sponsor & jam */
        .center-col { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 36px; padding: 40px; box-sizing: border-box; text-align: center; }
        .sp-label { font-size: 30px; letter-spacing: .3em; text-transform: uppercase; opacity: .7; font-weight: 700; }
        .sp-logo { max-width: 70%; max-height: 55%; object-fit: contain; }
        .sp-name { font-size: 64px; font-weight: 900; }
        .clock-time { font-family: 'JetBrains Mono', monospace; font-size: 260px; font-weight: 700; line-height: 1; }
        .clock-date { font-size: 54px; font-weight: 700; opacity: .85; }

        /* Panel samping */
        .side { position: absolute; top: 0; right: 0; bottom: 0; left: 0; background: var(--panel); display: flex; flex-direction: column; align-items: center; padding: 48px 28px; box-sizing: border-box; gap: 34px; text-align: center; }
        .side-logo { max-width: 78%; max-height: 190px; object-fit: contain; }
        .side-time { font-family: 'JetBrains Mono', monospace; font-size: 96px; font-weight: 700; line-height: 1; }
        .side-date { font-size: 28px; opacity: .8; font-weight: 600; margin-top: -14px; }
        .side-sp { margin-top: auto; width: 100%; display: flex; flex-direction: column; align-items: center; gap: 18px; }
        .side-sp img { max-width: 80%; max-height: 220px; object-fit: contain; }
        .side-sp .sp-name { font-size: 40px; }
        .side-sp .sp-label { font-size: 20px; }

        /* Running text */
        .ticker { position: absolute; top: 0; right: 0; bottom: 0; left: 0; display: flex; align-items: center; overflow: hidden; white-space: nowrap; }
        .ticker-track { position: absolute; left: 0; white-space: nowrap; will-change: transform; font-weight: 800; }
        .ticker-sep { opacity: .55; margin: 0 .9em; }

        /* Notifikasi, tutup, status */
        .toast { position: absolute; top: 40px; right: 40px; max-width: 900px; background: var(--accent); color: var(--text); border-radius: 22px; padding: 26px 34px; font-size: 38px; font-weight: 800; box-shadow: 0 30px 60px rgba(0,0,0,.45); transform: translateY(-260px); opacity: 0; transition: transform 600ms cubic-bezier(.2,.8,.2,1), opacity 400ms ease; z-index: 30; display: flex; gap: 22px; align-items: center; }
        .toast.on { transform: translateY(0); opacity: 1; }
        .toast-tag { font-size: 22px; letter-spacing: .12em; text-transform: uppercase; background: rgba(0,0,0,.25); padding: 6px 14px; border-radius: 999px; white-space: nowrap; }
        .closed { position: absolute; top: 0; right: 0; bottom: 0; left: 0; z-index: 40; background: var(--bg); display: none; flex-direction: column; align-items: center; justify-content: center; gap: 36px; text-align: center; }
        .closed.on { display: flex; }
        .closed img { max-width: 520px; max-height: 300px; object-fit: contain; }
        .closed-title { font-size: 72px; font-weight: 900; }
        .closed-sub { font-size: 40px; opacity: .8; }
        .status { position: absolute; left: 24px; bottom: 24px; z-index: 50; font: 700 22px system-ui, sans-serif; color: #FCA5A5; background: rgba(0,0,0,.6); padding: 8px 16px; border-radius: 10px; display: none; }
        .sound-hint { position: absolute; right: 24px; bottom: 24px; z-index: 50; font: 700 24px system-ui, sans-serif; color: #fff; background: rgba(0,0,0,.7); padding: 12px 20px; border-radius: 12px; display: none; }
        /* Tipis di tepi atas supaya tidak menutupi judul konten. */
        .preview-badge { position: absolute; left: 50%; top: 0; transform: translateX(-50%); z-index: 60; font: 800 15px system-ui, sans-serif; letter-spacing: .12em; color: #3A2503; background: #FCD34D; padding: 5px 16px; border-radius: 0 0 10px 10px; opacity: .92; }
        .empty { position: absolute; top: 0; right: 0; bottom: 0; left: 0; display: flex; align-items: center; justify-content: center; font-size: 48px; font-weight: 800; opacity: .7; text-align: center; padding: 80px; box-sizing: border-box; }
    </style>
</head>
<body>
<div id="sig-root"
     data-payload-url="{{ $payloadUrl }}"
     data-live-url="{{ $liveUrl }}"
     data-preview="{{ $isPreview ? '1' : '0' }}">
    <div id="stage"></div>
</div>
<script>window.SIG_INITIAL = @json($payload);</script>
{{-- Update realtime (Reverb): data live diambil begitu ada booking / check-in / tayangan baru. Tanpa Reverb → polling 12 dtk. --}}
@php($realtime = \App\Support\Realtime::client())
@if($realtime)
<script src="{{ asset('js/club61-live.js') }}?v={{ filemtime(public_path('js/club61-live.js')) }}"></script>
<script>window.SIG_REALTIME = @json($realtime);</script>
@endif
<script>
(function () {
    'use strict';

    var root = document.getElementById('sig-root');
    var stage = document.getElementById('stage');
    var payloadUrl = root.getAttribute('data-payload-url') || '';
    var liveUrl = root.getAttribute('data-live-url');
    var isPreview = root.getAttribute('data-preview') === '1';
    var POLL_MS = 12000;        // tanpa realtime / koneksi realtime putus
    var POLL_LIVE_MS = 60000;   // realtime tersambung: cukup untuk status per jam & heartbeat; perubahan datang lewat event

    var TRANSITIONS = { fade: 1, slide: 1, zoom: 1, none: 1 };
    var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    var state = {
        payload: window.SIG_INITIAL || null,
        version: window.SIG_INITIAL ? window.SIG_INITIAL.version : null,
        // Versi yang sedang tayang bisa diperbarui (template sama) → revision naik. Versi lama tanpa revision = 1.
        revision: window.SIG_INITIAL ? (window.SIG_INITIAL.revision || 1) : null,
        live: null,
        liveAt: 0,
        serverOffset: 0,
        soundUnlocked: false,
        seenEvents: {},
        firstLive: true,
        timers: [],
        widgets: [],
        toastQueue: [],
        toastBusy: false,
        soundMedia: null,
        tickerText: null
    };

    // ------------------------------------------------------------------ util
    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) { node.className = cls; }
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        return node;
    }
    function later(fn, ms) { var t = setTimeout(fn, ms); state.timers.push(t); return t; }
    function clearTimers() { for (var i = 0; i < state.timers.length; i++) { clearTimeout(state.timers[i]); } state.timers = []; }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function now() { return new Date(Date.now() + state.serverOffset); }
    function fmtCountdown(sec) {
        sec = Math.max(0, Math.floor(sec));
        var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
        return (h > 0 ? h + ':' + pad(m) : pad(m)) + ':' + pad(s);
    }
    function fitStage() {
        var scale = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
        stage.style.transform = 'translate(-50%, -50%) scale(' + scale + ')';
    }
    function getJson(url, ok, fail) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url + (url.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now(), true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.timeout = 10000;
        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                try { ok(JSON.parse(xhr.responseText)); } catch (e) { fail(); }
            } else { fail(); }
        };
        xhr.onerror = fail;
        xhr.ontimeout = fail;
        xhr.send();
    }

    // ------------------------------------------------------------------ tampilan
    function applyTheme(p) {
        var t = p.template.theme;
        stage.style.setProperty('--bg', t.background);
        stage.style.setProperty('--panel', t.panel);
        stage.style.setProperty('--accent', t.accent);
        stage.style.setProperty('--text', t.text);
        stage.style.setProperty('--font', "'" + t.font + "', system-ui, sans-serif");
    }

    function build() {
        clearTimers();
        if (state.ticker && state.ticker.anim) { clearInterval(state.ticker.anim); }
        state.ticker = null;
        state.widgets = [];
        // Pemutar YouTube lama dihentikan sebelum tampilan dibangun ulang (versi baru ditayangkan).
        var oldSlides = stage.querySelectorAll('.slide');
        for (var s = 0; s < oldSlides.length; s++) { dropSlide(oldSlides[s]); }
        state.soundMedia = null;
        state.tickerText = null;
        stage.innerHTML = '';

        var p = state.payload;
        if (!p || !p.template) {
            stage.appendChild(el('div', 'empty', isPreview ? 'Preview is empty' : 'Nothing is on screen yet. Publish a display from Admin → Signature.'));
            return;
        }
        applyTheme(p);

        var zones = p.template.zones || [];
        var transition = TRANSITIONS[p.template.theme.transition] ? p.template.theme.transition : 'fade';
        for (var i = 0; i < zones.length; i++) {
            var z = zones[i];
            var box = el('div', 'zone');
            box.style.left = z.x + '%';
            box.style.top = z.y + '%';
            box.style.width = z.w + '%';
            box.style.height = z.h + '%';
            stage.appendChild(box);
            if (z.kind === 'playlist') {
                box.className = 'zone tr-' + transition;
                startPlaylist(box, (p.playlists && p.playlists[z.key]) || [], zoneHasSound(z));
            }
            else if (z.kind === 'side_panel') { buildSidePanel(box); }
            else if (z.kind === 'ticker') { buildTicker(box); }
        }

        state.closed = el('div', 'closed');
        stage.appendChild(state.closed);
        state.toast = el('div', 'toast');
        stage.appendChild(state.toast);
        state.status = el('div', 'status');
        stage.appendChild(state.status);
        state.soundHint = el('div', 'sound-hint', 'Press OK on the remote to turn on sound');
        stage.appendChild(state.soundHint);
        if (isPreview) { stage.appendChild(el('div', 'preview-badge', 'PREVIEW — NOT LIVE YET')); }

        refreshSoundHint();
        renderClosed();
        renderWidgets();
    }

    // ------------------------------------------------------------------ playlist
    // zoneSound: hanya satu zona yang boleh bersuara (template custom bisa punya beberapa zona playlist).
    function startPlaylist(box, items, zoneSound) {
        // Playlist kosong → monitoring lapangan (mode bawaan TV).
        if (!items.length) { items = [{ type: 'MONITORING', duration: 3600 }]; }
        var index = -1;
        var current = null;
        var single = items.length === 1;

        function next() {
            index = (index + 1) % items.length;
            var item = items[index];
            var slide = el('div', 'slide');
            box.appendChild(slide);
            var advanceAfter = Math.max(3, item.duration || 10) * 1000;
            // Lanjut ke konten berikutnya hanya kalau slide ini masih yang tampil (timer lama tidak boleh melompati).
            var done = function () { if (current === slide && !single) { next(); } };

            if (item.type === 'IMAGE') {
                var img = el('img');
                img.src = item.url;
                img.alt = '';
                slide.appendChild(img);
            } else if (item.type === 'VIDEO') {
                var video = el('video');
                video.src = item.url;
                video.setAttribute('playsinline', '');
                video.preload = 'auto';
                video.volume = Math.max(0, Math.min(1, (item.volume || 80) / 100));
                video.muted = !(zoneSound && wantsSound(item));
                video.loop = single;
                slide.appendChild(video);
                slide._media = { kind: 'video', node: video, item: item, sound: zoneSound };
                if (zoneSound) { state.soundMedia = slide._media; }
                video.onended = done;
                video.onerror = function () { later(done, 1500); };
                var played = video.play();
                if (played && played['catch']) {
                    played['catch'](function () { video.muted = true; video.play(); });
                }
                advanceAfter = null; // video pindah saat selesai
                // Pengaman: video rusak / macet tidak boleh menahan playlist selamanya.
                later(function () { if (video.paused || video.readyState < 2) { done(); } }, 15000);
            } else if (item.type === 'YOUTUBE' && item.youtube_id) {
                playYoutube(slide, item, zoneSound, single, done);
                advanceAfter = null; // pindah saat videonya selesai…
                // …atau saat durasi maksimal tercapai (live streaming tidak pernah selesai), dan
                // pengaman: tidak mulai main dalam 15 detik (internet putus / video dihapus) → dilewati.
                later(done, Math.max(3, item.duration || 300) * 1000);
                later(function () { if (!slide._media || !slide._media.started) { done(); } }, 15000);
            } else if (item.type === 'COURTSEE' && item.courtsee_id) {
                playCourtsee(box, slide, item);
                advanceAfter = Math.max(3, item.duration || 300) * 1000; // live tidak pernah selesai → durasi maksimal
            } else {
                renderWidgetInto(slide, item.type);
            }

            setTimeout(function () { slide.className = 'slide on'; }, 30);
            if (current) {
                var old = current;
                old.className = 'slide off';
                setTimeout(function () { dropSlide(old); }, 900);
            }
            current = slide;

            if (advanceAfter && !single) { later(done, advanceAfter); }
        }
        next();
    }

    function dropSlide(slide) {
        var m = slide._media;
        if (m && m.kind === 'video') { m.node.pause(); m.node.removeAttribute('src'); m.node.load(); }
        if (m && m.kind === 'youtube') { m.dead = true; if (m.player && m.player.destroy) { try { m.player.destroy(); } catch (e) {} } }
        if (m && state.soundMedia === m) { state.soundMedia = null; }
        state.widgets = state.widgets.filter(function (w) { return w.node !== slide; });
        if (slide.parentNode) { slide.parentNode.removeChild(slide); }
    }

    // ------------------------------------------------------------------ Courtsee
    // Server hanya mengirim ID tayangan (UUID, sudah divalidasi); alamat halaman disusun di sini. Posisi pemutar di
    // halaman watch Courtsee selebar 1920 px (diukur 2 Nov 2026): kiri 329, atas 118, 1248×702. Kalau Courtsee mengubah
    // tampilannya, cukup sesuaikan angka ini.
    var COURTSEE = { pageW: 1920, pageH: 1080, left: 329, top: 118, w: 1248, h: 702 };
    function playCourtsee(box, slide, item) {
        var holder = el('div', 'cs');
        var clip = el('div', 'cs-clip');
        var frame = document.createElement('iframe');
        var zoneW = box.clientWidth || 1920;
        var zoneH = box.clientHeight || 1080;
        var scale = Math.min(zoneW / COURTSEE.w, zoneH / COURTSEE.h);
        clip.style.width = Math.round(COURTSEE.w * scale) + 'px';
        clip.style.height = Math.round(COURTSEE.h * scale) + 'px';
        clip.style.left = Math.round((zoneW - COURTSEE.w * scale) / 2) + 'px';
        clip.style.top = Math.round((zoneH - COURTSEE.h * scale) / 2) + 'px';
        frame.width = COURTSEE.pageW;
        frame.height = COURTSEE.pageH;
        frame.style.left = Math.round(-COURTSEE.left * scale) + 'px';
        frame.style.top = Math.round(-COURTSEE.top * scale) + 'px';
        frame.style.webkitTransform = 'scale(' + scale + ')';
        frame.style.transform = 'scale(' + scale + ')';
        frame.setAttribute('scrolling', 'no');
        frame.setAttribute('allow', 'autoplay; fullscreen');
        frame.setAttribute('referrerpolicy', 'no-referrer');
        // Halaman Courtsee tidak boleh membelokkan TV ke alamat lain (tanpa allow-top-navigation).
        frame.setAttribute('sandbox', 'allow-scripts allow-same-origin');
        frame.src = 'https://courtsee.com/watch/' + encodeURIComponent(item.courtsee_id);
        clip.appendChild(frame);
        holder.appendChild(clip);
        slide.appendChild(holder);
    }

    // ------------------------------------------------------------------ YouTube
    // IFrame API YouTube dimuat sekali, hanya kalau playlist berisi YouTube. Yang dikirim server hanya ID video
    // (11 karakter, sudah divalidasi) — alamat pemutar disusun di sini, dari domain youtube-nocookie.
    var yt = { loading: false, queue: [] };
    function withYoutube(cb) {
        if (window.YT && window.YT.Player) { cb(); return; }
        yt.queue.push(cb);
        if (yt.loading) { return; }
        yt.loading = true;
        var previous = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = function () {
            if (typeof previous === 'function') { try { previous(); } catch (e) {} }
            var queue = yt.queue;
            yt.queue = [];
            for (var i = 0; i < queue.length; i++) { queue[i](); }
        };
        var script = document.createElement('script');
        script.src = 'https://www.youtube.com/iframe_api';
        script.async = true;
        // Internet putus: slide YouTube dilewati oleh pengaman 15 detik; dicoba lagi di putaran berikutnya.
        script.onerror = function () { yt.loading = false; yt.queue = []; if (script.parentNode) { script.parentNode.removeChild(script); } };
        document.head.appendChild(script);
    }

    function playYoutube(slide, item, zoneSound, loop, done) {
        var holder = el('div', 'yt');
        var target = el('div');
        holder.appendChild(target);
        slide.appendChild(holder);
        var media = { kind: 'youtube', player: null, item: item, sound: zoneSound, started: false, dead: false };
        slide._media = media;
        if (zoneSound) { state.soundMedia = media; }

        withYoutube(function () {
            if (media.dead) { return; }
            media.player = new window.YT.Player(target, {
                host: 'https://www.youtube-nocookie.com',
                videoId: item.youtube_id,
                width: '100%',
                height: '100%',
                playerVars: { autoplay: 1, mute: 1, controls: 0, disablekb: 1, fs: 0, rel: 0, iv_load_policy: 3, playsinline: 1, cc_load_policy: 0, modestbranding: 1 },
                events: {
                    onReady: function (e) {
                        if (media.dead) { return; }
                        e.target.setVolume(Math.max(0, Math.min(100, item.volume || 80)));
                        // Mulai tanpa suara (aturan autoplay browser); suara dinyalakan setelah OK ditekan di remote.
                        if (zoneSound && wantsSound(item)) { e.target.unMute(); }
                        e.target.playVideo();
                    },
                    onStateChange: function (e) {
                        if (e.data === 1) { media.started = true; }
                        if (e.data === 0) {
                            if (loop) { e.target.seekTo(0); e.target.playVideo(); } else { done(); }
                        }
                    },
                    onError: function () { later(done, 1500); }
                }
            });
        });
    }

    // ------------------------------------------------------------------ widget
    function renderWidgetInto(slide, type) {
        var w = { node: slide, type: type };
        state.widgets.push(w);
        drawWidget(w);
    }
    function renderWidgets() { for (var i = 0; i < state.widgets.length; i++) { drawWidget(state.widgets[i]); } }

    function drawWidget(w) {
        var slide = w.node;
        slide.innerHTML = '';
        if (w.type === 'MONITORING') { drawMonitoring(slide); }
        else if (w.type === 'SCHEDULE') { drawSchedule(slide); }
        else if (w.type === 'SPONSOR') { drawSponsor(slide); }
        else if (w.type === 'CLOCK') { drawClock(slide); }
    }

    function drawMonitoring(slide) {
        var wrap = el('div', 'mon');
        var head = el('div', 'mon-head');
        head.appendChild(el('div', 'mon-title', 'Court Status'));
        head.appendChild(el('div', 'mon-sub', state.live ? 'Updated ' + timeText(new Date(state.liveAt + state.serverOffset)) : 'Loading…'));
        wrap.appendChild(head);
        var courts = (state.live && state.live.courts) || [];
        var cols = Math.min(Math.max(courts.length, 1), 3);
        var grid = el('div', 'mon-grid cols-' + cols);
        grid.style.gridTemplateColumns = 'repeat(' + cols + ', 1fr)';
        for (var i = 0; i < courts.length; i++) {
            var c = courts[i];
            var card = el('div', 'court ' + c.status);
            var top = el('div', 'court-top');
            top.appendChild(el('div', 'court-name', c.name));
            top.appendChild(el('div', 'badge ' + c.status, c.status === 'PLAYING' ? 'Playing' : (c.status === 'WAITING' ? 'Awaiting check-in' : 'Available')));
            card.appendChild(top);
            var mid = el('div', 'court-mid');
            if (c.current) {
                mid.appendChild(el('div', 'who', c.current.name ? c.current.name : 'Booked'));
                mid.appendChild(el('div', 'time', c.current.start + ' – ' + c.current.end));
                mid.appendChild(el('div', 'cd-label', 'Time left'));
                var cd = el('div', 'countdown', fmtCountdown(c.current.remaining_seconds));
                cd._remaining = c.current.remaining_seconds;
                mid.appendChild(cd);
            } else {
                mid.appendChild(el('div', 'free-big', 'Free'));
                mid.appendChild(el('div', 'free-cta', c.next ? 'Free until ' + c.next.start : 'Book now at the front desk'));
            }
            card.appendChild(mid);
            var next = el('div', 'next', c.next ? 'Next ' + c.next.start + ' – ' + c.next.end : 'Next');
            next.appendChild(el('b', '', c.next ? (c.next.name ? c.next.name : 'Booked') : 'No bookings yet'));
            card.appendChild(next);
            grid.appendChild(card);
        }
        if (!courts.length) { grid.appendChild(el('div', 'empty', state.live ? 'No active courts' : 'Loading…')); }
        wrap.appendChild(grid);
        slide.appendChild(wrap);
    }

    function minText(m) { return pad(Math.floor(m / 60) % 24) + ':' + pad(m % 60); }

    // Rentang kosong yang masih bisa dibooking (mulai jam penuh berikutnya), di sela booking satu lapangan.
    function freeGaps(court, from, to) {
        var gaps = [];
        var cursor = Math.max(from, court.open_minutes);
        var end = Math.min(to, court.close_minutes);
        var list = (court.bookings || []).slice().sort(function (a, b) { return a.start_minutes - b.start_minutes; });
        for (var i = 0; i < list.length; i++) {
            if (list[i].start_minutes > cursor) { gaps.push([cursor, Math.min(list[i].start_minutes, end)]); }
            cursor = Math.max(cursor, list[i].end_minutes);
        }
        if (cursor < end) { gaps.push([cursor, end]); }
        return gaps.filter(function (g) { return g[1] - g[0] >= 30; });
    }

    function drawSchedule(slide) {
        var live = state.live;
        var rows = (live && live.schedule) || [];
        var nowMin = live && typeof live.now_minutes === 'number' ? live.now_minutes + Math.floor((Date.now() - state.liveAt) / 60000) : -1;
        var wrap = el('div', 'sch');

        // Ringkasan di kanan judul.
        var playing = 0, upcoming = 0, freeHours = 0;
        var nextHour = Math.ceil(Math.max(nowMin, 0) / 60) * 60;
        for (var r = 0; r < rows.length; r++) {
            for (var b = 0; b < (rows[r].bookings || []).length; b++) {
                var st = rows[r].bookings[b].status;
                if (st === 'PLAYING' || st === 'WAITING') { playing++; } else if (st === 'UPCOMING') { upcoming++; }
            }
            var g = freeGaps(rows[r], nextHour, 24 * 60);
            for (var k = 0; k < g.length; k++) { freeHours += Math.floor((g[k][1] - g[k][0]) / 60); }
        }
        var head = el('div', 'sch-head');
        var titles = el('div');
        titles.appendChild(el('div', 'mon-title', "Today's Schedule"));
        titles.appendChild(el('div', 'mon-sub', dateText(now())));
        head.appendChild(titles);
        var stats = el('div', 'sch-stats');
        [['play', playing, 'On court'], ['', upcoming, 'Upcoming'], ['free', freeHours, 'Free hours']].forEach(function (s) {
            var box = el('div', 'sch-stat ' + s[0]);
            box.appendChild(el('b', '', s[1]));
            box.appendChild(el('span', '', s[2]));
            stats.appendChild(box);
        });
        head.appendChild(stats);
        wrap.appendChild(head);

        if (!rows.length) {
            wrap.appendChild(el('div', 'empty', live ? 'No active courts' : 'Loading schedule…'));
            slide.appendChild(wrap);
            return;
        }

        // Rentang waktu: dari 1 jam sebelum sekarang sampai tutup (jam lewat tidak memakan tempat). Setelah tutup → seharian.
        var open = 24 * 60, close = 0;
        for (var c = 0; c < rows.length; c++) { open = Math.min(open, rows[c].open_minutes); close = Math.max(close, rows[c].close_minutes); }
        var from = Math.max(open, Math.floor(nowMin / 60) * 60 - 60);
        if (nowMin < 0 || from >= close - 60) { from = open; }
        var span = Math.max(60, close - from);
        function pct(m) { return ((Math.min(Math.max(m, from), close) - from) / span * 100) + '%'; }

        var board = el('div', 'sch-board');
        var gutter = el('div', 'sch-gutter');
        gutter.appendChild(el('div', 'sch-gutter-head'));
        var gutterBody = el('div', 'sch-gutter-body');
        gutter.appendChild(gutterBody);
        board.appendChild(gutter);
        var cols = el('div', 'sch-cols');
        cols.style.gridTemplateColumns = 'repeat(' + rows.length + ', minmax(0, 1fr))';
        board.appendChild(cols);
        wrap.appendChild(board);

        var legend = el('div', 'legend');
        [['#34D399', 'Playing'], ['#FCD34D', 'Awaiting check-in'], ['var(--accent)', 'Upcoming'], ['', 'Available'], ['rgba(255,255,255,.2)', 'Finished']].forEach(function (l) {
            var item = el('span', '', l[1]);
            var dot = el('i');
            if (l[0]) { dot.style.background = l[0]; } else { dot.style.border = '2px dashed #6EE7B7'; }
            item.insertBefore(dot, item.firstChild);
            legend.appendChild(item);
        });
        wrap.appendChild(legend);
        slide.appendChild(wrap);

        var tracks = [];
        for (var i = 0; i < rows.length; i++) {
            var court = rows[i];
            var col = el('div', 'sch-col');
            var list = court.bookings || [];
            var active = null, remaining = 0;
            for (var j = 0; j < list.length; j++) {
                if (list[j].status === 'PLAYING' || list[j].status === 'WAITING') { active = list[j]; }
                if (list[j].status !== 'DONE') { remaining++; }
            }
            var ch = el('div', 'sch-colhead' + (active ? ' live' : ''));
            var cname = el('div', 'sch-colname', court.name);
            if (court.name.length > 16) { cname.style.fontSize = Math.max(22, Math.round(30 * 16 / court.name.length)) + 'px'; }
            ch.appendChild(cname);
            ch.appendChild(el('div', 'sch-colmeta', active ? 'Playing until ' + active.end : (remaining ? remaining + (remaining === 1 ? ' more booking today' : ' more bookings today') : 'No more bookings today')));
            col.appendChild(ch);
            var track = el('div', 'sch-track');
            col.appendChild(track);
            cols.appendChild(col);
            tracks.push(track);
        }

        // Tinggi 1 jam dalam piksel → menentukan ukuran tulisan blok & kerapatan label jam.
        var hourPx = (tracks[0].offsetHeight || 700) / (span / 60);
        var labelStep = hourPx < 34 ? 120 : 60;
        for (var m = Math.ceil(from / 60) * 60; m <= close; m += 60) {
            for (var t = 0; t < tracks.length; t++) {
                var line = el('div', 'sch-line');
                line.style.top = pct(m);
                tracks[t].appendChild(line);
            }
            // Label jam yang terlalu dekat dengan penanda "sekarang" disembunyikan supaya tidak bertumpuk.
            if ((m - from) % labelStep === 0 && Math.abs(m - nowMin) / 60 * hourPx > 30) {
                var lab = el('div', 'sch-hour', minText(m));
                lab.style.top = pct(m);
                gutterBody.appendChild(lab);
            }
        }

        for (var x = 0; x < rows.length; x++) {
            var tr = tracks[x];
            var ct = rows[x];
            if (ct.open_minutes > from) { var co = el('div', 'sch-closed'); co.style.top = '0'; co.style.height = pct(ct.open_minutes); tr.appendChild(co); }
            if (ct.close_minutes < close) { var cc = el('div', 'sch-closed'); cc.style.top = pct(ct.close_minutes); cc.style.bottom = '0'; tr.appendChild(cc); }
            if (nowMin > from) { var past = el('div', 'sch-past'); past.style.height = pct(nowMin); tr.appendChild(past); }

            var gaps = freeGaps(ct, Math.max(nextHour, from), close);
            for (var q = 0; q < gaps.length; q++) {
                var fb = el('div', 'sch-free', 'Available');
                var fh = (gaps[q][1] - gaps[q][0]) / 60 * hourPx;
                fb.style.top = 'calc(' + pct(gaps[q][0]) + ' + 4px)';
                fb.style.height = Math.max(fh - 8, 10) + 'px';
                fb.style.fontSize = (fh >= 56 ? 26 : 19) + 'px';
                fb.appendChild(el('span', '', minText(gaps[q][0]) + ' – ' + minText(gaps[q][1])));
                tr.appendChild(fb);
            }

            for (var y = 0; y < (ct.bookings || []).length; y++) {
                var bk = ct.bookings[y];
                if (bk.end_minutes <= from) { continue; }
                var bh = (Math.min(bk.end_minutes, close) - Math.max(bk.start_minutes, from)) / 60 * hourPx - 8;
                var size = bh >= 96 ? 'lg' : (bh >= 58 ? 'md' : 'sm');
                var blk = el('div', 'sch-blk ' + bk.status + ' ' + size + (bh >= 120 ? ' wrap' : ''));
                blk.style.top = 'calc(' + pct(bk.start_minutes) + ' + 4px)';
                blk.style.height = Math.max(bh, 18) + 'px';
                var name = el('div', 'sch-blk-name', bk.name ? bk.name : 'Booked');
                var time = el('div', 'sch-blk-time', bk.start + ' – ' + bk.end);
                if (size !== 'sm') {
                    var top = el('div', 'sch-blk-top');
                    top.appendChild(time);
                    var pill = { PLAYING: 'Playing', WAITING: 'Waiting', DONE: 'Done' }[bk.status];
                    if (pill) { top.appendChild(el('span', 'sch-pill', pill)); }
                    blk.appendChild(top);
                    blk.appendChild(name);
                } else {
                    blk.appendChild(name);
                    blk.appendChild(time);
                }
                tr.appendChild(blk);
            }

            if (nowMin >= from && nowMin <= close) { var nl = el('div', 'sch-now'); nl.style.top = pct(nowMin); tr.appendChild(nl); }
        }
        if (nowMin >= from && nowMin <= close) {
            var tag = el('div', 'sch-now-tag', minText(nowMin));
            tag.style.top = pct(nowMin);
            gutterBody.appendChild(tag);
        }
    }

    function drawSponsor(slide) {
        var col = el('div', 'center-col');
        var sponsors = (state.payload && state.payload.sponsors) || [];
        col.appendChild(el('div', 'sp-label', 'Supported by'));
        if (!sponsors.length) {
            col.appendChild(el('div', 'sp-name', 'Club 61 Padel & Wellness'));
        } else {
            var s = sponsors[Math.floor(Date.now() / 6000) % sponsors.length];
            if (s.logo_url) { var img = el('img', 'sp-logo'); img.src = s.logo_url; img.alt = ''; col.appendChild(img); }
            col.appendChild(el('div', 'sp-name', s.name));
        }
        slide.appendChild(col);
    }

    function drawClock(slide) {
        var col = el('div', 'center-col');
        var t = el('div', 'clock-time', timeText(now()));
        t._clock = true;
        col.appendChild(t);
        col.appendChild(el('div', 'clock-date', dateText(now())));
        slide.appendChild(col);
    }

    function timeText(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
    function dateText(d) { return DAYS[d.getDay()] + ', ' + d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear(); }

    // ------------------------------------------------------------------ panel samping
    function buildSidePanel(box) {
        var side = el('div', 'side');
        var logo = el('img', 'side-logo');
        logo.src = state.payload.template.logo_url;
        logo.alt = '';
        side.appendChild(logo);
        var time = el('div', 'side-time', timeText(now()));
        time._clock = true;
        side.appendChild(time);
        var date = el('div', 'side-date', dateText(now()));
        side.appendChild(date);
        var sp = el('div', 'side-sp');
        side.appendChild(sp);
        box.appendChild(side);

        var sponsors = state.payload.sponsors || [];
        var i = 0;
        function rotate() {
            sp.innerHTML = '';
            date.textContent = dateText(now());
            if (!sponsors.length) { return; }
            var s = sponsors[i % sponsors.length];
            sp.appendChild(el('div', 'sp-label', 'Supported by'));
            if (s.logo_url) { var img = el('img'); img.src = s.logo_url; img.alt = ''; sp.appendChild(img); }
            sp.appendChild(el('div', 'sp-name', s.name));
            i++;
            later(rotate, Math.max(3, s.duration || 8) * 1000);
        }
        rotate();
    }

    // ------------------------------------------------------------------ running text
    function buildTicker(box) {
        var cfg = state.payload.template.ticker;
        box.style.background = cfg.background;
        box.style.color = cfg.color;
        var wrap = el('div', 'ticker');
        var track = el('div', 'ticker-track');
        track.style.fontFamily = "'" + cfg.font + "', system-ui, sans-serif";
        track.style.fontSize = (cfg.font_size || 44) + 'px';
        wrap.appendChild(track);
        box.appendChild(wrap);
        state.ticker = { box: box, track: track, cfg: cfg, anim: null };
        renderTicker(true);
    }

    function tickerItems() {
        var items = [];
        if (state.live && state.live.ticker) { items = state.live.ticker; }
        else if (state.payload && state.payload.running_texts) {
            items = state.payload.running_texts.filter(function (t) { return t.kind === 'MANUAL'; }).map(function (t) { return t.text; });
        }
        return items.length ? items : ['Welcome to Club 61 Padel & Wellness'];
    }

    function renderTicker(force) {
        var t = state.ticker;
        if (!t) { return; }
        var items = tickerItems();
        var joined = items.join('\u0001');
        if (!force && joined === state.tickerText) { return; }
        // Ganti teks hanya di awal putaran supaya tidak meloncat di tengah layar.
        if (!force && t.running) { t.pending = true; return; }
        state.tickerText = joined;
        t.track.innerHTML = '';
        for (var i = 0; i < items.length; i++) {
            if (i > 0) { t.track.appendChild(el('span', 'ticker-sep', t.cfg.separator || '•')); }
            t.track.appendChild(el('span', '', items[i]));
        }
        startTickerLoop();
    }

    function startTickerLoop() {
        var t = state.ticker;
        var boxWidth = t.box.offsetWidth || 1920;
        var width = t.track.offsetWidth;
        var speed = Math.max(20, t.cfg.speed || 120);
        var toLeft = (t.cfg.direction || 'left') !== 'right';
        var from = toLeft ? boxWidth : -width;
        var to = toLeft ? -width : boxWidth;
        var duration = (boxWidth + width) / speed * 1000;
        var start = Date.now();
        t.running = true;
        // Timer biasa (±30 fps), bukan requestAnimationFrame: sebagian browser TV memperlambat / menghentikan
        // requestAnimationFrame, sedangkan timer tetap jalan.
        if (t.anim) { clearInterval(t.anim); }
        function step() {
            var p = Math.min(1, (Date.now() - start) / duration);
            t.track.style.transform = 'translateX(' + Math.round(from + (to - from) * p) + 'px)';
            if (p < 1) { return; }
            clearInterval(t.anim);
            t.anim = null;
            t.running = false;
            if (t.pending) { t.pending = false; renderTicker(true); } else { startTickerLoop(); }
        }
        step();
        t.anim = setInterval(step, 33);
    }

    // ------------------------------------------------------------------ notifikasi
    function handleEvents(events) {
        for (var i = events.length - 1; i >= 0; i--) {
            var e = events[i];
            if (state.seenEvents[e.id]) { continue; }
            state.seenEvents[e.id] = true;
            // Saat TV baru menyala, notifikasi lama tidak ditumpuk sekaligus.
            if (!state.firstLive && e.age_seconds <= 60) { state.toastQueue.push(e); }
        }
        state.firstLive = false;
        nextToast();
    }
    function nextToast() {
        if (state.toastBusy || !state.toastQueue.length || !state.toast) { return; }
        var e = state.toastQueue.shift();
        state.toastBusy = true;
        state.toast.innerHTML = '';
        state.toast.appendChild(el('span', 'toast-tag', e.type === 'CHECKIN' ? 'Check-in' : 'New booking'));
        state.toast.appendChild(el('span', '', e.text));
        state.toast.className = 'toast on';
        setTimeout(function () {
            state.toast.className = 'toast';
            setTimeout(function () { state.toastBusy = false; nextToast(); }, 700);
        }, 8000);
    }

    // ------------------------------------------------------------------ suara
    // Ada video (MP4 / YouTube) bersuara di zona yang boleh bersuara?
    function needsSound() {
        var p = state.payload;
        if (!p || !p.screen.sound_enabled) { return false; }
        var zones = (p.template && p.template.zones) || [];
        for (var z = 0; z < zones.length; z++) {
            if (zones[z].kind !== 'playlist' || !zoneHasSound(zones[z])) { continue; }
            var items = (p.playlists && p.playlists[zones[z].key]) || [];
            for (var i = 0; i < items.length; i++) { if ((items[i].type === 'VIDEO' || items[i].type === 'YOUTUBE') && items[i].sound) { return true; } }
        }
        return false;
    }
    // Template lama / preset tidak punya pengaturan suara per zona → zona playlist pertama yang bersuara.
    function zoneHasSound(zone) {
        if (typeof zone.sound === 'boolean') { return zone.sound; }
        var zones = state.payload.template.zones || [];
        for (var i = 0; i < zones.length; i++) { if (zones[i].kind === 'playlist') { return zones[i] === zone; } }
        return false;
    }
    function wantsSound(item) { return !!(state.soundUnlocked && state.payload.screen.sound_enabled && item.sound); }
    // Layar penuh: browser hanya mengizinkan setelah ada tombol yang ditekan (OK di remote) — dicoba di setiap tekan
    // sampai berhasil, termasuk setelah halaman dimuat ulang. Preview admin tidak pernah dibuat layar penuh.
    function fullscreenRequest() {
        var root = document.documentElement;
        return root.requestFullscreen || root.webkitRequestFullscreen || root.mozRequestFullScreen || root.msRequestFullscreen || null;
    }
    function isFullscreen() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);
    }
    function needsFullscreen() { return !isPreview && fullscreenRequest() !== null && !isFullscreen(); }
    function enterFullscreen() {
        if (!needsFullscreen()) { return; }
        try {
            var result = fullscreenRequest().call(document.documentElement);
            if (result && typeof result.then === 'function') { result.then(null, function () {}); }
        } catch (e) { /* browser menolak — dicoba lagi di tekan berikutnya */ }
    }
    function refreshSoundHint() {
        if (!state.soundHint) { return; }
        var sound = needsSound() && !state.soundUnlocked;
        var full = needsFullscreen();
        state.soundHint.textContent = sound && full ? 'Press OK on the remote for full screen & sound'
            : (full ? 'Press OK on the remote for full screen' : 'Press OK on the remote to turn on sound');
        state.soundHint.style.display = sound || full ? 'block' : 'none';
    }
    function unlockSound() {
        if (state.soundUnlocked) { return; }
        state.soundUnlocked = true;
        var m = state.soundMedia;
        if (m && wantsSound(m.item)) {
            if (m.kind === 'video') { m.node.muted = false; m.node.play(); }
            if (m.kind === 'youtube' && m.player && m.player.unMute) { m.player.unMute(); }
        }
        refreshSoundHint();
    }
    document.addEventListener('keydown', unlockSound);
    document.addEventListener('click', unlockSound);
    document.addEventListener('touchstart', unlockSound);
    document.addEventListener('keydown', enterFullscreen);
    document.addEventListener('click', enterFullscreen);
    document.addEventListener('touchstart', enterFullscreen);
    document.addEventListener('fullscreenchange', refreshSoundHint);
    document.addEventListener('webkitfullscreenchange', refreshSoundHint);

    // ------------------------------------------------------------------ jam tutup
    function renderClosed() {
        if (!state.closed || !state.payload) { return; }
        var open = !state.live || state.live.is_open !== false;
        if (open) { state.closed.className = 'closed'; return; }
        state.closed.innerHTML = '';
        var logo = el('img');
        logo.src = state.payload.template.logo_url;
        logo.alt = '';
        state.closed.appendChild(logo);
        state.closed.appendChild(el('div', 'closed-title', 'See you tomorrow!'));
        state.closed.appendChild(el('div', 'closed-sub', 'Open daily ' + state.payload.hours.open + ' – ' + state.payload.hours.close));
        state.closed.className = 'closed on';
    }

    // ------------------------------------------------------------------ polling
    function pollLive() {
        if (!liveUrl) { return; }
        getJson(liveUrl, function (data) {
            state.live = data;
            state.liveAt = Date.now();
            if (data.server_time) { state.serverOffset = new Date(data.server_time).getTime() - Date.now(); }
            if (state.status) { state.status.style.display = 'none'; }

            if (!isPreview && (data.version !== state.version || (data.version !== null && (data.revision || 1) !== state.revision))) {
                loadPayload();
            } else {
                renderWidgets();
                renderTicker(false);
                renderClosed();
                handleEvents(data.events || []);
            }
            pollDone();
        }, function () {
            if (state.status && state.liveAt) {
                state.status.textContent = 'Connection lost — last update ' + timeText(new Date(state.liveAt + state.serverOffset));
                state.status.style.display = 'block';
            }
            pollDone();
        });
    }

    // Satu polling dalam satu waktu. Event yang datang saat polling berjalan → diulang sekali sesudahnya.
    var poll = { running: false, again: false, timer: null, eventTimer: null };
    function startPoll() {
        clearTimeout(poll.timer);
        if (poll.running) { poll.again = true; return; }
        poll.running = true;
        poll.again = false;
        pollLive();
    }
    function pollDone() {
        if (!poll.running) { return; }
        poll.running = false;
        clearTimeout(poll.timer);
        poll.timer = setTimeout(startPoll, poll.again ? 500 : (live && live.connected() ? POLL_LIVE_MS : POLL_MS));
    }
    // Booking walk-in 3 jam = beberapa sinyal beruntun → cukup sekali ambil data.
    function pollSoon() {
        clearTimeout(poll.eventTimer);
        poll.eventTimer = setTimeout(startPoll, 300);
    }

    // ------------------------------------------------------------------ realtime (Reverb)
    var live = window.Club61Live && window.SIG_REALTIME ? window.Club61Live.connect(window.SIG_REALTIME) : null;
    if (live) {
        live.on('booking-board', 'board.changed', function (data) {
            var today = state.live && state.live.server_time ? state.live.server_time.substr(0, 10) : null;
            var dates = data.dates || [];
            for (var i = 0; i < dates.length; i++) {
                if (dates[i] === '*' || dates[i] === today || !today) { pollSoon(); return; }
            }
        });
        live.on('signature', 'signature.changed', function (data) {
            if (data.kind === 'published') { pollSoon(); }
        });
        // Tersambung lagi → ambil data sekali (mengejar perubahan selama putus). Putus → kembali polling 12 dtk.
        live.onState(function (connected) {
            if (connected) { pollSoon(); } else if (!poll.running) { clearTimeout(poll.timer); poll.timer = setTimeout(startPoll, POLL_MS); }
        });
    }

    function loadPayload() {
        if (!payloadUrl) { return; }
        getJson(payloadUrl, function (payload) {
            state.payload = payload;
            state.version = payload ? payload.version : null;
            state.revision = payload ? (payload.revision || 1) : null;
            build();
        }, function () {});
    }

    // Detik berjalan: hitung mundur & jam (tanpa menunggu polling).
    setInterval(function () {
        var nodes = stage.querySelectorAll('.countdown');
        var elapsed = (Date.now() - state.liveAt) / 1000;
        for (var i = 0; i < nodes.length; i++) {
            if (typeof nodes[i]._remaining === 'number') { nodes[i].textContent = fmtCountdown(nodes[i]._remaining - elapsed); }
        }
        var clocks = stage.querySelectorAll('.clock-time, .side-time');
        for (var j = 0; j < clocks.length; j++) { clocks[j].textContent = timeText(now()); }
        // Sesi main berganti tiap jam pas: status lapangan langsung diperbarui, tidak menunggu polling 60 dtk.
        var t = now();
        if (t.getMinutes() === 0 && t.getSeconds() === 3) { startPoll(); }
    }, 1000);

    // TV menyala berhari-hari: muat ulang halaman tiap 6 jam supaya memori browser TV tetap lega.
    if (!isPreview) { setTimeout(function () { window.location.reload(); }, 6 * 3600 * 1000); }

    window.addEventListener('resize', fitStage);
    fitStage();
    build();
    startPoll();
})();
</script>
</body>
</html>
