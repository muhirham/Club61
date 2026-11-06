<script>
    // Teks invoice dua bahasa (lang/{id,en}/invoice.php) — placeholder :x diganti di JS.
    const INV_T = @js(__('invoice.js'));
    const INV_STATUS = @js(__('invoice.statuses'));
    const INV_MONTHS = @js(__('invoice.months'));
    const INV_DATE_LOCALE = @js(app()->getLocale() === 'id' ? 'id-ID' : 'en-US');

    function invoiceApp() {
        return {
            isLoading: true,
            // Jam berjalan (diperbarui tiap detik) — tombol bayar langsung tertutup begitu batas bayar lewat.
            nowTs: Date.now(),
            isPolling: false,
            pollCount: 0,
            maxPolls: 10,
            pollingInterval: null,
            ticket: null,
            currentTicket: null,
            isMembershipTicket: false,
            allMembershipPurchases: [],
            pastBookings: [],
            isDownloadingPng: false,
            customerName: @json(Auth::user()->name ?? 'Customer VIP'),
            canCancelBooking: @json(Auth::check() && Auth::user()->canCancelBooking()),
            noticeModal: {
                show: false,
                title: '',
                message: '',
                type: 'info',
                buttonText: INV_T.ok_understood,
                onClose: null,
            },

            showNotice(title, message, type = 'info', buttonText = INV_T.ok_understood, onClose = null) {
                this.noticeModal = {
                    show: true,
                    title,
                    message,
                    type,
                    buttonText,
                    onClose,
                };
            },

            handleNoticeClose() {
                this.noticeModal.show = false;
                if (typeof this.noticeModal.onClose === 'function') {
                    const cb = this.noticeModal.onClose;
                    this.noticeModal.onClose = null;
                    cb();
                }
            },

            // Filter & Pagination
            searchQuery: '',
            searchDate: '',
            currentPage: 1,
            perPage: 3,

            get filteredPastBookings() {
                let list = this.pastBookings || [];
                if (this.searchQuery && this.searchQuery.trim() !== '') {
                    const q = this.searchQuery.toLowerCase().trim();
                    list = list.filter(b => {
                        const courtName = (b.court && b.court.name) ? b.court.name.toLowerCase() : '';
                        const planName = (b.plan_name) ? b.plan_name.toLowerCase() : '';
                        const bookingCode = (b.booking_code) ? b.booking_code.toLowerCase() : '';
                        const membershipCode = (b.membership_code) ? b.membership_code.toLowerCase() : '';
                        const bookingId = (b.id) ? b.id.toLowerCase() : '';
                        const status = (b.status) ? b.status.toLowerCase() : '';
                        const rawDate = (b.booking_date || b.created_at) ? String(b.booking_date || b.created_at).toLowerCase() : '';
                        const formattedDate = this.formatDate(b.booking_date || b.created_at).toLowerCase();
                        return courtName.includes(q) || planName.includes(q) || bookingCode.includes(q) || membershipCode.includes(q) || bookingId.includes(q) || status.includes(q) || rawDate.includes(q) || formattedDate.includes(q);
                    });
                }
                if (this.searchDate && this.searchDate.trim() !== '') {
                    const targetDate = this.searchDate.trim();
                    list = list.filter(b => {
                        const d = b.booking_date || b.created_at;
                        if (!d) return false;
                        const bDate = String(d).substring(0, 10);
                        return bDate === targetDate;
                    });
                }
                return list;
            },

            get totalPages() {
                return Math.max(1, Math.ceil(this.filteredPastBookings.length / this.perPage));
            },

            get paginatedBookings() {
                if (this.currentPage > this.totalPages) {
                    this.currentPage = this.totalPages;
                }
                if (this.currentPage < 1) {
                    this.currentPage = 1;
                }
                const start = (this.currentPage - 1) * this.perPage;
                return this.filteredPastBookings.slice(start, start + this.perPage);
            },

            nextPage() {
                if (this.currentPage < this.totalPages) {
                    this.currentPage++;
                }
            },

            prevPage() {
                if (this.currentPage > 1) {
                    this.currentPage--;
                }
            },

            resetFilters() {
                this.searchQuery = '';
                this.searchDate = '';
                this.currentPage = 1;
            },

            // Payment Methods State & Modal
            showPaymentModal: false,
            // Pilihan bayar = metode AKTIF dari menu "Metode Pembayaran Online" (sama dengan validasi server).
            // catalogMethods = semua metode (termasuk nonaktif) hanya untuk menampilkan nama metode pembayaran lama.
            paymentMethods: @js(app(\App\Services\Payment\OnlinePaymentMethodService::class)->forFrontend()),
            catalogMethods: @js(app(\App\Services\Payment\OnlinePaymentMethodService::class)->catalogForDisplay()),
            selectedMethod: (@js(app(\App\Services\Payment\OnlinePaymentMethodService::class)->forFrontend())[0]) || { id: '', code: '', name: INV_T.no_method_available, badge: '-', fee: 0, note: '' },

            /** Nominal yang akan dibayar sekarang: selisih reschedule yang belum lunas, atau total order. */
            get amountToPay() {
                const t = this.currentTicket;
                if (!t) return 0;
                if (t.type === 'MEMBERSHIP') return parseFloat(t.grand_total) || 0;
                return parseFloat(t.has_pending_delta ? t.unpaid_delta : (t.order_grand_total ?? t.total_amount)) || 0;
            },

            /** Metode yang berlaku untuk nominal ini (batas nominal per metode, mis. QRIS maks Rp10 juta). */
            get availableMethods() {
                const total = this.amountToPay;
                return this.paymentMethods.filter(m => (m.min_amount === null || total >= m.min_amount) && (m.max_amount === null || total <= m.max_amount));
            },

            ensureSelectedMethodAvailable() {
                if (! this.availableMethods.some(m => m.code === this.selectedMethod.code) && this.availableMethods.length) {
                    this.selectedMethod = this.availableMethods[0];
                }
            },
            isSubmittingPayment: false,
            isCashNotice: false,
            lastSnapToken: null,

            get displayCourtFee() {
                if (this.ticket && this.ticket.order_court_fee) return this.ticket.order_court_fee;
                return this.ticket ? this.ticket.court_fee : 0;
            },

            get displayEquipmentFee() {
                if (this.ticket && this.ticket.order_equipment_fee !== undefined) return this.ticket.order_equipment_fee;
                return this.ticket ? (this.ticket.equipment_fee || 0) : 0;
            },

            get displayGrandTotal() {
                if (this.ticket && this.ticket.order_grand_total) return this.ticket.order_grand_total;
                return this.ticket ? this.ticket.total_amount : 0;
            },

            get totalMemberDiscount() {
                if (!this.ticket) return 0;
                if (this.ticket.order_member_discount_court !== undefined) return parseFloat(this.ticket.order_member_discount_court) || 0;
                return parseFloat(this.ticket.member_discount_court) || 0;
            },

            get totalSponsorDiscount() {
                if (!this.ticket) return 0;
                if (this.ticket.order_sponsor_discount_court !== undefined) return parseFloat(this.ticket.order_sponsor_discount_court) || 0;
                return parseFloat(this.ticket.sponsor_discount_court) || 0;
            },

            /** Voucher / promo yang memotong order ini (null kalau tidak ada). */
            get orderVoucher() {
                return this.ticket && this.ticket.order_voucher ? this.ticket.order_voucher : null;
            },

            /** Keterangan potongan voucher, mis. "Promo Pembukaan · 20% off court rental (max Rp 50.000)". */
            voucherDetail(v) {
                if (!v) return '';
                if (v.is_credit) return INV_T.credit_voucher;
                let what = INV_T.promo;
                if (v.type === 'PERCENT') {
                    what = INV_T.percent_off.replace(':value', Number(v.value || 0).toLocaleString(INV_DATE_LOCALE, { maximumFractionDigits: 2 }))
                        + (v.max_discount ? INV_T.max_discount.replace(':amount', this.formatNumber(v.max_discount)) : '');
                } else if (v.type === 'FIXED') {
                    what = INV_T.fixed_off.replace(':amount', this.formatNumber(v.value));
                }
                return (v.name ? v.name + ' · ' : '') + what;
            },

            get totalSponsorHours() {
                if (!this.ticket) return 0;
                if (this.ticket.order_sponsor_hours_consumed !== undefined) return parseFloat(this.ticket.order_sponsor_hours_consumed) || 0;
                return parseFloat(this.ticket.sponsor_hours_consumed) || 0;
            },

            getPaymentMethodObject(rawCodeOrName) {
                if (!rawCodeOrName) return null;
                const upper = String(rawCodeOrName).toUpperCase();

                const exact = this.catalogMethods.find(m => m.code === upper);
                if (exact) return exact;
                if (upper.includes('PERMATA')) return this.catalogMethods.find(m => m.code === 'BSI_VA') || { id: 'bsi_va', code: 'BSI_VA', name: 'VA Bank Lain (Permata, BSI, dll.)', badge: 'VA' };
                if (upper.includes('CREDIT')) return this.catalogMethods.find(m => m.code === 'CREDIT_CARD') || { id: 'credit_card', code: 'CREDIT_CARD', name: 'Kartu Kredit / Debit Online', badge: 'CARD' };
                if (upper.includes('BCA')) return this.catalogMethods.find(m => m.code === 'BCA_VA') || { id: 'bca', code: 'BCA_VA', name: 'BCA Virtual Account', badge: 'BCA' };
                if (upper.includes('MANDIRI')) return this.catalogMethods.find(m => m.code === 'MANDIRI_VA') || { id: 'mandiri', code: 'MANDIRI_VA', name: 'Mandiri Virtual Account', badge: 'MDR' };
                if (upper.includes('BRI')) return this.catalogMethods.find(m => m.code === 'BRI_VA') || { id: 'bri', code: 'BRI_VA', name: 'BRI Virtual Account', badge: 'BRI' };
                if (upper.includes('BNI')) return this.catalogMethods.find(m => m.code === 'BNI_VA') || { id: 'bni', code: 'BNI_VA', name: 'BNI Virtual Account', badge: 'BNI' };
                if (upper.includes('CIMB')) return this.catalogMethods.find(m => m.code === 'CIMB_VA') || { id: 'cimb', code: 'CIMB_VA', name: 'CIMB Virtual Account', badge: 'CIMB' };
                if (upper.includes('BSI')) return this.catalogMethods.find(m => m.code === 'BSI_VA') || { id: 'bsi_va', code: 'BSI_VA', name: 'VA Bank Lain (Permata, BSI, dll.)', badge: 'VA' };
                if (upper.includes('CASH') || upper.includes('TUNAI')) return this.catalogMethods.find(m => m.code === 'CASH') || { id: 'cash', code: 'CASH', name: 'Cash on Arrival (Walk-in)', badge: 'CASH' };
                if (upper.includes('QRIS') || upper.includes('GOPAY') || upper.includes('OVO')) return this.catalogMethods.find(m => m.code === 'QRIS') || { id: 'qris', code: 'QRIS', name: 'QRIS Instant (GoPay/OVO/BCA)', badge: 'QRIS' };

                return { id: 'custom', code: upper, name: rawCodeOrName, badge: 'PAY' };
            },

            selectPaymentMethod(m) {
                this.selectedMethod = m;
                this.showPaymentModal = false;
                this.isCashNotice = (m.code === 'CASH');
            },

            openChangeMethodModal() {
                this.showPaymentModal = true;
            },

            continuePayment() {
                return this.payNow();
            },

            async payNow() {
                // Klik beruntun (double tap) tidak boleh mengirim permintaan bayar kedua selagi yang pertama berjalan.
                if (!this.currentTicket || this.isSubmittingPayment) return;
                // Tagihan Rp0 tidak memakai metode bayar (server langsung melunasi) — jangan diblokir batas nominal metode.
                const isFree = Number(this.amountToPay) <= 0;
                if (! isFree && ! this.availableMethods.length) {
                    this.showNotice(INV_T.online_unavailable_title, INV_T.online_unavailable_message, 'error', INV_T.close);
                    return;
                }
                // Jangan diam-diam membayar dengan metode lain — beri tahu customer dulu, biar dia yang lanjutkan.
                if (! isFree && ! this.availableMethods.some(m => m.code === this.selectedMethod.code)) {
                    const previous = this.selectedMethod.name;
                    this.ensureSelectedMethodAvailable();
                    this.showNotice(INV_T.method_switched_title, INV_T.method_switched_message.replace(':previous', previous).replace(':method', this.selectedMethod.name), 'info', INV_T.oke);
                    return;
                }
                if (['EXPIRED', 'CANCELLED', 'REFUNDED', 'REFUND_PENDING'].includes(this.currentTicket.status)) {
                    this.showNotice(INV_T.reservation_inactive_title, INV_T.reservation_inactive_message, 'error', INV_T.close);
                    return;
                }
                if (this.paymentWindowClosed(this.currentTicket)) {
                    this.showNotice(INV_T.payment_time_up_title, INV_T.payment_time_up_message, 'error', INV_T.close);
                    return;
                }
                this.isSubmittingPayment = true;

                try {
                    // Tagihan selisih reschedule melekat ke booking-nya → kirim id booking, bukan id order.
                    const targetId = this.currentTicket.has_pending_delta
                        ? this.currentTicket.id
                        : (this.currentTicket.order_id || this.currentTicket.id);
                    const res = await fetch(`/api/v1/padel/bookings/${targetId}/retry-payment`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({
                            payment_method: this.selectedMethod.code
                        })
                    });

                    const json = await res.json();
                    if (json.success) {
                        if (json.is_cash) {
                            this.isCashNotice = true;
                            await this.loadTicket(this.currentTicket.id);
                        } else if (json.is_paid) {
                            // Tagihan Rp0 (ditanggung kuota member / voucher jam corporate): lunas tanpa Midtrans.
                            await this.loadTicket(this.currentTicket.id);
                            this.showNotice(INV_T.booking_confirmed_title, json.message || INV_T.booking_confirmed_message, 'success', INV_T.ok);
                        } else if (json.checkout_mode === 'SNAP') {
                            // DOKU SNAP: QR / nomor VA tampil di kartu ini (pay_display dari server), status dicek otomatis.
                            await this.loadTicket(this.currentTicket.id);
                            this.startAutoPolling(this.currentTicket.id);
                        } else if (json.driver === 'doku' && json.payment_url) {
                            // DOKU Checkout: halaman bayar DOKU; setelah bayar DOKU mengembalikan customer ke invoice ini.
                            window.location.href = json.payment_url;
                        } else if (json.snap_token) {
                            this.lastSnapToken = json.snap_token;
                            this.openSnap(json.snap_token, json.redirect_url);
                        }
                    } else {
                        // Status booking bisa sudah berubah (batas bayar habis, sudah lunas lewat sesi lain) — muat ulang
                        // kartunya supaya tampilan tidak tertahan di "Pay Now" sampai di-refresh.
                        await this.loadTicket(this.currentTicket.id);
                        this.showNotice(res.status === 422 ? INV_T.payment_unprocessable_title : INV_T.payment_failed_title, json.message || INV_T.payment_session_failed, 'error', INV_T.close);
                    }
                } catch(e) {
                    console.error('Error retry payment:', e);
                    this.showNotice(INV_T.network_title, INV_T.network_message, 'error', INV_T.close);
                } finally {
                    this.isSubmittingPayment = false;
                }
            },

            /**
             * Gambar QR di elemen ini dari teks (qrcodejs lokal, public/js/qrcode.min.js). Dulu gambar diminta ke
             * api.qrserver.com — kode akses gate / kartu member ikut terkirim ke pihak ketiga, dan QR tidak muncul kalau
             * layanan itu down. Menunggu library termuat (script di bawah halaman) maksimal ±6 detik.
             */
            // Modul 21: judul & keterangan tiket yang sudah tidak aktif (batal / refund / voucher saldo / hangus).
            closedTicketTitle(t) {
                const refund = t.refund_info || null;
                if (t.status === 'REFUND_PENDING') return INV_T.refund_review_title;
                if (t.status === 'REFUNDED') return INV_T.refunded_title;
                if (t.status === 'CANCELLED' && refund && refund.voucher_code) return INV_T.credit_converted_title;
                if (t.status === 'CANCELLED') return INV_T.cancelled_title;
                return this.wasPaid(t) ? INV_T.no_show_title : INV_T.payment_window_expired_title;
            },

            // Lunas termasuk Rp0 (voucher corporate / kuota member / voucher 100%): hangus = tidak hadir, bukan telat bayar.
            wasPaid(t) {
                return !!t && (Number(t.total_paid) > 0 || t.was_paid === true);
            },

            closedTicketMessage(t) {
                const refund = t.refund_info || null;
                const amount = refund ? 'Rp ' + this.formatNumber(Math.round(refund.amount)) : '';
                if (t.status === 'REFUND_PENDING') return INV_T.refund_review_message.replace(':amount', amount);
                if (t.status === 'REFUNDED') return INV_T.refunded_message.replace(':amount', amount);
                if (t.status === 'CANCELLED' && refund && refund.voucher_code) {
                    return INV_T.credit_converted_message.replace(':amount', amount).replace(':code', refund.voucher_code) + (refund.voucher_valid_until ? INV_T.credit_valid_until.replace(':date', refund.voucher_valid_until) : '') + INV_T.credit_converted_suffix;
                }
                if (t.status === 'CANCELLED') return INV_T.cancelled_message;
                return this.wasPaid(t) ? INV_T.no_show_message : INV_T.payment_window_expired_message;
            },

            renderQr(el, text, attempt = 0, level = "H") {
                if (!el) return;
                text = text ? String(text) : '';
                if (attempt === 0) el._qrWanted = text;
                if (el._qrWanted !== text) return; // teks sudah berganti selama menunggu library
                if (el._qrDrawn === text) return;

                if (!text) {
                    el.innerHTML = '';
                    el._qrDrawn = '';
                    return;
                }
                if (!window.QRCode) {
                    if (attempt < 40) setTimeout(() => this.renderQr(el, text, attempt + 1, level), 150);
                    return;
                }

                el.innerHTML = '';
                new QRCode(el, { text, width: 220, height: 220, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel[level] ?? QRCode.CorrectLevel.H });
                el.querySelectorAll('img, canvas').forEach(node => { node.style.width = '100%'; node.style.height = '100%'; });
                el._qrDrawn = text;
            },

            // ── Halaman bayar DOKU SNAP (QRIS / VA BCA) ─────────────────────────────────────────────────────────
            snapCountdown(expiresAt) {
                const ms = new Date(expiresAt).getTime() - this.nowTs;
                if (isNaN(ms) || ms <= 0) return '00:00';
                const s = Math.floor(ms / 1000);
                const h = Math.floor(s / 3600);
                const mm = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
                const ss = String(s % 60).padStart(2, '0');
                return h > 0 ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
            },

            async copySnapText(text) {
                try {
                    await navigator.clipboard.writeText(String(text || ''));
                    this.showNotice(INV_T.copied_title, INV_T.copied_message, 'success', INV_T.ok);
                } catch (e) {
                    this.showNotice(INV_T.copy_failed_title, INV_T.copy_failed_message, 'error', INV_T.close);
                }
            },

            downloadSnapQr(panel) {
                const node = panel ? panel.querySelector('canvas, img') : null;
                if (!node) return;
                const link = document.createElement('a');
                link.download = 'QRIS-Club61.png';
                link.href = node.tagName === 'CANVAS' ? node.toDataURL('image/png') : node.src;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            },

            // Pesanan membership online yang belum dibayar: lanjutkan bayar (order yang sama) / batalkan.
            showCancelMembershipModal: false,
            isCancellingMembership: false,
            membershipPollTimer: null,
            membershipPollId: null,

            async payMembership() {
                const t = this.currentTicket;
                if (!t || !t.can_pay_online || this.isSubmittingPayment) return;
                if (! this.availableMethods.length) {
                    this.showNotice(INV_T.online_unavailable_title, INV_T.online_unavailable_message, 'error', INV_T.close);
                    return;
                }
                if (! this.availableMethods.some(m => m.code === this.selectedMethod.code)) {
                    const previous = this.selectedMethod.name;
                    this.ensureSelectedMethodAvailable();
                    this.showNotice(INV_T.method_switched_title, INV_T.method_switched_message.replace(':previous', previous).replace(':method', this.selectedMethod.name), 'info', INV_T.oke);
                    return;
                }

                this.isSubmittingPayment = true;
                try {
                    const res = await fetch(`/api/v1/membership/purchases/${t.id}/pay`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                        body: JSON.stringify({ payment_method: this.selectedMethod.code }),
                    });
                    const json = await res.json();
                    if (!res.ok || !json.success) {
                        this.showNotice(INV_T.payment_failed_title, json.message || INV_T.membership_payment_failed_message, 'error', INV_T.close);
                        await this.refreshMembershipTicket(t.id);
                        return;
                    }

                    const payment = json.data.payment || {};
                    if (json.data.already_paid || payment.is_mock) {
                        await this.refreshMembershipTicket(t.id);
                        this.showNotice(INV_T.membership_active_title, json.message, 'success', INV_T.oke);
                    } else if (payment.checkout_mode === 'SNAP') {
                        // DOKU SNAP: QR / nomor VA tampil di kartu membership ini, status dicek otomatis.
                        await this.refreshMembershipTicket(t.id);
                        this.pollMembership(t.id);
                    } else if (payment.snap_token && window.snap) {
                        window.snap.pay(payment.snap_token, {
                            onSuccess: () => this.pollMembership(t.id),
                            onPending: () => this.pollMembership(t.id),
                            onError: () => this.showNotice(INV_T.payment_declined_title, INV_T.payment_declined_message, 'error', INV_T.close),
                            onClose: () => this.pollMembership(t.id),
                        });
                    } else if (payment.redirect_url || payment.payment_url) {
                        window.location.href = payment.redirect_url || payment.payment_url;
                    }
                } catch (e) {
                    console.error('Error membership payment:', e);
                    this.showNotice(INV_T.network_title, INV_T.network_message, 'error', INV_T.close);
                } finally {
                    this.isSubmittingPayment = false;
                }
            },

            async confirmCancelMembership() {
                const t = this.currentTicket;
                if (!t) return;
                this.isCancellingMembership = true;
                try {
                    const res = await fetch(`/api/v1/membership/purchases/${t.id}/cancel`, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    });
                    const json = await res.json();
                    this.showCancelMembershipModal = false;
                    await this.refreshMembershipTicket(t.id);
                    this.showNotice(json.success ? INV_T.order_cancelled_title : INV_T.cannot_cancel_title, json.message, json.success ? 'success' : 'error', INV_T.oke);
                } catch (e) {
                    console.error('Error cancel membership order:', e);
                    this.showNotice(INV_T.server_error_title, INV_T.server_error_message, 'error', INV_T.close);
                } finally {
                    this.isCancellingMembership = false;
                }
            },

            /** Muat ulang daftar pembelian (ikut cek Midtrans kalau verify) lalu tampilkan lagi kartu yang sama. */
            async refreshMembershipTicket(id, verify = false) {
                try {
                    const res = await fetch('/api/v1/membership/my-purchases' + (verify ? '?verify_payment=1' : ''), { headers: { 'Accept': 'application/json' } });
                    const json = await res.json();
                    if (json.success && json.data) {
                        this.allMembershipPurchases = json.data;
                        const found = json.data.find(m => m.id === id);
                        if (found && this.isMembershipTicket && this.currentTicket && this.currentTicket.id === id) {
                            this.setMembershipTicket(found);
                        }
                        return found;
                    }
                } catch (e) {
                    console.error('Failed to refresh membership purchase:', e);
                }
                return null;
            },

            /**
             * Cek status ke server (yang ikut menanyakan Midtrans) sampai aktif / batal — dipakai setelah jendela
             * Midtrans DAN saat halaman dibuka dengan pesanan yang masih menunggu (customer bisa membayar VA di tab /
             * aplikasi bank lain). Dulu cuma 3 menit setelah Snap ditutup, jadi status baru berubah setelah refresh.
             * Tiap 5 detik di menit pertama, lalu tiap 15 detik, maks. 15 menit.
             */
            pollMembership(id) {
                if (this.membershipPollTimer) clearTimeout(this.membershipPollTimer);
                this.membershipPollId = id;
                const startedAt = Date.now();
                const tick = async () => {
                    const found = await this.refreshMembershipTicket(id, true);
                    const elapsed = Date.now() - startedAt;
                    if (!found || found.status !== 'PENDING_PAYMENT' || elapsed > 900000) {
                        this.membershipPollTimer = null;
                        if (found && found.status === 'ACTIVE') {
                            this.showNotice(INV_T.membership_active_title, INV_T.membership_active_message, 'success', INV_T.oke);
                        }
                        return;
                    }
                    this.membershipPollTimer = setTimeout(tick, elapsed < 60000 ? 5000 : 15000);
                };
                this.membershipPollTimer = setTimeout(tick, 0);
            },

            /** Pesanan online yang masih menunggu → mulai cek otomatis (sekali per kartu yang dibuka). */
            watchPendingMembership(data) {
                if (data && data.can_pay_online && data.status === 'PENDING_PAYMENT' && !(this.membershipPollTimer && this.membershipPollId === data.id)) {
                    this.pollMembership(data.id);
                }
            },

            showCancelModal: false,
            isCancellingBooking: false,

            openCancelModal() {
                if (!this.canCancelBooking) {
                    this.showNotice(INV_T.access_denied_title, INV_T.access_denied_message, 'error', INV_T.close);
                    return;
                }
                this.showCancelModal = true;
            },

            cancelActiveBooking() {
                this.openCancelModal();
            },

            async confirmCancelBooking() {
                if (!this.currentTicket) return;
                if (!this.canCancelBooking) {
                    this.showNotice(INV_T.access_denied_title, INV_T.access_denied_message, 'error', INV_T.close);
                    this.showCancelModal = false;
                    return;
                }

                this.isCancellingBooking = true;
                try {
                    const bookingIds = (this.ticket && this.ticket.order_bookings && this.ticket.order_bookings.length > 0)
                        ? this.ticket.order_bookings.map(b => b.id)
                        : [this.currentTicket.id];

                    const res = await fetch('/api/v1/padel/release-slot', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({
                            booking_ids: bookingIds
                        })
                    });

                    const json = await res.json();
                    if (json.success) {
                        localStorage.removeItem('club61_cart');
                        localStorage.removeItem('club61_hold_data');
                        sessionStorage.removeItem('club61_cart');
                        sessionStorage.removeItem('club61_hold_data');
                        sessionStorage.removeItem('vantage_cart');
                        sessionStorage.removeItem('vantage_hold_data');
                        window.dispatchEvent(new CustomEvent('cart-updated'));

                        window.location.href = '{{ route('customer.booking') }}';
                    } else {
                        this.showNotice(INV_T.cancellation_failed_title, json.message || INV_T.cancellation_failed_message, 'error', INV_T.close);
                        this.isCancellingBooking = false;
                    }
                } catch (e) {
                    console.error('Error cancel booking:', e);
                    this.showNotice(INV_T.server_error_title, INV_T.server_error_message, 'error', INV_T.close);
                    this.isCancellingBooking = false;
                }
            },

            openSnap(token, redirectUrl = null) {
                if (window.snap) {
                    window.snap.pay(token, {
                        onSuccess: async (result) => {
                            await this.loadTicket(this.currentTicket.id);
                        },
                        onPending: async (result) => {
                            await this.loadTicket(this.currentTicket.id);
                        },
                        onError: (result) => {
                            this.showNotice(INV_T.payment_declined_title, INV_T.payment_declined_message, 'error', INV_T.close);
                        },
                        onClose: () => {
                            this.startAutoPolling(this.currentTicket.id);
                        }
                    });
                } else if (redirectUrl) {
                    // Popup Snap tidak termuat (client key kosong / snap.js diblokir) → halaman pembayaran Midtrans.
                    window.location.href = redirectUrl;
                } else {
                    this.showNotice(INV_T.loading_payment_title, INV_T.loading_payment_message, 'info', INV_T.close);
                }
            },

            async init() {
                setInterval(() => { this.nowTs = Date.now(); }, 1000);
                const urlParams = new URLSearchParams(window.location.search);
                const lookupKey = urlParams.get('booking_id') || urlParams.get('order_id') || urlParams.get('booking_code') || urlParams.get('id');
                const membershipLookupKey = urlParams.get('membership_id');

                let loaded = false;
                if (membershipLookupKey) {
                    loaded = await this.loadMembershipTicket(membershipLookupKey);
                } else if (lookupKey) {
                    loaded = await this.loadTicket(lookupKey);
                }

                if (!loaded) {
                    loaded = await this.loadLatestBooking();
                }
                if (!loaded) {
                    await this.loadLatestMembershipPurchase();
                }

                await this.loadMyBookings();
                this.isLoading = false;

                // Kembali ke tab ini (mis. habis bayar VA di aplikasi bank) → langsung cek ulang pesanan membership yang menunggu.
                document.addEventListener('visibilitychange', () => {
                    const t = this.currentTicket;
                    if (document.visibilityState === 'visible' && this.isMembershipTicket && t && t.can_pay_online && t.status === 'PENDING_PAYMENT') {
                        this.pollMembership(t.id);
                    }
                });

                window.addEventListener('popstate', async () => {
                    const params = new URLSearchParams(window.location.search);
                    const key = params.get('booking_id') || params.get('order_id') || params.get('booking_code') || params.get('id');
                    const membershipKey = params.get('membership_id');
                    if (key || membershipKey) {
                        this.isLoading = true;
                        if (this.pollingInterval) {
                            clearInterval(this.pollingInterval);
                            this.pollingInterval = null;
                            this.isPolling = false;
                        }
                        if (membershipKey) {
                            await this.loadMembershipTicket(membershipKey);
                        } else {
                            await this.loadTicket(key);
                        }
                        await this.loadMyBookings();
                        this.isLoading = false;
                    }
                });
            },

            async loadTicket(id) {
                try {
                    const res = await fetch(`/api/v1/padel/bookings/${id}/ticket`);
                    const json = await res.json();
                    if (json.success && json.data) {
                        this.ticket = json.data;
                        this.currentTicket = json.data;
                        this.isMembershipTicket = false;

                        const rawMethod = this.ticket.payment_method_label
                            || this.ticket.payment_method
                            || (this.ticket.order && (this.ticket.order.payment_method_label || this.ticket.order.payment_method));

                        if (rawMethod) {
                            const methodObj = this.getPaymentMethodObject(rawMethod);
                            if (methodObj) {
                                this.selectedMethod = methodObj;
                            }
                            if (rawMethod === 'CASH' || this.ticket.payment_method === 'CASH') {
                                this.isCashNotice = true;
                            }
                        }

                        if (this.isAwaitingPayment(this.ticket)) {
                            this.startAutoPolling(id);
                        }

                        return true;
                    }
                    return false;
                } catch(e) {
                    console.error('Failed to load ticket:', e);
                    return false;
                }
            },

            /**
             * Muat daftar SEMUA pembelian membership user (bukan cuma yang aktif) — dipakai buat
             * "current ticket" fallback (kalau belum ada booking sama sekali) DAN riwayat gabungan
             * di kolom kanan, jadi cuma 1x fetch API per kunjungan halaman.
             */
            async loadMembershipPurchases() {
                try {
                    const res = await fetch('/api/v1/membership/my-purchases');
                    const json = await res.json();
                    if (json.success && json.data) {
                        this.allMembershipPurchases = json.data;
                        return json.data;
                    }
                } catch(e) {
                    console.error('Failed to load membership purchases:', e);
                }
                return [];
            },

            async loadLatestMembershipPurchase() {
                const list = this.allMembershipPurchases.length ? this.allMembershipPurchases : await this.loadMembershipPurchases();
                if (list.length > 0) {
                    this.setMembershipTicket(list[0]);
                    return true;
                }
                return false;
            },

            async loadMembershipTicket(id) {
                const list = this.allMembershipPurchases.length ? this.allMembershipPurchases : await this.loadMembershipPurchases();
                const found = list.find(m => m.id === id || m.order_number === id || m.membership_code === id);
                if (found) {
                    this.setMembershipTicket(found);
                    return true;
                }
                return false;
            },

            setMembershipTicket(data) {
                this.ticket = data;
                this.currentTicket = data;
                this.isMembershipTicket = true;
                this.watchPendingMembership(data);

                if (data.payment_method) {
                    const methodObj = this.getPaymentMethodObject(data.payment_method);
                    if (methodObj) {
                        this.selectedMethod = methodObj;
                    }
                }
            },

            /**
             * Ganti "current ticket" yang sedang ditampilkan dari daftar riwayat gabungan (bisa
             * booking lapangan ATAU pembelian membership) — mengganti loadTicket()/switchToBooking()
             * lama yang cuma tau soal booking.
             */
            async switchToEntry(item) {
                if (!item || !item.id) return;
                this.isLoading = true;
                if (this.pollingInterval) {
                    clearInterval(this.pollingInterval);
                    this.pollingInterval = null;
                    this.isPolling = false;
                }

                const newUrl = new URL(window.location.href);
                newUrl.searchParams.delete('order_id');
                newUrl.searchParams.delete('id');
                newUrl.searchParams.delete('booking_code');
                newUrl.searchParams.delete('booking_id');
                newUrl.searchParams.delete('membership_id');

                if (item.type === 'MEMBERSHIP') {
                    newUrl.searchParams.set('membership_id', item.id);
                    window.history.pushState({ membership_id: item.id }, '', newUrl);
                    await this.loadMembershipTicket(item.id);
                } else {
                    newUrl.searchParams.set('booking_id', item.id);
                    window.history.pushState({ booking_id: item.id }, '', newUrl);
                    await this.loadTicket(item.id);
                }

                await this.loadMyBookings();
                this.isLoading = false;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            },

            // Kept for backward compatibility with any inline handler still referencing it directly.
            async switchToBooking(id) {
                return this.switchToEntry({ id, type: 'BOOKING' });
            },

            switchSession(sBooking) {
                this.currentTicket = sBooking;
            },

            async loadLatestBooking() {
                try {
                    const res = await fetch('/api/v1/padel/my-bookings');
                    const json = await res.json();
                    if (json.success && json.data && json.data.length > 0) {
                        const latest = json.data[0];
                        return await this.loadTicket(latest.id);
                    }
                } catch(e) {
                    console.error('Failed to load latest booking:', e);
                }
                return false;
            },

            async loadMyBookings() {
                try {
                    const [bookingsJson, membershipList] = await Promise.all([
                        fetch('/api/v1/padel/my-bookings').then(r => r.json()),
                        this.allMembershipPurchases.length ? Promise.resolve(this.allMembershipPurchases) : this.loadMembershipPurchases(),
                    ]);

                    let combined = [];
                    if (bookingsJson.success && bookingsJson.data) {
                        combined = combined.concat(bookingsJson.data.map(b => ({ ...b, type: 'BOOKING' })));
                    }
                    combined = combined.concat(membershipList || []);

                    combined.sort((a, b) => new Date(b.created_at || b.booking_date || 0) - new Date(a.created_at || a.booking_date || 0));

                    const currentId = this.ticket?.id;
                    const currentOrderId = this.ticket?.order_id || this.ticket?.order?.id;
                    const currentOrderNumber = this.ticket?.order?.order_number || this.ticket?.order_number;

                    this.pastBookings = combined.filter(b => {
                        if (!this.ticket) return true;
                        if (b.id === currentId) return false;
                        if (currentOrderId && b.order_id === currentOrderId) return false;
                        if (currentOrderNumber && (b.order_id === currentOrderNumber || b.order?.order_number === currentOrderNumber || b.order_number === currentOrderNumber)) return false;
                        return true;
                    });
                } catch(e) {
                    console.error('Failed to load booking history:', e);
                }
            },

            calculateDuration(start, end) {
                if (!start || !end) return 1;
                try {
                    const s = new Date(start).getTime();
                    const e = new Date(end).getTime();
                    if (!isNaN(s) && !isNaN(e)) {
                        return Math.max(1, Math.round((e - s) / (1000 * 60 * 60)));
                    }
                } catch(e) {}
                return 1;
            },

            /** Masih menunggu pembayaran: booking belum lunas, atau selisih reschedule yang belum dibayar. */
            isAwaitingPayment(t) {
                if (!t) return false;
                return ['PENDING', 'PENDING_PAYMENT'].includes(t.status) || !!t.has_pending_delta;
            },

            /**
             * Batas bayar booking sudah lewat tapi server belum selesai melepas slot (pembersih otomatis menunggu
             * jeda & konfirmasi Midtrans). Tombol bayar / ganti metode ditutup; polling yang memperbarui statusnya.
             * Tagihan selisih reschedule tidak punya batas bayar.
             */
            paymentWindowClosed(t) {
                if (!t || t.has_pending_delta || !['PENDING', 'PENDING_PAYMENT'].includes(t.status) || !t.expires_at) return false;
                const exp = new Date(t.expires_at).getTime();
                return !isNaN(exp) && this.nowTs >= exp;
            },

            /**
             * Cek status ke server (yang ikut menanyakan Midtrans) sampai lunas, batal, atau batas bayar lewat.
             * Dulu berhenti setelah 30 detik — customer VA yang transfer 1–3 menit kemudian tetap melihat
             * "menunggu pembayaran" sampai refresh. Tiap 3 detik di menit pertama, lalu tiap 10 detik; jeda saat
             * tab tidak dibuka.
             */
            startAutoPolling(id) {
                if (this.isPolling) return;
                this.isPolling = true;
                this.pollCount = 0;

                const startedAt = Date.now();
                let lastPollAt = 0;
                let busy = false;
                const stopAt = () => {
                    const t = this.currentTicket;
                    // Batas bayar dari server + 20 menit: slot baru dilepas setelah jeda 3 menit, dan bisa sampai 15 menit
                    // kalau Midtrans masih "pending". Dulu berhenti di +3 menit → tampilan tertahan di "Pay Now" sampai
                    // di-refresh. Selisih reschedule tidak punya batas → 20 menit sejak mulai.
                    const exp = t && ['PENDING', 'PENDING_PAYMENT'].includes(t.status) && t.expires_at ? new Date(t.expires_at).getTime() : NaN;
                    return isNaN(exp) ? startedAt + 20 * 60 * 1000 : exp + 20 * 60 * 1000;
                };
                const stop = () => {
                    clearInterval(this.pollingInterval);
                    this.pollingInterval = null;
                    this.isPolling = false;
                };

                this.pollingInterval = setInterval(async () => {
                    const now = Date.now();
                    if (now > stopAt()) return stop();
                    if (busy || document.visibilityState === 'hidden') return;
                    if (now - lastPollAt < ((now - startedAt) < 60000 ? 3000 : 10000)) return;

                    lastPollAt = now;
                    busy = true;
                    this.pollCount++;
                    try {
                        // verify_payment=1: server ikut menanyakan status ke Midtrans, jadi tetap
                        // berubah lunas walau webhook Midtrans tidak sampai.
                        const res = await fetch(`/api/v1/padel/bookings/${id}/ticket?verify_payment=1`);
                        const json = await res.json();
                        if (json.success && json.data) {
                            this.ticket = json.data;
                            this.currentTicket = json.data;

                            if (!this.isAwaitingPayment(this.ticket)) stop();
                        }
                    } catch(e) {
                    } finally {
                        busy = false;
                    }
                }, 1000);
            },

            formatNumber(val) {
                if (!val) return '0';
                return Math.round(val).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
            },

            // Tanggal & jam selalu dalam zona waktu venue (bukan zona waktu HP customer): jadwal 19:00 WIB tetap
            // tampil 19:00 walau HP disetel ke zona lain.
            appTimezone: @js(config('app.timezone')),

            formatDate(val) {
                if (!val) return '-';
                const months = INV_MONTHS;
                const plain = String(val).match(/^(\d{4})-(\d{2})-(\d{2})$/);
                if (plain) return `${plain[3]} ${months[Number(plain[2]) - 1]} ${plain[1]}`;
                try {
                    const d = new Date(val);
                    if (!isNaN(d.getTime())) {
                        const parts = Object.fromEntries(new Intl.DateTimeFormat(INV_DATE_LOCALE, { timeZone: this.appTimezone, day: '2-digit', month: 'short', year: 'numeric' })
                            .formatToParts(d).map(p => [p.type, p.value]));
                        return `${parts.day} ${parts.month} ${parts.year}`;
                    }
                } catch(e) {}
                return String(val).substring(0, 10);
            },

            formatTime(isoString) {
                if (!isoString) return '--:--';
                try {
                    const d = new Date(isoString);
                    if (!isNaN(d.getTime())) {
                        return new Intl.DateTimeFormat('en-GB', { timeZone: this.appTimezone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(d);
                    }
                } catch(e) {}
                return isoString.substring(11, 16) || isoString;
            },

            // Label status yang ramah dibaca (bukan kode mentah seperti PENDING_PAYMENT). Booking yang dipindah jadwal dan
            // selisihnya belum lunas tetap LOCKED di database, tapi bagi customer itu "Balance due", bukan "In checkout".
            statusLabel(status, ticket = null) {
                if (status === 'LOCKED' && ticket && (ticket.has_pending_delta || Number(ticket.reschedule_count) > 0)) {
                    return INV_T.balance_due;
                }
                if (status === 'EXPIRED' && ticket && this.wasPaid(ticket)) {
                    return INV_T.no_show_label;
                }
                const map = INV_STATUS;
                return map[status] || (status ? String(status).replace(/_/g, ' ').toLowerCase().replace(/^\w/, c => c.toUpperCase()) : '-');
            },

            // 'ok' = lunas / aktif, 'bad' = tutup / batal, 'wait' = menunggu.
            statusTone(status) {
                if (['PAID', 'CONFIRMED', 'CHECKED_IN', 'COMPLETED', 'ACTIVE'].includes(status)) return 'ok';
                if (['EXPIRED', 'CANCELLED', 'REFUNDED', 'REFUND_PENDING'].includes(status)) return 'bad';
                return 'wait';
            },

            statusPillClass(status) {
                const tone = this.statusTone(status);
                return tone === 'ok' ? 'bg-emerald-100 text-emerald-800 border-emerald-200' : (tone === 'bad' ? 'bg-rose-100 text-rose-800 border-rose-200' : 'bg-amber-100 text-amber-900 border-amber-200');
            },

            /**
             * Download E-Ticket as PNG image
             */
            async downloadTicketPng() {
                if (!this.currentTicket) return;
                this.isDownloadingPng = true;

                try {
                    await this.generateAndSaveTicketPng();
                } catch (err) {
                    console.error('Download PNG failed:', err);
                    this.showNotice(INV_T.download_failed_title, INV_T.download_failed_message + (err.message || err), 'error', INV_T.close);
                } finally {
                    this.isDownloadingPng = false;
                }
            },

            async generateAndSaveTicketPng() {
                if (!this.currentTicket) return;

                // Rincian bayar: harga normal → potongan → alat → biaya, harus menjumlah ke TOTAL AMOUNT. Sewa lapangan di
                // order sudah dipotong benefit, jadi harga normalnya dihitung balik (dulu potongannya terpotong dua kali).
                const sumOrder = this.ticket ? this.ticket.order : null;
                const voucher = this.orderVoucher;
                const sumRows = [
                    [INV_T.png_court_rental, 'Rp ' + this.formatNumber(this.displayCourtFee + this.totalMemberDiscount + this.totalSponsorDiscount)],
                    this.totalMemberDiscount > 0 ? [INV_T.png_membership_discount, '- Rp ' + this.formatNumber(this.totalMemberDiscount)] : null,
                    this.totalSponsorDiscount > 0 ? [INV_T.png_corporate_voucher, '- Rp ' + this.formatNumber(this.totalSponsorDiscount)] : null,
                    [INV_T.png_equipment, 'Rp ' + this.formatNumber(this.displayEquipmentFee)],
                    voucher ? [INV_T.png_voucher + voucher.code + ' — ' + this.voucherDetail(voucher), '- Rp ' + this.formatNumber(voucher.discount)] : null,
                    [INV_T.png_tax_service, 'Rp ' + this.formatNumber(sumOrder ? (parseFloat(sumOrder.tax_amount) || 0) + (parseFloat(sumOrder.service_charge) || 0) : 0)],
                ].filter(Boolean);
                const rowGap = 34;
                const extraH = Math.max(0, sumRows.length - 3) * rowGap;

                const width = 840;
                const height = 1320 + extraH;
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');

                function drawRoundRect(x, y, w, h, radius, fill = true, stroke = false, fillColor = '#ffffff', strokeColor = '#E6DAC0', lineWidth = 1) {
                    ctx.save();
                    ctx.beginPath();
                    ctx.moveTo(x + radius, y);
                    ctx.lineTo(x + w - radius, y);
                    ctx.quadraticCurveTo(x + w, y, x + w, y + radius);
                    ctx.lineTo(x + w, y + h - radius);
                    ctx.quadraticCurveTo(x + w, y + h, x + w - radius, y + h);
                    ctx.lineTo(x + radius, y + h);
                    ctx.quadraticCurveTo(x, y + h, x, y + h - radius);
                    ctx.lineTo(x, y + radius);
                    ctx.quadraticCurveTo(x, y, x + radius, y);
                    ctx.closePath();
                    if (fill) {
                        ctx.fillStyle = fillColor;
                        ctx.fill();
                    }
                    if (stroke) {
                        ctx.lineWidth = lineWidth;
                        ctx.strokeStyle = strokeColor;
                        ctx.stroke();
                    }
                    ctx.restore();
                }

                // 1. Base Luxury Cream Background
                const bgGrad = ctx.createLinearGradient(0, 0, 0, height);
                bgGrad.addColorStop(0, '#FCF8EE');
                bgGrad.addColorStop(0.5, '#FFFFFF');
                bgGrad.addColorStop(1, '#FCF8EE');
                ctx.fillStyle = bgGrad;
                ctx.fillRect(0, 0, width, height);

                // Outer Gold Borders
                drawRoundRect(20, 20, width - 40, height - 40, 24, false, true, null, '#E6DAC0', 3);
                drawRoundRect(28, 28, width - 56, height - 56, 18, false, true, null, '#E6DAC0', 1);

                // 2. Ticket Header Banner
                const headerH = 175;
                ctx.save();
                ctx.beginPath();
                ctx.moveTo(40 + 20, 40);
                ctx.lineTo(width - 40 - 20, 40);
                ctx.quadraticCurveTo(width - 40, 40, width - 40, 40 + 20);
                ctx.lineTo(width - 40, 40 + headerH);
                ctx.lineTo(40, 40 + headerH);
                ctx.lineTo(40, 40 + 20);
                ctx.quadraticCurveTo(40, 40, 40 + 20, 40);
                ctx.closePath();
                ctx.clip();

                const headGrad = ctx.createLinearGradient(40, 40, width - 40, 40 + headerH);
                headGrad.addColorStop(0, '#662721');
                headGrad.addColorStop(0.6, '#4F2F2A');
                headGrad.addColorStop(1, '#4F2F2A');
                ctx.fillStyle = headGrad;
                ctx.fillRect(40, 40, width - 80, headerH);
                ctx.restore();

                // Header Badge
                drawRoundRect(60, 58, 270, 26, 13, true, true, 'rgba(223, 195, 135, 0.2)', '#E6DAC0', 1);
                ctx.fillStyle = '#F7F0DB';
                ctx.font = 'bold 11px sans-serif';
                ctx.fillText(INV_T.png_badge, 72, 75);

                // Court Name
                const courtName = (this.currentTicket.court && this.currentTicket.court.name) ? this.currentTicket.court.name : INV_T.png_court_fallback;
                ctx.fillStyle = '#FFFFFF';
                ctx.font = 'bold 26px serif';
                ctx.fillText(courtName, 60, 118);

                // Date & Time Subtitle
                const bookingDateStr = this.formatDate(this.currentTicket.booking_date);
                const bookingTimeStr = `${this.formatTime(this.currentTicket.start_time)} - ${this.formatTime(this.currentTicket.end_time)} WIB`;
                const duration = this.calculateDuration(this.currentTicket.start_time, this.currentTicket.end_time);
                ctx.fillStyle = '#A7F3D0';
                ctx.font = '14px sans-serif';
                ctx.fillText(`${bookingDateStr} • ${bookingTimeStr} (${duration} ${duration > 1 ? INV_T.png_hours : INV_T.png_hour})`, 60, 145);

                // Right-side Status Badge
                const isPaid = (this.currentTicket.status === 'PAID' || this.currentTicket.status === 'CONFIRMED' || this.currentTicket.status === 'CHECKED_IN');
                const statusText = isPaid ? (this.currentTicket.status === 'CHECKED_IN' ? INV_T.png_checked_in : INV_T.png_paid) : INV_T.png_pending;
                const statusBg = isPaid ? '#10B981' : '#F59E0B';
                const badgeW = 140;
                drawRoundRect(width - 60 - badgeW, 62, badgeW, 30, 15, true, false, statusBg);
                ctx.fillStyle = '#FFFFFF';
                ctx.font = 'bold 12px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText(statusText, width - 60 - (badgeW / 2), 82);

                // Booking Code on Header
                const bookingCode = this.currentTicket.booking_code || this.currentTicket.id.substring(0, 10);
                ctx.fillStyle = '#E6DAC0';
                ctx.font = 'bold 13px monospace';
                ctx.textAlign = 'right';
                ctx.fillText(INV_T.png_code + bookingCode, width - 60, 125);
                ctx.textAlign = 'left';

                // 3. Perforated Divider Bar
                const divY = 40 + headerH;
                const barH = 42;
                ctx.fillStyle = '#F7F0DB';
                ctx.fillRect(40, divY, width - 80, barH);
                ctx.strokeStyle = '#E6DAC0';
                ctx.lineWidth = 1;
                ctx.strokeRect(40, divY, width - 80, barH);

                ctx.save();
                ctx.setLineDash([5, 5]);
                ctx.strokeStyle = '#E6DAC0';
                ctx.beginPath();
                ctx.moveTo(50, divY + (barH / 2));
                ctx.lineTo(width - 50, divY + (barH / 2));
                ctx.stroke();
                ctx.restore();

                // Notches
                ctx.fillStyle = '#FCF8EE';
                ctx.beginPath();
                ctx.arc(40, divY + (barH / 2), 16, 0, Math.PI * 2);
                ctx.fill();
                ctx.strokeStyle = '#E6DAC0';
                ctx.lineWidth = 1.5;
                ctx.stroke();

                ctx.beginPath();
                ctx.arc(width - 40, divY + (barH / 2), 16, 0, Math.PI * 2);
                ctx.fillStyle = '#FCF8EE';
                ctx.fill();
                ctx.strokeStyle = '#E6DAC0';
                ctx.lineWidth = 1.5;
                ctx.stroke();

                // Divider Text Pill
                drawRoundRect((width / 2) - 190, divY + 8, 380, 26, 13, true, true, '#FFFFFF', '#E6DAC0', 1);
                ctx.fillStyle = '#662721';
                ctx.font = 'bold 11px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText(isPaid ? INV_T.png_status_valid : INV_T.png_status_awaiting, width / 2, divY + 25);
                ctx.textAlign = 'left';

                // 4. QR Code Hero Section
                const qrSectionY = 275;
                const qrBoxW = 280;
                const qrBoxH = 280;
                const qrBoxX = (width - qrBoxW) / 2;

                drawRoundRect(qrBoxX, qrSectionY, qrBoxW, qrBoxH, 20, true, true, '#FFFFFF', '#E6DAC0', 2);

                // QR hanya dari kode akses asli — dulu jatuh ke kode booking / teks 'CLUB61-PASS' yang pasti ditolak gate.
                const qrText = this.currentTicket.qr_code_hash || '';
                const codeLabel = this.currentTicket.qr_code_hash || this.currentTicket.booking_code || '';
                let qrLoaded = false;

                if (qrText && window.QRCode) {
                    try {
                        const qrDiv = document.createElement('div');
                        qrDiv.style.display = 'none';
                        document.body.appendChild(qrDiv);
                        new QRCode(qrDiv, {
                            text: qrText,
                            width: 220,
                            height: 220,
                            colorDark: "#662721",
                            colorLight: "#ffffff",
                            correctLevel: QRCode.CorrectLevel.H
                        });
                        await new Promise(r => setTimeout(r, 60));
                        const qrCanvasEl = qrDiv.querySelector('canvas');
                        if (qrCanvasEl) {
                            ctx.drawImage(qrCanvasEl, qrBoxX + 30, qrSectionY + 30, 220, 220);
                            qrLoaded = true;
                        }
                        document.body.removeChild(qrDiv);
                    } catch(e) {}
                }

                // Tidak ada cadangan layanan QR luar (dulu api.qrserver.com — kode akses gate terkirim ke pihak ketiga).
                if (!qrLoaded) {
                    drawRoundRect(qrBoxX + 30, qrSectionY + 30, 220, 220, 12, true, true, '#FCF8EE', '#E6DAC0', 1);
                    ctx.fillStyle = '#662721';
                    ctx.font = 'bold 14px monospace';
                    ctx.textAlign = 'center';
                    ctx.fillText(INV_T.png_qr_unavailable, width / 2, qrSectionY + 130);
                    ctx.font = '11px sans-serif';
                    ctx.fillText(INV_T.png_show_code, width / 2, qrSectionY + 155);
                    ctx.textAlign = 'left';
                }

                ctx.fillStyle = '#662721';
                ctx.font = 'bold 14px monospace';
                ctx.textAlign = 'center';
                ctx.fillText(codeLabel, width / 2, qrSectionY + qrBoxH + 28);

                ctx.fillStyle = '#7A5A52';
                ctx.font = '12px sans-serif';
                ctx.fillText(INV_T.png_present_qr, width / 2, qrSectionY + qrBoxH + 48);
                ctx.textAlign = 'left';

                // 5. Reservation Details Section
                const detailY = 660;
                drawRoundRect(45, detailY, 6, 20, 3, true, false, '#662721');
                ctx.fillStyle = '#4F2F2A';
                ctx.font = 'bold 15px sans-serif';
                ctx.fillText(INV_T.png_details, 58, detailY + 15);

                const detailBoxH = 175;
                drawRoundRect(45, detailY + 28, width - 90, detailBoxH, 16, true, true, '#FFFFFF', '#E6DAC0', 1.5);

                function drawRow(y, label, value, isBold = false, isEmerald = false) {
                    ctx.fillStyle = '#7A5A52';
                    ctx.font = '12px sans-serif';
                    ctx.fillText(label, 65, y);

                    ctx.fillStyle = isEmerald ? '#059669' : (isBold ? '#4F2F2A' : '#4F2F2A');
                    ctx.font = isBold ? 'bold 13px sans-serif' : '13px sans-serif';
                    ctx.textAlign = 'right';
                    ctx.fillText(value, width - 65, y);
                    ctx.textAlign = 'left';

                    ctx.strokeStyle = 'rgba(223, 195, 135, 0.35)';
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(65, y + 10);
                    ctx.lineTo(width - 65, y + 10);
                    ctx.stroke();
                }

                const custName = (this.customerName || 'Customer VIP');
                drawRow(detailY + 60, INV_T.png_holder, custName + INV_T.png_vip_member, true);
                drawRow(detailY + 98, INV_T.png_schedule, `${bookingTimeStr} (${duration} ${duration > 1 ? INV_T.png_hours : INV_T.png_hour})`, true);
                drawRow(detailY + 136, INV_T.png_court_venue, `${courtName} • Club 61 Padel & Wellness`, true);
                drawRow(detailY + 174, INV_T.png_checkin_status, isPaid ? INV_T.png_ready : INV_T.png_awaiting, true, isPaid);

                // 6. Payment & Fee Summary Section
                const summaryY = 885;
                drawRoundRect(45, summaryY, 6, 20, 3, true, false, '#662721');
                ctx.fillStyle = '#4F2F2A';
                ctx.font = 'bold 15px sans-serif';
                ctx.fillText(INV_T.png_summary, 58, summaryY + 15);

                const sumBoxH = 245 + extraH;
                drawRoundRect(45, summaryY + 28, width - 90, sumBoxH, 16, true, true, '#FFFFFF', '#E6DAC0', 1.5);

                const gTotal = this.displayGrandTotal;

                let payMethod = 'QRIS Instant (GoPay/OVO/BCA)';
                if (this.currentTicket.payment_method_label) {
                    payMethod = this.currentTicket.payment_method_label;
                } else if (this.currentTicket.payment_method) {
                    const mObj = this.getPaymentMethodObject(this.currentTicket.payment_method);
                    payMethod = mObj ? mObj.name : this.currentTicket.payment_method;
                } else if (this.currentTicket.order && this.currentTicket.order.payment_method) {
                    const mObj = this.getPaymentMethodObject(this.currentTicket.order.payment_method);
                    payMethod = mObj ? mObj.name : this.currentTicket.order.payment_method;
                } else if (this.selectedMethod && this.selectedMethod.name) {
                    payMethod = this.selectedMethod.name;
                }

                function drawSumRow(y, label, value, isBold = false) {
                    ctx.fillStyle = '#7A5A52';
                    ctx.font = '12px sans-serif';
                    ctx.fillText(label, 65, y, 520);

                    ctx.fillStyle = isBold ? '#4F2F2A' : '#4F2F2A';
                    ctx.font = isBold ? 'bold 13px sans-serif' : '13px sans-serif';
                    ctx.textAlign = 'right';
                    ctx.fillText(value, width - 65, y);
                    ctx.textAlign = 'left';

                    ctx.strokeStyle = 'rgba(223, 195, 135, 0.35)';
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(65, y + 10);
                    ctx.lineTo(width - 65, y + 10);
                    ctx.stroke();
                }

                // Rincian (sumRows, disusun di atas) — baris tambahan memanjangkan kotak & kanvas sebesar extraH.
                sumRows.forEach(([label, value], i) => drawSumRow(summaryY + 60 + i * rowGap, label, value, false));

                // Grand Total Highlight Banner
                const bannerY = summaryY + 160 + extraH;
                drawRoundRect(60, bannerY, width - 120, 68, 12, true, true, '#F7F0DB', '#E6DAC0', 1.5);
                ctx.fillStyle = '#4F2F2A';
                ctx.font = 'bold 14px sans-serif';
                ctx.fillText(INV_T.png_total, 80, bannerY + 32);
                ctx.fillStyle = '#7A5A52';
                ctx.font = '11px sans-serif';
                ctx.fillText(INV_T.png_paid_via + payMethod, 80, bannerY + 52);

                ctx.fillStyle = '#662721';
                ctx.font = 'bold 22px monospace';
                ctx.textAlign = 'right';
                ctx.fillText('Rp ' + this.formatNumber(gTotal), width - 80, bannerY + 42);
                ctx.textAlign = 'left';

                // 7. Footer Section
                const footerY = 1185 + extraH;
                ctx.strokeStyle = '#E6DAC0';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(80, footerY);
                ctx.lineTo(width - 80, footerY);
                ctx.stroke();

                ctx.fillStyle = '#662721';
                ctx.font = 'bold 13px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('CLUB 61 PADEL ARENA • PLAY. COMPETE. CONNECT.', width / 2, footerY + 30);

                ctx.fillStyle = '#7A5A52';
                ctx.font = '11px sans-serif';
                ctx.fillText(INV_T.png_save_note, width / 2, footerY + 50);

                const now = new Date();
                const months = INV_MONTHS;
                const timeStampStr = `${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()} ${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')} WIB`;
                ctx.fillStyle = '#A08F86';
                ctx.font = '10px monospace';
                ctx.fillText(`${INV_T.png_downloaded}${timeStampStr} • Ref: #${bookingCode}`, width / 2, footerY + 70);
                ctx.textAlign = 'left';

                // 8. Download PNG
                canvas.toBlob((blob) => {
                    if (!blob) {
                        const dataUrl = canvas.toDataURL('image/png');
                        const link = document.createElement('a');
                        link.download = `E-Ticket-Club61-${bookingCode}.png`;
                        link.href = dataUrl;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        return;
                    }
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.download = `E-Ticket-Club61-${bookingCode}.png`;
                    link.href = url;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    setTimeout(() => URL.revokeObjectURL(url), 1000);
                }, 'image/png');
            }
        }
    }
</script>

@push('scripts')
    {{-- qrcodejs dari server sendiri — QR tetap muncul walau CDN luar diblokir / sinyal venue jelek. --}}
    <script src="{{ asset('js/qrcode.min.js') }}"></script>
    @include('customer.partials.midtrans-snap')
@endpush
