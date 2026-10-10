<script>
    function invoiceApp() {
        return {
            isLoading: true,
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
                buttonText: 'OK, Understood',
                onClose: null,
            },

            showNotice(title, message, type = 'info', buttonText = 'OK, Understood', onClose = null) {
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
            selectedMethod: (@js(app(\App\Services\Payment\OnlinePaymentMethodService::class)->forFrontend())[0]) || { id: '', code: '', name: 'Tidak ada metode tersedia', badge: '-', fee: 0, note: '' },

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
                if (!this.currentTicket) return;
                // Tagihan Rp0 tidak memakai metode bayar (server langsung melunasi) — jangan diblokir batas nominal metode.
                const isFree = Number(this.amountToPay) <= 0;
                if (! isFree && ! this.availableMethods.length) {
                    this.showNotice('Pembayaran Online Tidak Tersedia', 'Belum ada metode pembayaran online yang bisa dipakai untuk nominal ini. Silakan hubungi frontdesk.', 'error', 'Tutup');
                    return;
                }
                // Jangan diam-diam membayar dengan metode lain — beri tahu customer dulu, biar dia yang lanjutkan.
                if (! isFree && ! this.availableMethods.some(m => m.code === this.selectedMethod.code)) {
                    const previous = this.selectedMethod.name;
                    this.ensureSelectedMethodAvailable();
                    this.showNotice('Metode Pembayaran Diganti', `${previous} tidak bisa dipakai untuk nominal ini. Metode diganti ke ${this.selectedMethod.name}. Periksa lagi lalu tekan bayar.`, 'info', 'Oke');
                    return;
                }
                if (['EXPIRED', 'CANCELLED', 'REFUNDED', 'REFUND_PENDING'].includes(this.currentTicket.status)) {
                    this.showNotice('Reservation Inactive', 'This reservation has expired or has been cancelled and can no longer be processed. Please make a new booking.', 'error', 'Close');
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
                            this.showNotice('Booking Confirmed', json.message || 'Your booking is fully covered and confirmed.', 'success', 'OK');
                        } else if (json.snap_token) {
                            this.lastSnapToken = json.snap_token;
                            this.openSnap(json.snap_token, json.redirect_url);
                        }
                    } else {
                        this.showNotice('Payment Failed', json.message || 'Failed to process payment session.', 'error', 'Close');
                    }
                } catch(e) {
                    console.error('Error retry payment:', e);
                    this.showNotice('Network Issue', 'Encountered a problem connecting to the payment gateway.', 'error', 'Close');
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
                if (t.status === 'REFUND_PENDING') return 'Refund Under Review';
                if (t.status === 'REFUNDED') return 'Reservation Refunded';
                if (t.status === 'CANCELLED' && refund && refund.voucher_code) return 'Converted to Credit Voucher';
                if (t.status === 'CANCELLED') return 'Reservation Cancelled';
                return t.total_paid > 0 ? 'Match Session Expired (No-Show)' : 'Payment Window Expired';
            },

            closedTicketMessage(t) {
                const refund = t.refund_info || null;
                const amount = refund ? 'Rp ' + this.formatNumber(Math.round(refund.amount)) : '';
                if (t.status === 'REFUND_PENDING') return `This reservation has been cancelled and the court slot released. Your refund of ${amount} is being reviewed by the club.`;
                if (t.status === 'REFUNDED') return `Your refund of ${amount} has been processed and returned by the club administration.`;
                if (t.status === 'CANCELLED' && refund && refund.voucher_code) {
                    return `The refund could not be returned as cash, so ${amount} was saved as credit voucher ${refund.voucher_code}` + (refund.voucher_valid_until ? ` (valid until ${refund.voucher_valid_until})` : '') + '. It appears automatically at checkout for your next booking.';
                }
                if (t.status === 'CANCELLED') return 'This reservation was cancelled and the slot has been returned to the schedule.';
                return t.total_paid > 0 ? 'Your scheduled match time has passed without turnstile check-in. This ticket is now closed.' : 'The 15-minute payment window for this session has ended and the court slots have been released. Please book a new schedule.';
            },

            renderQr(el, text, attempt = 0) {
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
                    if (attempt < 40) setTimeout(() => this.renderQr(el, text, attempt + 1), 150);
                    return;
                }

                el.innerHTML = '';
                new QRCode(el, { text, width: 220, height: 220, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.H });
                el.querySelectorAll('img, canvas').forEach(node => { node.style.width = '100%'; node.style.height = '100%'; });
                el._qrDrawn = text;
            },

            // Pesanan membership online yang belum dibayar: lanjutkan bayar (order yang sama) / batalkan.
            showCancelMembershipModal: false,
            isCancellingMembership: false,
            membershipPollTimer: null,
            membershipPollId: null,

            async payMembership() {
                const t = this.currentTicket;
                if (!t || !t.can_pay_online) return;
                if (! this.availableMethods.length) {
                    this.showNotice('Pembayaran Online Tidak Tersedia', 'Belum ada metode pembayaran online yang bisa dipakai untuk nominal ini. Silakan hubungi frontdesk.', 'error', 'Tutup');
                    return;
                }
                if (! this.availableMethods.some(m => m.code === this.selectedMethod.code)) {
                    const previous = this.selectedMethod.name;
                    this.ensureSelectedMethodAvailable();
                    this.showNotice('Metode Pembayaran Diganti', `${previous} tidak bisa dipakai untuk nominal ini. Metode diganti ke ${this.selectedMethod.name}. Periksa lagi lalu tekan bayar.`, 'info', 'Oke');
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
                        this.showNotice('Pembayaran Gagal', json.message || 'Sesi pembayaran belum bisa dibuat.', 'error', 'Tutup');
                        await this.refreshMembershipTicket(t.id);
                        return;
                    }

                    const payment = json.data.payment || {};
                    if (json.data.already_paid || payment.is_mock) {
                        await this.refreshMembershipTicket(t.id);
                        this.showNotice('Membership Aktif', json.message, 'success', 'Oke');
                    } else if (payment.snap_token && window.snap) {
                        window.snap.pay(payment.snap_token, {
                            onSuccess: () => this.pollMembership(t.id),
                            onPending: () => this.pollMembership(t.id),
                            onError: () => this.showNotice('Payment Declined', 'Payment was declined or failed to process.', 'error', 'Close'),
                            onClose: () => this.pollMembership(t.id),
                        });
                    } else if (payment.redirect_url || payment.payment_url) {
                        window.location.href = payment.redirect_url || payment.payment_url;
                    }
                } catch (e) {
                    console.error('Error membership payment:', e);
                    this.showNotice('Network Issue', 'Encountered a problem connecting to the payment gateway.', 'error', 'Close');
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
                    this.showNotice(json.success ? 'Pesanan Dibatalkan' : 'Tidak Bisa Dibatalkan', json.message, json.success ? 'success' : 'error', 'Oke');
                } catch (e) {
                    console.error('Error cancel membership order:', e);
                    this.showNotice('Server Error', 'An unexpected error occurred while communicating with the server.', 'error', 'Close');
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
                            this.showNotice('Membership Aktif', 'Pembayaran diterima. Paket membership Anda sudah aktif.', 'success', 'Oke');
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
                    this.showNotice('Access Denied', 'You do not have permission to cancel this booking.', 'error', 'Close');
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
                    this.showNotice('Access Denied', 'You do not have permission to cancel this booking.', 'error', 'Close');
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
                        this.showNotice('Cancellation Failed', json.message || 'Failed to cancel booking.', 'error', 'Close');
                        this.isCancellingBooking = false;
                    }
                } catch (e) {
                    console.error('Error cancel booking:', e);
                    this.showNotice('Server Error', 'An unexpected error occurred while communicating with the server.', 'error', 'Close');
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
                            this.showNotice('Payment Declined', 'Payment was declined or failed to process.', 'error', 'Close');
                        },
                        onClose: () => {
                            this.startAutoPolling(this.currentTicket.id);
                        }
                    });
                } else if (redirectUrl) {
                    // Popup Snap tidak termuat (client key kosong / snap.js diblokir) → halaman pembayaran Midtrans.
                    window.location.href = redirectUrl;
                } else {
                    this.showNotice('Loading Gateway', 'Midtrans payment gateway component is loading. Please try again shortly.', 'info', 'Close');
                }
            },

            async init() {
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
                    // Batas bayar dari server (+3 menit jeda notifikasi). Selisih reschedule tidak punya batas → 20 menit.
                    const exp = t && ['PENDING', 'PENDING_PAYMENT'].includes(t.status) && t.expires_at ? new Date(t.expires_at).getTime() : NaN;
                    return isNaN(exp) ? startedAt + 20 * 60 * 1000 : exp + 3 * 60 * 1000;
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
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                const plain = String(val).match(/^(\d{4})-(\d{2})-(\d{2})$/);
                if (plain) return `${plain[3]} ${months[Number(plain[2]) - 1]} ${plain[1]}`;
                try {
                    const d = new Date(val);
                    if (!isNaN(d.getTime())) {
                        const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: this.appTimezone, day: '2-digit', month: 'short', year: 'numeric' })
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

            // Label status yang ramah dibaca (bukan kode mentah seperti PENDING_PAYMENT).
            statusLabel(status) {
                const map = {
                    PAID: 'Paid', CONFIRMED: 'Confirmed', CHECKED_IN: 'Checked in', COMPLETED: 'Completed', ACTIVE: 'Active',
                    PENDING: 'Awaiting payment', PENDING_PAYMENT: 'Awaiting payment', UNPAID: 'Unpaid', PARTIALLY_PAID: 'Partly paid',
                    LOCKED: 'In checkout', EXPIRED: 'Expired', CANCELLED: 'Cancelled', REFUND_PENDING: 'Refund pending', REFUNDED: 'Refunded',
                };
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
                    this.showNotice('Download Failed', 'Failed to generate e-ticket image: ' + (err.message || err), 'error', 'Close');
                } finally {
                    this.isDownloadingPng = false;
                }
            },

            async generateAndSaveTicketPng() {
                if (!this.currentTicket) return;

                const width = 840;
                const height = 1320;
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
                ctx.fillText('OFFICIAL BOARDING PASS • PADEL PASS', 72, 75);

                // Court Name
                const courtName = (this.currentTicket.court && this.currentTicket.court.name) ? this.currentTicket.court.name : 'Court Arena';
                ctx.fillStyle = '#FFFFFF';
                ctx.font = 'bold 26px serif';
                ctx.fillText(courtName, 60, 118);

                // Date & Time Subtitle
                const bookingDateStr = this.formatDate(this.currentTicket.booking_date);
                const bookingTimeStr = `${this.formatTime(this.currentTicket.start_time)} - ${this.formatTime(this.currentTicket.end_time)} WIB`;
                const duration = this.calculateDuration(this.currentTicket.start_time, this.currentTicket.end_time);
                ctx.fillStyle = '#A7F3D0';
                ctx.font = '14px sans-serif';
                ctx.fillText(`${bookingDateStr} • ${bookingTimeStr} (${duration} ${duration > 1 ? 'Hours' : 'Hour'})`, 60, 145);

                // Right-side Status Badge
                const isPaid = (this.currentTicket.status === 'PAID' || this.currentTicket.status === 'CONFIRMED' || this.currentTicket.status === 'CHECKED_IN');
                const statusText = isPaid ? (this.currentTicket.status === 'CHECKED_IN' ? 'CHECKED IN' : 'PAID') : 'PENDING';
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
                ctx.fillText('Code: #' + bookingCode, width - 60, 125);
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
                ctx.fillText(`STATUS: ${isPaid ? 'VALID ENTRY PASS' : 'AWAITING PAYMENT'} • GATE: FRONTDESK`, width / 2, divY + 25);
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
                    ctx.fillText('QR NOT AVAILABLE', width / 2, qrSectionY + 130);
                    ctx.font = '11px sans-serif';
                    ctx.fillText('Show booking code at frontdesk', width / 2, qrSectionY + 155);
                    ctx.textAlign = 'left';
                }

                ctx.fillStyle = '#662721';
                ctx.font = 'bold 14px monospace';
                ctx.textAlign = 'center';
                ctx.fillText(codeLabel, width / 2, qrSectionY + qrBoxH + 28);

                ctx.fillStyle = '#7A5A52';
                ctx.font = '12px sans-serif';
                ctx.fillText('Present this QR Code to frontdesk staff / turnstile gate upon arrival', width / 2, qrSectionY + qrBoxH + 48);
                ctx.textAlign = 'left';

                // 5. Reservation Details Section
                const detailY = 660;
                drawRoundRect(45, detailY, 6, 20, 3, true, false, '#662721');
                ctx.fillStyle = '#4F2F2A';
                ctx.font = 'bold 15px sans-serif';
                ctx.fillText('MATCH RESERVATION DETAILS', 58, detailY + 15);

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
                drawRow(detailY + 60, 'Ticket Holder Name', custName + ' (VIP Member)', true);
                drawRow(detailY + 98, 'Booking Schedule', `${bookingTimeStr} (${duration} ${duration > 1 ? 'Hours' : 'Hour'})`, true);
                drawRow(detailY + 136, 'Court & Venue', `${courtName} • Indoor Central AC`, true);
                drawRow(detailY + 174, 'Check-In Status', isPaid ? 'READY FOR ENTRY (VALID)' : 'AWAITING PAYMENT', true, isPaid);

                // 6. Payment & Fee Summary Section
                const summaryY = 885;
                drawRoundRect(45, summaryY, 6, 20, 3, true, false, '#662721');
                ctx.fillStyle = '#4F2F2A';
                ctx.font = 'bold 15px sans-serif';
                ctx.fillText('PAYMENT & FEE SUMMARY', 58, summaryY + 15);

                const sumBoxH = 245;
                drawRoundRect(45, summaryY + 28, width - 90, sumBoxH, 16, true, true, '#FFFFFF', '#E6DAC0', 1.5);

                const cFee = this.displayCourtFee;
                const eFee = this.displayEquipmentFee;
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
                    ctx.fillText(label, 65, y);

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

                // Rincian harus menjumlah ke TOTAL AMOUNT: diskon mengurangi sewa lapangan,
                // pajak & biaya layanan (per order, dari Pengaturan Biaya & Pajak) ditampilkan terpisah.
                const discount = this.totalMemberDiscount + this.totalSponsorDiscount;
                const order = this.ticket ? this.ticket.order : null;
                const taxAndService = order ? (parseFloat(order.tax_amount) || 0) + (parseFloat(order.service_charge) || 0) : 0;

                drawSumRow(summaryY + 60, discount > 0 ? 'Padel Court Rental (after discount)' : 'Padel Court Rental', 'Rp ' + this.formatNumber(Math.max(0, cFee - discount)), false);
                drawSumRow(summaryY + 98, 'Equipment Rental (Rackets & Balls)', 'Rp ' + this.formatNumber(eFee), false);
                drawSumRow(summaryY + 136, 'Tax & Service Fee', 'Rp ' + this.formatNumber(taxAndService), false);

                // Grand Total Highlight Banner
                drawRoundRect(60, summaryY + 160, width - 120, 68, 12, true, true, '#F7F0DB', '#E6DAC0', 1.5);
                ctx.fillStyle = '#4F2F2A';
                ctx.font = 'bold 14px sans-serif';
                ctx.fillText('TOTAL AMOUNT', 80, summaryY + 192);
                ctx.fillStyle = '#7A5A52';
                ctx.font = '11px sans-serif';
                ctx.fillText('Paid via ' + payMethod, 80, summaryY + 212);

                ctx.fillStyle = '#662721';
                ctx.font = 'bold 22px monospace';
                ctx.textAlign = 'right';
                ctx.fillText('Rp ' + this.formatNumber(gTotal), width - 80, summaryY + 202);
                ctx.textAlign = 'left';

                // 7. Footer Section
                const footerY = 1185;
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
                ctx.fillText('Save this digital e-ticket to your device as official proof of reservation.', width / 2, footerY + 50);

                const now = new Date();
                const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                const timeStampStr = `${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()} ${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')} WIB`;
                ctx.fillStyle = '#A08F86';
                ctx.font = '10px monospace';
                ctx.fillText(`Downloaded: ${timeStampStr} • Ref: #${bookingCode}`, width / 2, footerY + 70);
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
