{{--
    Tanda booking yang dibayar jam voucher sponsor / corporate di hasil check-in (Kelola Pemesanan & Booking System).
    Akun karyawan memakai password 6 digit terakhir HP, jadi staf mencocokkan nama pemain dengan ID card karyawan.
    Parameter: $sponsor = ['organization' => nama perusahaan] | null (booking biasa → tidak tampil apa pun).
--}}
@if (! empty($sponsor['organization']))
    <div data-checkin-sponsor style="margin-top: 0.75rem; padding: 0.65rem 0.8rem; border-radius: 10px; background: #EFF6FF; border: 1px solid #BFDBFE; color: #1E3A8A;">
        <div style="font-size: 0.8125rem; font-weight: 800;">Voucher Sponsor: {{ $sponsor['organization'] }}</div>
        <div style="font-size: 0.75rem; font-weight: 600; margin-top: 0.15rem;">Cocokkan nama pemain dengan ID card karyawan sebelum mempersilakan masuk.</div>
    </div>
@endif
