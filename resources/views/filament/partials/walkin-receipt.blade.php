{{--
    Struk POS Walk-In Padel — SATU sumber untuk struk transaksi baru, modal struk, dan cetak ulang dari
    Riwayat Transaksi. Param: $receipt (lihat BookOfflineCourt::buildWalkInReceipt()).
--}}
<div id="printable-pos-receipt"
                            style="background:#FFFFFF; border:1px solid #E5E7EB; box-shadow:0 4px 15px rgba(0,0,0,0.06); padding:1.5rem; width:100%; max-width:420px; font-family:monospace; font-size:0.75rem; color:#111827; border-radius:8px;">
                            <div
                                style="text-align:center; border-bottom:1px dashed #000; padding-bottom:0.75rem; margin-bottom:0.75rem;">
                                <div style="font-weight:900; font-size:1rem; letter-spacing:0.05em;">CLUB 61 PADEL
                                    ARENA</div>
                                <div style="font-size:0.65rem; color:#4B5563;">{{ \App\Models\Setting\CompanyProfileSetting::receiptAddress() }}</div>
                                <div style="font-size:0.65rem; color:#4B5563;">Frontdesk &amp; Reservation Counter
                                </div>
                            </div>

                            <div
                                style="border-bottom:1px dashed #000; padding-bottom:0.5rem; margin-bottom:0.5rem; line-height:1.4;">
                                <div>No. Order: <strong>{{ $receipt['order_number'] }}</strong></div>
                                <div>Waktu: {{ $receipt['created_at'] }}</div>
                                <div>Kasir: {{ $receipt['cashier_name'] }}</div>
                                <div>Customer: {{ $receipt['customer_name'] }}
                                    ({{ $receipt['customer_phone'] }})</div>
                                <div>Metode: <strong>{{ $receipt['payment_method'] }}</strong></div>

                                @if (!empty($receipt['payment_meta']))
                                    @php $pm = $receipt['payment_meta']; @endphp
                                    @if (isset($pm['terminal']) || isset($pm['card_last_4']))
                                        <div style="font-size:0.65rem; color:#4B5563; margin-top:0.2rem;">
                                            Kartu:
                                            {{ $pm['card_type'] ?? 'CARD' }}{{ !empty($pm['card_network']) ? ' (' . $pm['card_network'] . ')' : '' }}
                                            &bull; {{ $pm['card_issuer'] ?? '' }} (**** {{ $pm['card_last_4'] }})
                                        </div>
                                        <div style="font-size:0.65rem; color:#4B5563;">
                                            Appr: {{ $pm['approval_code'] }} &bull; Trace: {{ $pm['trace_number'] }}
                                            &bull; Mesin: {{ $pm['terminal'] ?? '-' }}
                                        </div>
                                    @elseif(isset($pm['qris_provider']))
                                        <div style="font-size:0.65rem; color:#4B5563; margin-top:0.2rem;">
                                            QRIS: {{ $pm['qris_provider'] }} &bull; RRN: {{ $pm['qris_rrn'] }}
                                        </div>
                                    @elseif(isset($pm['cash_received']))
                                        <div style="font-size:0.65rem; color:#4B5563; margin-top:0.2rem;">
                                            Tunai: Rp {{ number_format($pm['cash_received'], 0, ',', '.') }} &bull;
                                            Kembali: Rp {{ number_format($pm['cash_change'], 0, ',', '.') }}
                                        </div>
                                    @endif
                                @endif
                            </div>

                            <div style="border-bottom:1px dashed #000; padding-bottom:0.5rem; margin-bottom:0.5rem;">
                                <div style="font-weight:800; margin-bottom:0.25rem;">ITEM LAPANGAN:</div>
                                @foreach ($receipt['bookings'] as $b)
                                    <div wire:key="receipt-booking-{{ $b['booking_code'] }}"
                                        style="margin-bottom:0.35rem;">
                                        @php
                                            $fmtJam = fn ($h) => rtrim(rtrim(number_format((float) $h, 1, ',', '.'), '0'), ',');
                                        @endphp
                                        <div style="display:flex; justify-content:space-between;">
                                            <span>{{ $b['court_name'] }}</span>
                                            {{-- Harga normal; potongan benefit ditulis di bawahnya (struk Rp0 tetap jelas apa yang ditanggung). --}}
                                            <span>Rp {{ number_format($b['normal_fee'] ?? $b['court_fee'], 0, ',', '.') }}</span>
                                        </div>
                                        <div style="font-size:0.625rem; color:#4B5563;">
                                            {{ $receipt['booking_date'] }} &bull; {{ $b['time_label'] }}
                                            WIB</div>
                                        @if (($b['member_discount'] ?? 0) > 0)
                                            <div style="display:flex; justify-content:space-between; font-size:0.65rem; color:#047857;">
                                                <span>&nbsp;&nbsp;{{ ($b['member_hours'] ?? 0) > 0 ? 'Kuota Member ('.$fmtJam($b['member_hours']).' jam)' : 'Diskon Member' }}</span>
                                                <span>- Rp {{ number_format($b['member_discount'], 0, ',', '.') }}</span>
                                            </div>
                                        @endif
                                        @if (($b['sponsor_discount'] ?? 0) > 0)
                                            <div style="display:flex; justify-content:space-between; font-size:0.65rem; color:#047857;">
                                                <span>&nbsp;&nbsp;Jam Corporate{{ ! empty($b['sponsor_org']) ? ' '.$b['sponsor_org'] : '' }} ({{ $fmtJam($b['sponsor_hours'] ?? 0) }} jam)</span>
                                                <span>- Rp {{ number_format($b['sponsor_discount'], 0, ',', '.') }}</span>
                                            </div>
                                        @endif
                                        <div style="font-size:0.625rem; font-weight:800; color:#1F170D;">Kode:
                                            {{ $b['booking_code'] }}</div>
                                    </div>
                                @endforeach

                                @if (!empty($receipt['equipments']))
                                    <div style="font-weight:800; margin-top:0.4rem; margin-bottom:0.2rem;">SEWA ALAT:
                                    </div>
                                    @foreach ($receipt['equipments'] as $eqIdx => $eq)
                                        <div wire:key="receipt-equipment-{{ $eqIdx }}"
                                            style="display:flex; justify-content:space-between;">
                                            <span>{{ $eq['quantity'] }}x {{ $eq['name'] }}</span>
                                            <span>Rp {{ number_format($eq['price'], 0, ',', '.') }}</span>
                                        </div>
                                    @endforeach
                                @endif

                                @if (!empty($receipt['discount_amount']) && $receipt['discount_amount'] > 0)
                                    <div
                                        style="display:flex; justify-content:space-between; font-size:0.65rem; margin-top:0.35rem; color:#047857;">
                                        <span>Voucher {{ $receipt['voucher_code'] ?? '' }}</span>
                                        <span>- Rp
                                            {{ number_format($receipt['discount_amount'], 0, ',', '.') }}</span>
                                    </div>
                                @endif
                                @if (!empty($receipt['tax_amount']) && $receipt['tax_amount'] > 0)
                                    <div
                                        style="display:flex; justify-content:space-between; font-size:0.65rem; margin-top:0.35rem; color:#4B5563;">
                                        <span>{{ $receipt['tax_name'] ?? 'Pajak Daerah' }}</span>
                                        <span>Rp
                                            {{ number_format($receipt['tax_amount'], 0, ',', '.') }}</span>
                                    </div>
                                @endif
                                @if (!empty($receipt['service_charge']) && $receipt['service_charge'] > 0)
                                    <div
                                        style="display:flex; justify-content:space-between; font-size:0.65rem; margin-top:0.15rem; color:#4B5563;">
                                        <span>{{ $receipt['admin_fee_name'] ?? 'Biaya Layanan' }}</span>
                                        <span>Rp
                                            {{ number_format($receipt['service_charge'], 0, ',', '.') }}</span>
                                    </div>
                                @endif
                            </div>

                            <div style="border-bottom:1px dashed #000; padding-bottom:0.5rem; margin-bottom:0.6rem;">
                                @if (($receipt['receipt_type'] ?? 'SALE') === 'SETTLEMENT')
                                    {{-- Struk pelunasan: total order (sudah termasuk jadwal baru) + yang dibayar SEKARANG --}}
                                    <div style="display:flex; justify-content:space-between; font-size:0.7rem;">
                                        <span>Total Order</span>
                                        <span>Rp {{ number_format($receipt['grand_total'], 0, ',', '.') }}</span>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; font-size:0.7rem; color:#4B5563;">
                                        <span>Sudah dibayar sebelumnya</span>
                                        <span>Rp {{ number_format($receipt['paid_before'], 0, ',', '.') }}</span>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; font-weight:900; font-size:0.9375rem; margin-top:0.2rem;">
                                        <span>{{ $receipt['settlement_label'] ?? 'PELUNASAN' }}:</span>
                                        <span>Rp {{ number_format($receipt['paid_now'], 0, ',', '.') }}</span>
                                    </div>
                                    @if (! empty($receipt['schedule_before']))
                                        <div style="font-size:0.625rem; color:#4B5563; margin-top:0.2rem;">Dipindah dari: {{ $receipt['schedule_before'] }}</div>
                                    @endif
                                @else
                                    <div
                                        style="display:flex; justify-content:space-between; font-weight:900; font-size:0.9375rem;">
                                        <span>TOTAL BAYAR:</span>
                                        <span>Rp
                                            {{ number_format($receipt['grand_total'], 0, ',', '.') }}</span>
                                    </div>
                                    @if (! empty($receipt['note']))
                                        <div style="font-size:0.625rem; color:#4B5563; margin-top:0.2rem;">{{ $receipt['note'] }}</div>
                                    @endif
                                @endif
                                @foreach ($receipt['benefit_notes'] ?? [] as $benefitNote)
                                    <div style="font-size:0.65rem; margin-top:0.2rem;">{{ $benefitNote }}</div>
                                @endforeach
                                <div style="font-size:0.65rem; margin-top:0.2rem;">
                                    Status: <strong>LUNAS
                                        (PAID){{ ! empty($receipt['auto_checked_in']) ? ' — CHECKED IN' : '' }}</strong>
                                </div>
                                @if (! empty($receipt['is_reprint']))
                                    <div style="font-size:0.625rem; font-weight:900; margin-top:0.25rem;">*** CETAK ULANG {{ $receipt['reprinted_at'] ?? '' }} ***</div>
                                @endif
                                {{-- Dibuka dari Buku Transaksi (Modul 17) — bukan cetak ulang kasir. --}}
                                @if (! empty($receipt['admin_copy_at']))
                                    <div style="font-size:0.625rem; font-weight:900; margin-top:0.25rem;">*** SALINAN ADMIN {{ $receipt['admin_copy_at'] }} ***</div>
                                @endif
                            </div>

                            <div style="text-align:center; font-size:0.625rem; color:#4B5563; line-height:1.3;">
                                <div>Terima kasih telah bermain di Club 61!</div>
                                <div>Tunjukkan struk ini kepada petugas lapangan.</div>
                            </div>
                        </div>
