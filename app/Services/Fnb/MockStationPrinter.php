<?php

namespace App\Services\Fnb;

use App\Models\Pos\KitchenTicket;
use App\Support\ReceiptPaper;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Mode tes slip stasiun (STATION_PRINT_DRIVER=mock): byte ESC/POS yang SAMA dengan yang dikirim ke printer disimpan ke
 * storage/logs/print-mock/ (.bin mentah + .txt yang bisa dibaca) dan teksnya ditampilkan di layar kasir. Tidak ada yang
 * dikirim ke printer.
 */
class MockStationPrinter
{
    public const MAX_BYTES = 262144;

    /** Simpan slip & kembalikan versi teksnya. */
    public function store(KitchenTicket $ticket, string $bytes): string
    {
        $dir = storage_path('logs/print-mock');
        File::ensureDirectoryExists($dir);

        $base = now('Asia/Jakarta')->format('Ymd-His').'-'.(Str::slug($ticket->station_name) ?: 'stasiun').'-'.$ticket->id;
        $text = self::toText($bytes);

        File::put($dir.'/'.$base.'.bin', $bytes);
        File::put($dir.'/'.$base.'.txt', $text);

        return $text;
    }

    /**
     * Byte ESC/POS slip (club61LayoutToEscPos) → teks rata sesuai perintah perataan, lebar kolom kertas. Perintah yang
     * dikenali: ESC @, ESC t n, ESC a n, ESC E n, ESC d n, GS ! n, GS L / GS W nL nH (geser & lebar area, tidak ditampilkan), GS V B n.
     */
    public static function toText(string $bytes, int $columns = ReceiptPaper::COLUMNS): string
    {
        $lines = [];
        $line = '';
        $align = 0;
        $flush = function () use (&$lines, &$line, &$align, $columns) {
            $text = rtrim($line);
            $gap = max(0, $columns - strlen($text));
            $lines[] = match ($align) {
                1 => str_repeat(' ', intdiv($gap, 2)).$text,
                2 => str_repeat(' ', $gap).$text,
                default => $text,
            };
            $line = '';
        };

        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($bytes[$i]);
            if ($byte === 0x1B) {
                $command = ord($bytes[$i + 1] ?? "\0");
                if ($command === 0x61) {
                    $align = ord($bytes[$i + 2] ?? "\0") % 3;
                }
                if ($command === 0x64) {
                    for ($n = ord($bytes[$i + 2] ?? "\0"); $n > 0; $n--) {
                        $lines[] = '';
                    }
                }
                $i += $command === 0x40 ? 1 : 2;
            } elseif ($byte === 0x1D) {
                $command = ord($bytes[$i + 1] ?? "\0");
                if ($command === 0x56) {
                    $lines[] = str_repeat('- ', intdiv($columns, 2)).'(potong)';
                    $i += 3;
                } elseif ($command === 0x4C || $command === 0x57) {
                    $i += 3;
                } else {
                    $i += 2;
                }
            } elseif ($byte === 0x0A) {
                $flush();
            } elseif ($byte >= 0x20 && $byte <= 0x7E) {
                $line .= chr($byte);
            }
        }
        if ($line !== '') {
            $flush();
        }

        return implode("\n", $lines)."\n";
    }
}
