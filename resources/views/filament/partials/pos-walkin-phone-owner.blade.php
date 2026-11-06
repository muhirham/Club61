{{--
    Modul 33 (Walk-In di kasir POS): nomor HP yang diketik sudah punya akun → transaksi masuk ke akun itu. Nama pemiliknya
    ditampilkan agar resepsionis mencocokkan dengan customer (nomor HP tidak diverifikasi OTP).
    Param: $owner = ['name' => …, 'phone' => …] | null (ResolvesPosCustomer::getWalkInPhoneOwnerProperty).
--}}
@if (! empty($owner))
    <div data-walkin-phone-owner style="margin-top:0.3rem; background:#EFF6FF; border:1px solid #BFDBFE; border-radius:8px; padding:0.4rem 0.55rem; font-size:0.6875rem; color:#1E3A8A; line-height:1.4;">
        Nomor ini sudah terdaftar atas nama <strong>{{ $owner['name'] }}</strong>. Transaksi akan masuk ke akun tersebut. Pastikan sesuai dengan customer.
    </div>
@endif
