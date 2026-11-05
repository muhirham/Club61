<?php

namespace App\Models\Sponsor;

use App\Models\Membership\UserMembership;
use App\Models\Membership\UserMembershipBalance;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SponsorOrganization extends Model
{
    use HasUlids, SoftDeletes;

    public const STATUS_ACTIVE = 'ACTIVE';

    /** Ditangguhkan admin (mis. kontrak belum dibayar): PIC tidak bisa rilis jam & voucher karyawan tidak bisa dipakai. */
    public const STATUS_SUSPENDED = 'SUSPENDED';

    /** Hak Akses PIC: aksi di halaman Tim Corporate yang bisa dimatikan admin per perusahaan (Kelola Sponsor > Edit). */
    public const PIC_MANAGE_MEMBERS = 'MANAGE_MEMBERS';

    public const PIC_RELEASE_SINGLE = 'RELEASE_SINGLE';

    public const PIC_RELEASE_BULK = 'RELEASE_BULK';

    public const PIC_EDIT_VOUCHERS = 'EDIT_VOUCHERS';

    public const PIC_ACTIONS = [
        self::PIC_MANAGE_MEMBERS => 'Menambah & mengaktifkan kembali karyawan (manual dan impor CSV)',
        self::PIC_RELEASE_SINGLE => 'Memberikan jam ke karyawan satu per satu (termasuk jam awal saat menambah karyawan dan kolom jam di CSV)',
        self::PIC_RELEASE_BULK => 'Memberikan jam ke semua karyawan sekaligus',
        self::PIC_EDIT_VOUCHERS => 'Mengubah & menghapus voucher jam karyawan',
    ];

    protected $fillable = [
        'name',
        'user_membership_id',
        'sponsor_admin_user_id',
        'status',
        'pic_restricted_actions',
    ];

    protected function casts(): array
    {
        return [
            'pic_restricted_actions' => 'array',
        ];
    }

    /** Aksi PIC yang diizinkan. Kosong / NULL di kolom = semua boleh (perusahaan lama & default). */
    public function picAllows(string $action): bool
    {
        return ! in_array($action, (array) ($this->pic_restricted_actions ?? []), true);
    }

    /** @return array<int, string> */
    public function picAllowedActions(): array
    {
        return array_values(array_filter(array_keys(self::PIC_ACTIONS), fn (string $action) => $this->picAllows($action)));
    }

    public function userMembership(): BelongsTo
    {
        return $this->belongsTo(UserMembership::class, 'user_membership_id');
    }

    public function sponsorAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_admin_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(SponsorOrganizationMember::class, 'sponsor_organization_id');
    }

    public function accessSchedules(): HasMany
    {
        return $this->hasMany(SponsorAccessSchedule::class, 'sponsor_organization_id');
    }

    /**
     * Total jam yang SUDAH PERNAH dirilis (lifetime) ke seluruh anggota organisasi ini — termasuk
     * voucher yang sudah expired atau anggotanya sudah di-revoke. Ini murni informasional (jumlah
     * dari tabel voucher), independen dari ada/tidaknya kuota kontrak di bawah.
     */
    public function isSuspended(): bool
    {
        return $this->status !== self::STATUS_ACTIVE;
    }

    public function totalHoursReleased(): float
    {
        return (float) SponsorMemberVoucher::whereHas('member', fn ($q) => $q->where('sponsor_organization_id', $this->id))
            ->sum('hours_granted');
    }

    /**
     * Saldo kuota jam bermain PADEL milik organisasi ini — BUKAN field terpisah, tapi diambil
     * langsung dari benefit paket membership yang dibeli (dikonfigurasi staf lewat Master Data >
     * Paket Membership > Matriks Entitlement Fasilitas, facility=PADEL, quota_type=HOURS), lalu
     * disalin jadi UserMembershipBalance saat membership aktif. Null kalau paket ini tidak
     * dikonfigurasi kuota jam PADEL sama sekali (facility PADEL tidak ada atau quota_type-nya
     * bukan HOURS) — artinya organisasi ini TIDAK BISA merilis jam voucher (lihat SponsorOrganizationService::deductFromOrganizationQuota).
     */
    public function padelHourBalance(): ?UserMembershipBalance
    {
        $balance = $this->userMembership?->balanceFor('PADEL');

        return ($balance && $balance->quota_type === 'HOURS') ? $balance : null;
    }

    /**
     * Total kuota kontrak (mis. "200 jam" dari paket) — null kalau paket tidak punya kuota jam PADEL.
     */
    public function totalQuota(): ?float
    {
        return $this->padelHourBalance()?->initial_quota !== null
            ? (float) $this->padelHourBalance()->initial_quota
            : null;
    }

    /**
     * Sisa kuota kontrak yang masih boleh dirilis staf/PIC ke tim — berkurang otomatis tiap kali
     * voucher dirilis (lihat SponsorOrganizationService), lewat ledger MembershipBalanceService
     * yang sama dengan yang dipakai fasilitas lain (audit trail lengkap di MembershipUsageLog).
     * Null berarti paket tidak punya kuota jam PADEL (rilis jam ditolak).
     */
    public function remainingQuota(): ?float
    {
        $balance = $this->padelHourBalance();

        return $balance !== null ? (float) $balance->remaining_quota : null;
    }
}
