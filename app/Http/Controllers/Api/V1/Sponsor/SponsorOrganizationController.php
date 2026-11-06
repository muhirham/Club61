<?php

namespace App\Http\Controllers\Api\V1\Sponsor;

use App\Http\Controllers\Controller;
use App\Models\Sponsor\SponsorMemberVoucher;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\Sponsor\SponsorOrganizationMember;
use App\Services\Audit\ActivityLogger;
use App\Services\Sponsor\SponsorOrganizationService;
use App\Support\PersonName;
use App\Support\SiteLocale;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SponsorOrganizationController extends Controller
{
    public function __construct(protected SponsorOrganizationService $service) {}

    /**
     * Ringkasan akun corporate milik PIC yang sedang login: kontrak, jumlah anggota aktif,
     * dan jadwal akses lapangan (READ-ONLY — jadwal ditentukan & diubah oleh staf venue lewat
     * panel admin, bukan oleh PIC).
     */
    public function show(Request $request): JsonResponse
    {
        $organization = $this->resolveOwnOrganization($request)
            ->load(['userMembership.plan', 'accessSchedules' => fn ($q) => $q->where('valid_until', '>=', now()->toDateString())->orderBy('valid_from')]);

        $activeMemberCount = SponsorOrganizationMember::where('sponsor_organization_id', $organization->id)
            ->where('status', 'ACTIVE')
            ->count();

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('corporate.summary_loaded'),
            'data' => [
                'organization' => $organization,
                'active_member_count' => $activeMemberCount,
                'total_hours_released' => $organization->totalHoursReleased(),
                'quota_remaining' => $organization->remainingQuota(),
                'pic_allowed_actions' => $organization->picAllowedActions(),
            ],
        ]);
    }

    /**
     * Daftar roster anggota tim beserta total sisa jam voucher aktif masing-masing.
     */
    public function members(Request $request): JsonResponse
    {
        $organization = $this->resolveOwnOrganization($request);
        $this->authorize('view', $organization);

        $members = SponsorOrganizationMember::where('sponsor_organization_id', $organization->id)
            ->with(['user:id,name,phone,email', 'vouchers'])
            ->latest('created_at')
            ->paginate(20);

        $members->getCollection()->transform(function (SponsorOrganizationMember $member) {
            $member->total_remaining_hours = $member->totalRemainingHours();

            return $member;
        });

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('corporate.members_loaded'),
            'data' => $members,
        ]);
    }

    /**
     * Tambah anggota tim secara manual (Nama, No HP, jam voucher awal opsional).
     */
    public function addMember(Request $request): JsonResponse
    {
        $organization = $this->resolveOwnOrganization($request);
        $this->authorize('manageMembers', $organization);
        $this->authorize('performPicAction', [$organization, SponsorOrganization::PIC_MANAGE_MEMBERS]);

        $validated = $request->validate([
            'name' => PersonName::rules(),
            'phone' => 'required|string|max:20',
            'initial_hours' => 'nullable|numeric|min:0.01',
        ]);

        if (isset($validated['initial_hours'])) {
            $this->authorize('performPicAction', [$organization, SponsorOrganization::PIC_RELEASE_SINGLE]);
        }

        try {
            $member = $this->service->addMemberManually(
                $organization,
                $validated['name'],
                $validated['phone'],
                isset($validated['initial_hours']) ? (float) $validated['initial_hours'] : null,
                $request->user()
            );
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('corporate.member_added'),
            'data' => $member->load(['user:id,name,phone,email', 'vouchers']),
        ], 201);
    }

    /**
     * Terbitkan voucher jam baru untuk 1 anggota (expired 1 bulan dari sekarang). Voucher lama
     * yang belum expired TIDAK digabung/direset — anggota bisa punya beberapa voucher aktif
     * sekaligus.
     */
    public function releaseVoucher(Request $request, string $member): JsonResponse
    {
        $organization = $this->resolveOwnOrganization($request);
        $this->authorize('manageMembers', $organization);
        $this->authorize('performPicAction', [$organization, SponsorOrganization::PIC_RELEASE_SINGLE]);

        $memberModel = $this->resolveOwnMember($organization, $member);

        $validated = $request->validate([
            'hours' => 'required|numeric|min:0.01',
        ]);

        try {
            $voucher = $this->service->releaseVoucher($memberModel, (float) $validated['hours'], 'MANUAL_RELEASE', $request->user());
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $memberName = $memberModel->user?->name ?? 'karyawan';
        $this->logPicAction($organization, 'sponsor.voucher_released', 'PIC memberikan '.$this->hours($voucher->hours_granted).' jam ke '.$memberName, [
            'karyawan' => $memberName,
            'jam' => (float) $voucher->hours_granted,
        ]);

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('corporate.voucher_released'),
            'data' => $voucher,
        ], 201);
    }

    /**
     * Rilis voucher manual ke SEMUA anggota aktif sekaligus — mode 'uniform' (jam sama rata
     * buat semua) atau 'per_member' (jam beda-beda, PIC isi satu-satu dalam 1 form).
     */
    public function bulkReleaseVoucher(Request $request): JsonResponse
    {
        $organization = $this->resolveOwnOrganization($request);
        $this->authorize('manageMembers', $organization);
        $this->authorize('performPicAction', [$organization, SponsorOrganization::PIC_RELEASE_BULK]);

        $validated = $request->validate([
            'mode' => 'required|in:uniform,per_member',
            'hours' => 'required_if:mode,uniform|nullable|numeric|min:0.01',
            'allocations' => 'required_if:mode,per_member|nullable|array',
            'allocations.*.member_id' => 'required_with:allocations|string',
            'allocations.*.hours' => 'required_with:allocations|numeric|min:0.01',
        ]);

        if ($validated['mode'] === 'uniform') {
            $activeMemberIds = SponsorOrganizationMember::where('sponsor_organization_id', $organization->id)
                ->where('status', 'ACTIVE')
                ->pluck('id');
            $map = $activeMemberIds->mapWithKeys(fn ($id) => [$id => (float) $validated['hours']])->toArray();
        } else {
            $map = collect($validated['allocations'] ?? [])
                ->mapWithKeys(fn ($a) => [$a['member_id'] => (float) $a['hours']])
                ->toArray();
        }

        $result = $this->service->bulkReleaseVoucher($organization, $map, $request->user());

        if ($result['released'] > 0) {
            $this->logPicAction($organization, 'sponsor.voucher_bulk_released', 'PIC memberikan jam ke '.$result['released'].' karyawan sekaligus', [
                'mode' => $validated['mode'] === 'uniform' ? 'Jam sama rata' : 'Jam per karyawan',
                'berhasil' => $result['released'],
                'gagal' => count($result['failed']),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('corporate.bulk_released', ['released' => $result['released'], 'failed' => count($result['failed'])]),
            'data' => $result,
        ]);
    }

    /**
     * Koreksi jumlah jam sebuah voucher yang sudah terbit (mis. salah ketik saat rilis manual
     * atau salah baca kolom CSV) — beda dari releaseVoucher(), ini mengedit voucher yang sudah
     * ada, bukan menerbitkan baris baru.
     */
    public function updateVoucher(Request $request, string $voucher): JsonResponse
    {
        $organization = $this->resolveOwnOrganization($request);
        $this->authorize('manageMembers', $organization);
        $this->authorize('performPicAction', [$organization, SponsorOrganization::PIC_EDIT_VOUCHERS]);

        $voucherModel = $this->resolveOwnVoucher($organization, $voucher);
        $oldHours = (float) $voucherModel->hours_granted;

        $validated = $request->validate([
            'hours_granted' => 'required|numeric|min:0.01',
        ]);

        try {
            $voucherModel = $this->service->updateVoucherHours($voucherModel, (float) $validated['hours_granted']);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $memberName = $voucherModel->member?->user?->name ?? 'karyawan';
        $this->logPicAction($organization, 'sponsor.voucher_updated', 'PIC mengubah voucher '.$memberName.' dari '.$this->hours($oldHours).' menjadi '.$this->hours($voucherModel->hours_granted).' jam', [
            'karyawan' => $memberName,
            'jam_lama' => $oldHours,
            'jam_baru' => (float) $voucherModel->hours_granted,
        ]);

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('corporate.voucher_corrected'),
            'data' => $voucherModel,
        ]);
    }

    /**
     * Hapus voucher yang salah input — hanya diizinkan kalau belum ada jam yang terpakai.
     */
    public function deleteVoucher(Request $request, string $voucher): JsonResponse
    {
        $organization = $this->resolveOwnOrganization($request);
        $this->authorize('manageMembers', $organization);
        $this->authorize('performPicAction', [$organization, SponsorOrganization::PIC_EDIT_VOUCHERS]);

        $voucherModel = $this->resolveOwnVoucher($organization, $voucher);
        $memberName = $voucherModel->member?->user?->name ?? 'karyawan';
        $hours = (float) $voucherModel->hours_granted;

        try {
            $this->service->deleteVoucher($voucherModel);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $this->logPicAction($organization, 'sponsor.voucher_deleted', 'PIC menghapus voucher '.$this->hours($hours).' jam milik '.$memberName, [
            'karyawan' => $memberName,
            'jam' => $hours,
        ], ActivityLogger::WARNING);

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('corporate.voucher_deleted'),
        ]);
    }

    public function reactivateMember(Request $request, string $member): JsonResponse
    {
        $organization = $this->resolveOwnOrganization($request);
        $this->authorize('manageMembers', $organization);
        $this->authorize('performPicAction', [$organization, SponsorOrganization::PIC_MANAGE_MEMBERS]);

        $memberModel = $this->service->reactivateMember($this->resolveOwnMember($organization, $member));

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('corporate.member_reactivated'),
            'data' => $memberModel,
        ]);
    }

    /**
     * Import roster + voucher jam dari file CSV (kolom: nama/name, nomor_telepon/phone/no_hp,
     * jam/hours/durasi_jam opsional).
     */
    public function importCsv(Request $request): JsonResponse
    {
        $organization = $this->resolveOwnOrganization($request);
        $this->authorize('manageMembers', $organization);
        $this->authorize('performPicAction', [$organization, SponsorOrganization::PIC_MANAGE_MEMBERS]);

        try {
            $validated = $request->validate([
                'file' => 'required|file|mimes:csv,txt|max:2048',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => SiteLocale::text('corporate.csv_required')], 422);
        }

        try {
            $result = $this->service->importMembersFromCsv(
                $organization,
                $validated['file']->getRealPath(),
                $request->user(),
                allowHours: $organization->picAllows(SponsorOrganization::PIC_RELEASE_SINGLE),
            );
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if ($result['vouchers_issued'] + $result['vouchers_updated'] > 0) {
            $this->logPicAction($organization, 'sponsor.voucher_csv_imported', 'PIC mengimpor CSV: '.$result['vouchers_issued'].' voucher baru, '.$result['vouchers_updated'].' voucher diperbarui', [
                'karyawan_baru' => $result['imported'],
                'voucher_baru' => $result['vouchers_issued'],
                'voucher_diperbarui' => $result['vouchers_updated'],
                'baris_gagal' => count($result['failed_rows']),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('corporate.import_done', [
                'imported' => $result['imported'],
                'reactivated' => $result['reactivated'],
                'skipped' => $result['skipped_duplicate'],
                'issued' => $result['vouchers_issued'],
                'updated' => $result['vouchers_updated'],
                'failed' => count($result['failed_rows']),
            ]),
            'data' => $result,
        ]);
    }

    /** Jejak aksi voucher PIC di Log Aktivitas (anggota & perusahaan sudah tercatat otomatis lewat AuditRegistry). */
    private function logPicAction(SponsorOrganization $organization, string $event, string $description, array $meta, string $severity = ActivityLogger::INFO): void
    {
        ActivityLogger::record(
            module: 'SPONSOR',
            event: $event,
            description: $description.' — '.$organization->name,
            subject: $organization,
            meta: ['perusahaan' => $organization->name] + $meta,
            severity: $severity,
        );
    }

    private function hours(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /**
     * Resolve akun corporate milik user yang login sebagai PIC-nya. Sengaja TIDAK menerima
     * organization_id dari request/route — satu-satunya cara menentukan "organisasi mana"
     * adalah dari identitas user yang sedang login, supaya PIC organisasi A tidak pernah
     * bisa mengarahkan endpoint ini ke organisasi B lewat parameter manapun (no IDOR surface).
     */
    private function resolveOwnOrganization(Request $request): SponsorOrganization
    {
        $organization = SponsorOrganization::where('sponsor_admin_user_id', $request->user()->id)->first();

        if (! $organization) {
            abort(404, SiteLocale::text('corporate.not_pic'));
        }

        return $organization;
    }

    private function resolveOwnMember(SponsorOrganization $organization, string $memberId): SponsorOrganizationMember
    {
        $member = SponsorOrganizationMember::where('id', $memberId)
            ->where('sponsor_organization_id', $organization->id)
            ->first();

        if (! $member) {
            abort(404, SiteLocale::text('corporate.member_not_found'));
        }

        return $member;
    }

    private function resolveOwnVoucher(SponsorOrganization $organization, string $voucherId): SponsorMemberVoucher
    {
        $voucher = SponsorMemberVoucher::where('id', $voucherId)
            ->whereHas('member', fn ($q) => $q->where('sponsor_organization_id', $organization->id))
            ->first();

        if (! $voucher) {
            abort(404, SiteLocale::text('corporate.team_voucher_not_found'));
        }

        return $voucher;
    }
}
