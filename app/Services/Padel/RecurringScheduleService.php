<?php

namespace App\Services\Padel;

use App\Models\Membership\UserMembership;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Padel\PadelRecurringOccurrence;
use App\Models\Padel\PadelRecurringSchedule;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Jadwal Tetap Mingguan: membuat booking tiap minggu (paling jauh weeks_ahead minggu ke depan) lewat
 * PadelBookingService::createRecurringBooking, mencatat hasilnya per (lapangan, tanggal) di padel_recurring_occurrences,
 * dan melewati tanggal tertentu (mis. lapangan dipakai acara). Dipanggil tombol admin & perintah terjadwal harian
 * (padel:generate-recurring). Gagal (bentrok / kuota habis / membership berakhir) dicatat FAILED dan dicoba lagi.
 */
class RecurringScheduleService
{
    public function __construct(private PadelBookingService $bookings) {}

    /**
     * Membership aktif yang punya kuota jam PADEL — pilihan "pemilik kuota" di form (corporate & individu).
     *
     * @return array<string, string> [user_membership_id => label]
     */
    public function membershipOptions(?string $includeId = null): array
    {
        return UserMembership::with(['user', 'plan', 'sponsorOrganization', 'balances'])
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('status', 'ACTIVE')
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString())))
                ->when($includeId, fn ($q) => $q->orWhere('id', $includeId)))
            ->whereHas('balances', fn ($q) => $q->where('facility', 'PADEL')->where('quota_type', 'HOURS'))
            ->get()
            ->mapWithKeys(function (UserMembership $m) {
                $balance = $m->balanceFor('PADEL');
                $owner = $m->sponsorOrganization
                    ? 'Corporate · '.$m->sponsorOrganization->name
                    : 'Member · '.($m->user?->name ?? '-');

                return [$m->id => $owner.' — '.($m->plan?->name ?? 'Paket').' (sisa '.self::hours((float) $balance?->remaining_quota).' jam)'];
            })
            ->sort()
            ->all();
    }

    /**
     * Rapikan & periksa isian form jadwal di server (isian Livewire bisa direkayasa).
     *
     * @throws HttpException 422 dengan pesan untuk admin
     */
    public function normalize(array $data, ?PadelRecurringSchedule $record = null): array
    {
        $fail = fn (string $message) => throw new HttpException(422, $message);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $fail('Nama jadwal wajib diisi (maks. 100 karakter).');
        }

        $membershipId = (string) ($data['user_membership_id'] ?? $record?->user_membership_id);
        if ($record && $record->user_membership_id !== $membershipId && $record->occurrences()->exists()) {
            $fail('Pemilik kuota tidak bisa diganti setelah booking dibuat. Buat jadwal baru.');
        }
        if (! array_key_exists($membershipId, $this->membershipOptions($record?->user_membership_id))) {
            $fail('Pilih membership aktif yang punya kuota jam Padel.');
        }

        $day = (int) ($data['day_of_week'] ?? 0);
        if (! isset(PadelRecurringSchedule::DAYS[$day])) {
            $fail('Hari tidak valid.');
        }

        $start = (string) ($data['start_time'] ?? '');
        $end = (string) ($data['end_time'] ?? '');
        if (! preg_match('/^([01]\d|2[0-3]):00$/', $start) || ! preg_match('/^(([01]\d|2[0-3]):00|24:00)$/', $end)
            || PadelRecurringSchedule::hourOf($end) <= PadelRecurringSchedule::hourOf($start)) {
            $fail('Jam main harus jam bulat dan jam selesai setelah jam mulai.');
        }

        $courtIds = array_values(array_unique(array_map('strval', (array) ($data['court_ids'] ?? []))));
        $courts = PadelCourt::whereIn('id', $courtIds)->where('is_active', true)->get();
        if ($courtIds === [] || $courts->count() !== count($courtIds)) {
            $fail('Pilih minimal satu lapangan aktif.');
        }
        foreach ($courts as $court) {
            $open = (int) substr($court->open_time ?: '06:00', 0, 2);
            $closeVal = $court->close_time ?: '23:00';
            $close = in_array($closeVal, ['00:00', '24:00'], true) ? 24 : (int) substr($closeVal, 0, 2);
            if (PadelRecurringSchedule::hourOf($start) < $open || PadelRecurringSchedule::hourOf($end) > $close) {
                $fail("Jam main di luar jam operasional {$court->name} ({$court->open_time} - {$court->close_time}).");
            }
        }

        try {
            $startsOn = Carbon::parse((string) ($data['starts_on'] ?? ''))->toDateString();
            $endsOn = filled($data['ends_on'] ?? null) ? Carbon::parse((string) $data['ends_on'])->toDateString() : null;
        } catch (\Throwable) {
            $fail('Tanggal tidak valid.');
        }
        // Periode ikut masa membership: kosong → sampai tanggal habis membership; tidak boleh melewatinya.
        $membershipEnd = UserMembership::whereKey($membershipId)->value('end_date');
        $membershipEnd = $membershipEnd ? Carbon::parse($membershipEnd)->toDateString() : null;
        $endsOn ??= $membershipEnd;
        if ($membershipEnd !== null && $endsOn > $membershipEnd) {
            $fail('"Sampai tanggal" tidak boleh lewat masa aktif membership ('.Carbon::parse($membershipEnd)->translatedFormat('d M Y').').');
        }
        if ($endsOn !== null && $endsOn < $startsOn) {
            $fail('Tanggal berakhir harus sama atau setelah tanggal mulai.');
        }

        $weeks = (int) ($data['weeks_ahead'] ?? 0);
        if ($weeks < 1 || $weeks > PadelRecurringSchedule::MAX_WEEKS_AHEAD) {
            $fail('Booking dibuat 1 sampai '.PadelRecurringSchedule::MAX_WEEKS_AHEAD.' minggu ke depan.');
        }

        return [
            'name' => $name,
            'user_membership_id' => $membershipId,
            'day_of_week' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'court_ids' => $courtIds,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'weeks_ahead' => $weeks,
            'notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 1000) : null,
        ];
    }

    /** Isi form dari data tersimpan. */
    public static function formState(PadelRecurringSchedule $schedule): array
    {
        return $schedule->only(['name', 'user_membership_id', 'day_of_week', 'start_time', 'end_time', 'court_ids', 'weeks_ahead', 'notes']) + [
            'starts_on' => $schedule->starts_on?->toDateString(),
            'ends_on' => $schedule->ends_on?->toDateString(),
        ];
    }

    /**
     * Buat booking minggu-minggu yang belum dibuat (termasuk yang sebelumnya gagal).
     *
     * @return array{booked: int, failed: array<int, string>, busy: bool}
     */
    public function generate(PadelRecurringSchedule $schedule, ?User $actor = null): array
    {
        $result = ['booked' => 0, 'failed' => [], 'busy' => false];
        if (! $schedule->isActive()) {
            return $result;
        }

        // Satu proses per jadwal (tombol admin & perintah terjadwal bersamaan): yang kedua cukup dilewati.
        $lock = Cache::lock('padel-recurring:'.$schedule->id, 300);
        if (! $lock->get()) {
            $result['busy'] = true;

            return $result;
        }

        try {
            $actor ??= $this->systemActor($schedule);
            if (! $actor) {
                $result['failed'][] = 'Tidak ada akun staf untuk mencatat booking.';

                return $result;
            }

            $courts = PadelCourt::whereIn('id', (array) $schedule->court_ids)->get()->keyBy('id');

            foreach ($this->dueDates($schedule) as $date) {
                foreach ((array) $schedule->court_ids as $courtId) {
                    $occurrence = $this->occurrenceFor($schedule, $courtId, $date);
                    if (! in_array($occurrence->status, [PadelRecurringOccurrence::STATUS_PENDING, PadelRecurringOccurrence::STATUS_FAILED], true)) {
                        continue;
                    }

                    // Tanggal tutup / jam tutup yang bentrok (BOOKING_CLOSED_DATES): minggu ini dilewati, booking tidak
                    // dibuat & kuota tidak dipotong.
                    if (\App\Support\BookingWindow::isClosed($date) || \App\Support\BookingWindow::closedHoursReason($date, $schedule->start_time, $schedule->end_time) !== null) {
                        $reason = \App\Support\BookingWindow::closedReason();
                        PadelRecurringOccurrence::whereKey($occurrence->id)
                            ->whereIn('status', [PadelRecurringOccurrence::STATUS_PENDING, PadelRecurringOccurrence::STATUS_FAILED])
                            ->update(['status' => PadelRecurringOccurrence::STATUS_SKIPPED, 'note' => mb_substr('Club 61 tutup'.($reason ? " ({$reason})" : '').' — minggu ini dilewati, kuota jam tidak dipotong', 0, 255)]);

                        continue;
                    }

                    $label = $date->translatedFormat('d M Y').' · '.($courts[$courtId]->name ?? 'Lapangan');
                    try {
                        $this->bookings->createRecurringBooking($schedule, $courtId, $date->toDateString(), $actor);
                        $result['booked']++;
                    } catch (\Throwable $e) {
                        if (! $e instanceof HttpException) {
                            report($e);
                        }
                        $note = $e instanceof HttpException ? $e->getMessage() : 'Gagal dibuat (kesalahan sistem).';
                        // Jangan menimpa minggu yang ternyata sudah BOOKED oleh proses lain.
                        PadelRecurringOccurrence::whereKey($occurrence->id)
                            ->whereIn('status', [PadelRecurringOccurrence::STATUS_PENDING, PadelRecurringOccurrence::STATUS_FAILED])
                            ->update(['status' => PadelRecurringOccurrence::STATUS_FAILED, 'note' => mb_substr($note, 0, 255)]);
                        $result['failed'][] = $label.': '.$note;
                    }
                }
            }
        } finally {
            $lock->release();
        }

        if ($result['booked'] > 0 || $result['failed'] !== []) {
            ActivityLogger::record(
                module: 'PADEL',
                event: 'recurring.generated',
                description: "Jadwal Tetap \"{$schedule->name}\": {$result['booked']} booking dibuat".($result['failed'] !== [] ? ', '.count($result['failed']).' gagal' : ''),
                subject: $schedule,
                meta: array_filter([
                    'jadwal' => $schedule->scheduleLabel(),
                    'booking_dibuat' => $result['booked'],
                    'gagal' => $result['failed'] !== [] ? implode(' | ', array_slice($result['failed'], 0, 10)) : null,
                ], fn ($v) => $v !== null),
                causer: $actor,
            );
        }

        return $result;
    }

    /**
     * Simpan perubahan jadwal lalu sesuaikan booking yang sudah dibuat (aturan reschedule global):
     *   - hari / jam / durasi berubah → booking ke depan dipindah (urut: booking ke-1 → tanggal jadwal baru ke-1, dst);
     *   - lapangan diganti → booking lapangan lama pindah ke lapangan baru (berpasangan sesuai urutan);
     *   - lapangan dikurangi → booking lapangan yang dihapus dibatalkan, jamnya kembali;
     *   - lewat batas reschedule / slot tujuan terisi / tanggal di luar periode → tetap di jadwal lama, dan tanggal
     *     pasangannya dilewati supaya minggu itu tidak dobel.
     * Setelah itu minggu yang belum punya booking langsung dibuat (generate).
     *
     * @return array{moved: int, kept: array<int, string>, cancelled: int, generated: array}
     */
    public function updateSchedule(PadelRecurringSchedule $schedule, array $data, User $actor): array
    {
        if ($schedule->isStopped()) {
            throw new HttpException(422, 'Jadwal ini sudah dihapus.');
        }
        $old = ['day' => $schedule->day_of_week, 'start' => $schedule->start_time, 'end' => $schedule->end_time, 'courts' => array_values((array) $schedule->court_ids)];
        $schedule->update($data);
        $schedule->refresh();

        $summary = ['moved' => 0, 'kept' => [], 'cancelled' => 0, 'generated' => ['booked' => 0, 'failed' => [], 'busy' => false]];
        $patternChanged = $old['day'] !== $schedule->day_of_week || $old['start'] !== $schedule->start_time
            || $old['end'] !== $schedule->end_time || $old['courts'] !== array_values((array) $schedule->court_ids);

        if ($patternChanged) {
            $lock = Cache::lock('padel-recurring:'.$schedule->id, 300);
            $lock->block(15);
            try {
                $this->rebookFutureWeeks($schedule, $old, $actor, $summary);
            } finally {
                $lock->release();
            }
        }

        $summary['generated'] = $this->generate($schedule, $actor);

        if ($patternChanged) {
            ActivityLogger::record(
                module: 'PADEL',
                event: 'recurring.rescheduled',
                description: "Jadwal Tetap \"{$schedule->name}\" diubah: {$summary['moved']} booking dipindah, ".count($summary['kept']).' tetap, '.$summary['cancelled'].' dibatalkan',
                subject: $schedule,
                meta: array_filter([
                    'jadwal_baru' => $schedule->scheduleLabel(),
                    'tetap' => $summary['kept'] !== [] ? implode(' | ', array_slice($summary['kept'], 0, 10)) : null,
                ], fn ($v) => $v !== null),
                causer: $actor,
            );
        }

        return $summary;
    }

    /** Bagian dari updateSchedule: pindahkan / batalkan booking ke depan sesuai pola baru. */
    private function rebookFutureWeeks(PadelRecurringSchedule $schedule, array $old, User $actor, array &$summary): void
    {
        $newCourts = array_values((array) $schedule->court_ids);
        $kept = array_values(array_intersect($old['courts'], $newCourts));
        $removed = array_values(array_diff($old['courts'], $newCourts));
        $added = array_values(array_diff($newCourts, $old['courts']));
        // Lapangan yang diganti: lapangan lama ke-i → lapangan baru ke-i. Sisa lapangan lama = dikurangi.
        $courtMap = array_combine($kept, $kept);
        foreach ($removed as $i => $courtId) {
            $courtMap[$courtId] = $added[$i] ?? null;
        }

        $occurrences = PadelRecurringOccurrence::with(['booking.court', 'court'])
            ->where('recurring_schedule_id', $schedule->id)
            ->where('status', PadelRecurringOccurrence::STATUS_BOOKED)
            ->get()
            ->filter(fn (PadelRecurringOccurrence $o) => $o->booking && $o->booking->status === 'PAID' && $o->booking->start_time->gt(now()))
            ->sortBy(fn (PadelRecurringOccurrence $o) => $o->booking->start_time->timestamp);

        foreach ($occurrences->groupBy(fn (PadelRecurringOccurrence $o) => $o->booking->court_id) as $oldCourtId => $items) {
            $targetCourt = array_key_exists($oldCourtId, $courtMap) ? $courtMap[$oldCourtId] : ($newCourts[0] ?? null);
            $targetDates = $this->upcomingPatternDates($schedule, $items->count());

            foreach ($items->values() as $i => $occurrence) {
                $booking = $occurrence->booking;
                $label = $booking->booking_code.' ('.$booking->booking_date->translatedFormat('D d M').' · '.($booking->court?->name ?? '-').')';

                // Lapangan dikurangi → dibatalkan (masih sebelum batas reschedule), slotnya bisa dijual lagi.
                if ($targetCourt === null) {
                    if (now()->gte(PadelBookingService::rescheduleDeadline($booking))) {
                        $summary['kept'][] = $label.': lapangan dihapus tapi sudah lewat batas reschedule — tetap main';

                        continue;
                    }
                    $this->bookings->requestCancelAndRefund($booking->id, 'JADWAL_TETAP_DIUBAH', 'Lapangan dihapus dari jadwal tetap', $actor);
                    $occurrence->update(['status' => PadelRecurringOccurrence::STATUS_SKIPPED, 'note' => 'Lapangan dihapus dari jadwal — booking dibatalkan, jam kuota kembali']);
                    $summary['cancelled']++;

                    continue;
                }

                $targetDate = $targetDates[$i] ?? null;
                if ($targetDate === null) {
                    $summary['kept'][] = $label.': tanggal jadwal baru di luar periode — tetap di jadwal lama';

                    continue;
                }

                $sameSlot = $booking->court_id === $targetCourt && $booking->booking_date->toDateString() === $targetDate->toDateString()
                    && $booking->start_time->format('H:i') === $schedule->start_time && $booking->end_time->format('H:i') === ($schedule->end_time === '24:00' ? '00:00' : $schedule->end_time);
                if ($sameSlot) {
                    $this->rekeyOccurrence($occurrence, $targetCourt, $targetDate, null);

                    continue;
                }

                $target = PadelRecurringOccurrence::where('recurring_schedule_id', $schedule->id)->where('court_id', $targetCourt)
                    ->whereDate('occurrence_date', $targetDate->toDateString())->first();
                if ($target && $target->id !== $occurrence->id && in_array($target->status, [PadelRecurringOccurrence::STATUS_SKIPPED, PadelRecurringOccurrence::STATUS_BOOKED], true)) {
                    $summary['kept'][] = $label.': tanggal '.$targetDate->translatedFormat('d M').' sudah '.($target->status === PadelRecurringOccurrence::STATUS_SKIPPED ? 'dilewati' : 'terisi').' — tetap di jadwal lama';

                    continue;
                }

                try {
                    $moved = $this->bookings->moveRecurringBooking($booking, $targetCourt, $targetDate->toDateString(), $schedule->start_time, $schedule->end_time, $actor);
                    $from = $booking->booking_date->translatedFormat('D d M');
                    $this->rekeyOccurrence($occurrence, $targetCourt, $targetDate, 'Dipindah dari '.$from.' (jadwal diubah)'.($moved['kept_duration'] ? ' — durasi lama dipertahankan, kuota kurang' : ''));
                    $summary['moved']++;
                    if ($moved['kept_duration']) {
                        $summary['kept'][] = $label.': dipindah, tapi durasi lama dipertahankan (sisa kuota kurang)';
                    }
                } catch (HttpException $e) {
                    $summary['kept'][] = $label.': '.$e->getMessage();
                    // Minggu ini tetap main di jadwal lama → tanggal pasangannya dilewati supaya tidak dobel.
                    $pair = $this->occurrenceFor($schedule, $targetCourt, $targetDate);
                    if (in_array($pair->status, [PadelRecurringOccurrence::STATUS_PENDING, PadelRecurringOccurrence::STATUS_FAILED], true)) {
                        $pair->update(['status' => PadelRecurringOccurrence::STATUS_SKIPPED, 'note' => mb_substr('Minggu ini tetap main di jadwal lama: '.$label, 0, 255)]);
                    }
                }
            }
        }
    }

    /** Catatan minggu ikut pindah ke (lapangan, tanggal) baru supaya generator tidak membuat booking kedua. */
    private function rekeyOccurrence(PadelRecurringOccurrence $occurrence, string $courtId, Carbon $date, ?string $note): void
    {
        if ($occurrence->court_id === $courtId && $occurrence->occurrence_date->toDateString() === $date->toDateString()) {
            return;
        }
        // Catatan kosong / gagal di tanggal tujuan digantikan oleh booking yang dipindah.
        PadelRecurringOccurrence::where('recurring_schedule_id', $occurrence->recurring_schedule_id)->where('court_id', $courtId)
            ->whereDate('occurrence_date', $date->toDateString())->where('id', '!=', $occurrence->id)
            ->whereIn('status', [PadelRecurringOccurrence::STATUS_PENDING, PadelRecurringOccurrence::STATUS_FAILED])
            ->delete();
        $occurrence->update(['court_id' => $courtId, 'occurrence_date' => $date->toDateString(), 'note' => $note]);
    }

    /**
     * Tanggal pola jadwal (baru) yang belum lewat, mulai hari ini / starts_on, sampai ends_on — sebanyak $count.
     *
     * @return array<int, Carbon>
     */
    private function upcomingPatternDates(PadelRecurringSchedule $schedule, int $count): array
    {
        $today = now()->startOfDay();
        $from = $schedule->starts_on->copy()->startOfDay();
        $dates = [];
        for ($date = $schedule->firstDateOnOrAfter($from->lt($today) ? $today : $from); count($dates) < $count; $date = $date->copy()->addWeek()) {
            if ($schedule->ends_on && $date->gt($schedule->ends_on->copy()->startOfDay())) {
                break;
            }
            if (Carbon::parse($date->toDateString().' '.$schedule->start_time)->gt(now())) {
                $dates[] = $date->copy();
            }
        }

        return $dates;
    }

    /** Semua jadwal aktif — perintah terjadwal harian. */
    public function generateAll(): array
    {
        $summary = ['schedules' => 0, 'booked' => 0, 'failed' => 0];
        foreach (PadelRecurringSchedule::where('status', PadelRecurringSchedule::STATUS_ACTIVE)->get() as $schedule) {
            $r = $this->generate($schedule);
            $summary['schedules']++;
            $summary['booked'] += $r['booked'];
            $summary['failed'] += count($r['failed']);
        }

        return $summary;
    }

    /**
     * Lewati satu tanggal (semua lapangan jadwal ini). Booking yang sudah dibuat dibatalkan lewat jalur pembatalan
     * biasa (kuota jam kembali) — hanya kalau jam mainnya belum mulai.
     *
     * @return int jumlah booking yang dibatalkan
     */
    public function skip(PadelRecurringSchedule $schedule, string $date, string $reason, User $actor): int
    {
        if ($schedule->isStopped()) {
            throw new HttpException(422, 'Jadwal ini sudah dihapus.');
        }
        $day = Carbon::parse($date)->startOfDay();
        if ($day->dayOfWeekIso !== $schedule->day_of_week) {
            throw new HttpException(422, 'Tanggal itu bukan hari '.PadelRecurringSchedule::DAYS[$schedule->day_of_week].'.');
        }
        if (Carbon::parse($day->toDateString().' '.$schedule->start_time)->lte(now())) {
            throw new HttpException(422, 'Jam main tanggal itu sudah dimulai / lewat, jadi tidak bisa dilewati.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new HttpException(422, 'Alasan wajib diisi.');
        }

        // Cek dulu semua lapangan supaya tanggal ini tidak dilewati setengah jalan.
        $started = PadelBooking::where('recurring_schedule_id', $schedule->id)->whereDate('booking_date', $day->toDateString())
            ->whereIn('status', ['CHECKED_IN', 'COMPLETED'])->value('booking_code');
        if ($started) {
            throw new HttpException(422, "Booking {$started} sudah check-in / selesai — tanggal ini tidak bisa dilewati.");
        }

        $cancelled = 0;
        foreach ((array) $schedule->court_ids as $courtId) {
            $occurrence = $this->occurrenceFor($schedule, $courtId, $day);
            if ($occurrence->status === PadelRecurringOccurrence::STATUS_SKIPPED) {
                continue;
            }

            if ($occurrence->status === PadelRecurringOccurrence::STATUS_BOOKED && $occurrence->booking_id) {
                $booking = PadelBooking::find($occurrence->booking_id);
                // Booking yang sudah di-reschedule ke tanggal lain tidak ikut dibatalkan — tanggal ini sudah kosong.
                if ($booking && $booking->booking_date->toDateString() !== $day->toDateString()) {
                    $booking = null;
                }
                if ($booking && in_array($booking->status, ['PAID', 'LOCKED'], true)) {
                    // Jalur pembatalan Kelola Pemesanan: booking Rp0 langsung CANCELLED + kuota jam kembali.
                    $this->bookings->requestCancelAndRefund($booking->id, 'JADWAL_TETAP_DILEWATI', $reason, $actor);
                    $cancelled++;
                }
            }

            $occurrence->update(['status' => PadelRecurringOccurrence::STATUS_SKIPPED, 'note' => mb_substr($reason, 0, 255)]);
        }

        ActivityLogger::record(
            module: 'PADEL',
            event: 'recurring.skipped',
            description: "Jadwal Tetap \"{$schedule->name}\" dilewati tanggal ".$day->translatedFormat('d M Y').($cancelled ? " ({$cancelled} booking dibatalkan, kuota jam dikembalikan)" : ''),
            subject: $schedule,
            meta: ['tanggal' => $day->toDateString(), 'alasan' => $reason, 'booking_dibatalkan' => $cancelled],
            causer: $actor,
        );

        return $cancelled;
    }

    /**
     * Ringkasan sebelum "Hapus jadwal" (ditampilkan di dialog konfirmasi).
     *
     * @return array{cancel: int, hours: float, played: int}
     */
    public function removalPreview(PadelRecurringSchedule $schedule): array
    {
        $upcoming = $this->upcomingBookings($schedule);

        return [
            'cancel' => $upcoming->count(),
            'hours' => round((float) $upcoming->sum('member_hours_consumed'), 2),
            'played' => $this->playedBookings($schedule)->count(),
        ];
    }

    /**
     * Hapus jadwal. Patokannya jam main (kode tidak tahu member benar-benar datang atau tidak):
     *   - jam main BELUM mulai → dibatalkan lewat jalur pembatalan biasa (sama dengan "Lewati tanggal"): kuota jam kembali
     *     utuh, lapangan kosong lagi;
     *   - jam main SUDAH mulai / lewat → dianggap sudah dimainkan, tidak disentuh, jamnya terpakai.
     * Belum ada sesi yang lewat → jadwal dihapus; sudah ada → ditandai Dihentikan supaya riwayatnya tetap bisa dilihat.
     *
     * @return array{busy: bool, cancelled: int, hours_returned: float, played: int, deleted: bool}
     */
    public function remove(PadelRecurringSchedule $schedule, User $actor): array
    {
        $result = ['busy' => false, 'cancelled' => 0, 'hours_returned' => 0.0, 'played' => 0, 'deleted' => false];

        // Kunci yang sama dengan generate(): proses otomatis malam tidak bisa membuat booking baru di tengah penghapusan.
        $lock = Cache::lock('padel-recurring:'.$schedule->id, 300);
        if (! $lock->get()) {
            $result['busy'] = true;

            return $result;
        }

        try {
            // Dibaca ulang dari database: relasi membership yang sudah termuat bisa menyimpan saldo lama.
            $balance = $schedule->padelBalance()?->fresh();
            $before = $balance ? (float) $balance->remaining_quota : null;

            DB::transaction(function () use ($schedule, $actor, &$result) {
                foreach ($this->upcomingBookings($schedule) as $booking) {
                    $this->bookings->requestCancelAndRefund($booking->id, 'JADWAL_TETAP_DIHAPUS', 'Jadwal tetap "'.$schedule->name.'" dihapus', $actor);
                    $result['cancelled']++;
                }

                $result['played'] = $this->playedBookings($schedule)->count();

                if ($result['played'] === 0) {
                    // Tidak ada sesi yang sudah dimainkan: hapus total (minggu-minggunya ikut terhapus, booking yang barusan
                    // dibatalkan tetap ada sebagai CANCELLED tanpa tautan jadwal).
                    $schedule->delete();
                    $result['deleted'] = true;

                    return;
                }

                $schedule->occurrences()
                    ->where(fn ($q) => $q->whereIn('status', [PadelRecurringOccurrence::STATUS_PENDING, PadelRecurringOccurrence::STATUS_FAILED])
                        ->orWhere(fn ($q) => $q->where('status', PadelRecurringOccurrence::STATUS_BOOKED)
                            ->whereIn('booking_id', PadelBooking::where('recurring_schedule_id', $schedule->id)->where('status', 'CANCELLED')->select('id'))))
                    ->update(['status' => PadelRecurringOccurrence::STATUS_SKIPPED, 'note' => 'Jadwal dihapus']);
                $schedule->update(['status' => PadelRecurringSchedule::STATUS_STOPPED]);
            });

            if ($balance && $before !== null) {
                $result['hours_returned'] = round((float) $balance->fresh()->remaining_quota - $before, 2);
            }
        } finally {
            $lock->release();
        }

        ActivityLogger::record(
            module: 'PADEL',
            event: 'recurring.removed',
            description: "Jadwal Tetap \"{$schedule->name}\" dihapus: {$result['cancelled']} booking dibatalkan, {$result['hours_returned']} jam kembali ke kuota"
                .($result['played'] ? ", {$result['played']} sesi sudah lewat (jam terpakai) — jadwal ditandai Dihentikan" : ''),
            subject: $result['deleted'] ? null : $schedule,
            meta: [
                'jadwal' => $schedule->name.' — '.$schedule->scheduleLabel(),
                'booking_dibatalkan' => $result['cancelled'],
                'jam_kembali' => $result['hours_returned'],
                'sesi_sudah_lewat' => $result['played'],
            ],
            causer: $actor,
        );

        return $result;
    }

    /** Booking jadwal ini yang masih bisa dibatalkan: belum dibatalkan & jam mainnya belum mulai. */
    private function upcomingBookings(PadelRecurringSchedule $schedule): Collection
    {
        return PadelBooking::where('recurring_schedule_id', $schedule->id)
            ->whereIn('status', ['PAID', 'LOCKED'])
            ->where('start_time', '>', now())
            ->get();
    }

    /** Booking jadwal ini yang jam mainnya sudah mulai / lewat (dianggap sudah dimainkan; tidak datang = EXPIRED, jam tetap terpakai). */
    private function playedBookings(PadelRecurringSchedule $schedule): Collection
    {
        return PadelBooking::where('recurring_schedule_id', $schedule->id)
            ->whereNotIn('status', ['CANCELLED', 'REFUND_PENDING', 'REFUNDED'])
            ->where('start_time', '<=', now())
            ->get(['id']);
    }

    /** Batalkan "lewati": tanggal itu dibuatkan booking lagi di putaran berikutnya (kalau masih dalam jangkauan). */
    public function unskip(PadelRecurringSchedule $schedule, string $date, User $actor): int
    {
        if ($schedule->isStopped()) {
            throw new HttpException(422, 'Jadwal ini sudah dihapus.');
        }
        $count = PadelRecurringOccurrence::where('recurring_schedule_id', $schedule->id)
            ->whereDate('occurrence_date', $date)
            ->where('status', PadelRecurringOccurrence::STATUS_SKIPPED)
            ->delete();

        if ($count > 0) {
            ActivityLogger::record(
                module: 'PADEL',
                event: 'recurring.unskipped',
                description: "Jadwal Tetap \"{$schedule->name}\" tanggal ".Carbon::parse($date)->translatedFormat('d M Y').' tidak jadi dilewati',
                subject: $schedule,
                meta: ['tanggal' => $date],
                causer: $actor,
            );
        }

        return $count;
    }

    /**
     * Tanggal main yang sudah waktunya dibuatkan booking: mulai hari ini / starts_on, sampai weeks_ahead minggu ke depan
     * / ends_on. Jam main yang sudah lewat tidak dibuat.
     *
     * @return Collection<int, Carbon>
     */
    public function dueDates(PadelRecurringSchedule $schedule): Collection
    {
        $today = now()->startOfDay();
        $from = $schedule->starts_on->copy()->startOfDay();
        $from = $from->lt($today) ? $today : $from;
        $until = $today->copy()->addWeeks(max(1, min(PadelRecurringSchedule::MAX_WEEKS_AHEAD, (int) $schedule->weeks_ahead)));
        if ($schedule->ends_on && $schedule->ends_on->copy()->startOfDay()->lt($until)) {
            $until = $schedule->ends_on->copy()->startOfDay();
        }

        $dates = collect();
        for ($date = $schedule->firstDateOnOrAfter($from); $date->lte($until); $date = $date->copy()->addWeek()) {
            if (Carbon::parse($date->toDateString().' '.$schedule->start_time)->gt(now())) {
                $dates->push($date->copy());
            }
        }

        return $dates;
    }

    /**
     * Perkiraan kuota untuk tanggal yang BELUM dibuatkan booking sampai akhir periode, dengan aturan yang sama dengan
     * generate(): per tanggal lalu per lapangan, kuota dipotong selama cukup; tanggal yang dilewati / tutup / sudah dibuat
     * tidak dihitung. Jadwal aktif lain dari membership yang sama ikut dihitung karena kuotanya satu. Hanya perkiraan:
     * jam yang nanti diberikan ke karyawan sponsor juga memakai kuota yang sama.
     *
     * @return array{last_booked: ?Carbon, period_end: ?Carbon, shortfall: float, covered_until: ?Carbon, partial: ?array{date: Carbon, courts: int, of: int}, first_missing: ?Carbon}|null
     */
    public function quotaForecast(PadelRecurringSchedule $schedule): ?array
    {
        $balance = $schedule->padelBalance();
        if (! $balance || ! $schedule->isActive()) {
            return null;
        }

        $membershipEnd = $schedule->membership?->end_date?->copy()->startOfDay();
        $today = now()->startOfDay();
        $needs = [];
        $byDate = []; // per tanggal jadwal ini: of = lapangan yang dimainkan, courts = sudah dibuat / tertutup kuota
        foreach (PadelRecurringSchedule::where('user_membership_id', $schedule->user_membership_id)->where('status', PadelRecurringSchedule::STATUS_ACTIVE)->get() as $s) {
            $end = $s->ends_on?->copy()->startOfDay() ?? $membershipEnd ?? $today->copy()->addWeeks(52);
            $done = $s->occurrences()->whereDate('occurrence_date', '>=', $today->toDateString())
                ->whereIn('status', [PadelRecurringOccurrence::STATUS_BOOKED, PadelRecurringOccurrence::STATUS_SKIPPED])
                ->get(['occurrence_date', 'court_id', 'status'])
                ->mapWithKeys(fn (PadelRecurringOccurrence $o) => [$o->occurrence_date->toDateString().'|'.$o->court_id => $o->status]);

            for ($date = $s->firstDateOnOrAfter($today->copy()->max($s->starts_on->copy()->startOfDay())); $date->lte($end); $date = $date->copy()->addWeek()) {
                if (Carbon::parse($date->toDateString().' '.$s->start_time)->lte(now()) || \App\Support\BookingWindow::isClosed($date)
                    || \App\Support\BookingWindow::closedHoursReason($date, $s->start_time, $s->end_time) !== null) {
                    continue;
                }
                foreach ((array) $s->court_ids as $courtId) {
                    $status = $done[$date->toDateString().'|'.$courtId] ?? null;
                    if ($status === PadelRecurringOccurrence::STATUS_SKIPPED) {
                        continue;
                    }
                    if ($status === PadelRecurringOccurrence::STATUS_BOOKED) {
                        if ($s->id === $schedule->id) {
                            $byDate[$date->toDateString()] ??= ['date' => $date->copy(), 'of' => 0, 'courts' => 0];
                            $byDate[$date->toDateString()]['of']++;
                            $byDate[$date->toDateString()]['courts']++;
                        }

                        continue;
                    }
                    $needs[] = ['date' => $date->copy(), 'schedule' => $s->id, 'hours' => (float) $s->durationHours()];
                }
            }
        }
        usort($needs, fn ($a, $b) => [$a['date'], $a['schedule']] <=> [$b['date'], $b['schedule']]);

        // Bagi sisa kuota berurutan; catat hasil per tanggal milik jadwal ini.
        $remaining = (float) $balance->remaining_quota;
        $shortfall = 0.0;
        foreach ($needs as $need) {
            $covered = $remaining + 0.001 >= $need['hours'];
            if ($covered) {
                $remaining -= $need['hours'];
            }
            if ($need['schedule'] !== $schedule->id) {
                continue;
            }
            $key = $need['date']->toDateString();
            $byDate[$key] ??= ['date' => $need['date'], 'of' => 0, 'courts' => 0];
            $byDate[$key]['of']++;
            if ($covered) {
                $byDate[$key]['courts']++;
            } else {
                $shortfall += $need['hours'];
            }
        }
        ksort($byDate);

        $lastBooked = PadelBooking::where('recurring_schedule_id', $schedule->id)->whereNotIn('status', ['CANCELLED', 'REFUNDED'])->max('booking_date');
        $coveredUntil = null;
        $partial = null;
        $firstMissing = null;
        foreach ($byDate as $row) {
            if ($row['courts'] === $row['of']) {
                if ($partial === null && $firstMissing === null) {
                    $coveredUntil = $row['date'];
                }

                continue;
            }
            if ($row['courts'] > 0 && $partial === null && $firstMissing === null) {
                $partial = $row;

                continue;
            }
            $firstMissing ??= $row['date'];
        }

        return [
            'last_booked' => $lastBooked ? Carbon::parse($lastBooked)->startOfDay() : null,
            'period_end' => $schedule->ends_on?->copy()->startOfDay() ?? $membershipEnd,
            'shortfall' => round($shortfall, 2),
            'covered_until' => $coveredUntil,
            'partial' => $partial,
            'first_missing' => $firstMissing,
        ];
    }

    /** Perkiraan minggu yang masih tertutup sisa kuota (null = kuota tidak terbaca). */
    public function weeksLeft(PadelRecurringSchedule $schedule): ?array
    {
        $balance = $schedule->padelBalance();
        $perWeek = $schedule->hoursPerWeek();
        if (! $balance || $perWeek <= 0) {
            return null;
        }
        $remaining = (float) $balance->remaining_quota;

        return [
            'remaining' => $remaining,
            'weeks' => (int) floor($remaining / $perWeek),
            'leftover' => round($remaining - floor($remaining / $perWeek) * $perWeek, 2),
        ];
    }

    private function occurrenceFor(PadelRecurringSchedule $schedule, string $courtId, Carbon $date): PadelRecurringOccurrence
    {
        $keys = ['recurring_schedule_id' => $schedule->id, 'court_id' => $courtId, 'occurrence_date' => $date->toDateString()];
        $find = fn () => PadelRecurringOccurrence::where('recurring_schedule_id', $schedule->id)->where('court_id', $courtId)
            ->whereDate('occurrence_date', $date->toDateString())->first();

        try {
            return $find() ?? PadelRecurringOccurrence::create($keys + ['status' => PadelRecurringOccurrence::STATUS_PENDING]);
        } catch (UniqueConstraintViolationException) {
            return $find();
        }
    }

    /** Pencatat booking dari perintah terjadwal: pembuat jadwal, atau super_admin pertama kalau akunnya sudah tidak ada. */
    private function systemActor(PadelRecurringSchedule $schedule): ?User
    {
        return $schedule->createdBy ?? User::role('super_admin')->orderBy('created_at')->first();
    }

    private static function hours(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
