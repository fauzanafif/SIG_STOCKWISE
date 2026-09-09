<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates unique document numbers: {DOC}/{PREFIX}/{YY}/{ROMAN-MONTH}/{SEQ}
 * e.g. REQ/SDA/26/IX/001. Sequence is per (doc_type, prefix, year, month) and
 * incremented under a row lock.
 */
class DocumentNumberService
{
    private const ROMAN = [
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
        7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII',
    ];

    public function next(string $docType, string $prefix, ?Carbon $date = null): string
    {
        $date ??= now();
        $year = (int) $date->format('y');
        $month = (int) $date->format('n');

        $seq = DB::transaction(function () use ($docType, $prefix, $year, $month) {
            $row = DB::table('document_sequences')
                ->where(compact('year', 'month'))
                ->where('doc_type', $docType)
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            if ($row) {
                $next = $row->last_sequence + 1;
                DB::table('document_sequences')->where('id', $row->id)
                    ->update(['last_sequence' => $next, 'updated_at' => now()]);

                return $next;
            }

            DB::table('document_sequences')->insert([
                'doc_type' => $docType, 'prefix' => $prefix, 'year' => $year,
                'month' => $month, 'last_sequence' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return 1;
        });

        return sprintf(
            '%s/%s/%02d/%s/%03d',
            $docType, $prefix, $year, self::ROMAN[$month], $seq
        );
    }
}
