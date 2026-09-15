<?php

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a header row + data rows into a downloadable file (xlsx / csv / pdf-print).
 * PHASE 10 — brief §AG. csv/pdf write straight to the output stream so large
 * datasets stay memory-light; xlsx can't (PhpSpreadsheet builds the whole
 * workbook in memory before the writer runs), so that path raises memory_limit
 * instead — see xlsx() below.
 */
class DatasetExporter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, string|int|float|null>>  $rows
     */
    public function download(string $format, string $filename, array $headers, iterable $rows, string $title = ''): StreamedResponse
    {
        return match ($format) {
            'xlsx' => $this->xlsx($filename, $headers, $rows, $title),
            'pdf' => $this->pdf($filename, $headers, $rows, $title),
            default => $this->csv($filename, $headers, $rows),
        };
    }

    private function csv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "{$filename}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsx(string $filename, array $headers, iterable $rows, string $title): StreamedResponse
    {
        // Datasets like Master Barang / NPBG run 8-9k+ rows — PhpSpreadsheet holds
        // the whole cell collection in memory (no true streaming writer here), so
        // the default 128M memory_limit reliably fatal-errors past a few thousand
        // rows. Same fix already applied to DATA.xlsx in LegacyExportController.
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(substr($title ?: 'Data', 0, 31));

        $r = 1;
        if ($title !== '') {
            $sheet->setCellValue([1, $r], $title);
            $sheet->getStyle([1, $r])->getFont()->setBold(true)->setSize(13);
            $r += 2;
        }

        $headerRow = $r;
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, $headerRow], $h);
        }
        $sheet->getStyle([1, $headerRow, count($headers), $headerRow])->getFont()->setBold(true);
        $r++;

        foreach ($rows as $row) {
            $c = 1;
            foreach ($row as $value) {
                $sheet->setCellValue([$c++, $r], $value);
            }
            $r++;
        }

        // setAutoSize(true) makes PhpSpreadsheet measure every cell in the
        // column at save time — fine for a few hundred rows, but it's what
        // actually pushes datasets this size (thousands of rows) into the
        // memory/time exhaustion this method just widened room for. A width
        // guessed from the header label is a lot cheaper and good enough.
        foreach ($headers as $i => $h) {
            $sheet->getColumnDimensionByColumn($i + 1)->setWidth(max(12, min(40, strlen((string) $h) + 4)));
        }

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, "{$filename}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** A print-optimised HTML document; the browser's "Save as PDF" turns it into a PDF. */
    private function pdf(string $filename, array $headers, iterable $rows, string $title): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $title) {
            $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
            echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>'.$esc($title).'</title>';
            echo '<style>@media print{@page{size:A4 landscape;margin:12mm}}';
            echo 'body{font:12px/1.4 Arial,sans-serif;color:#111}h1{font-size:16px;margin:0 0 4px}';
            echo '.meta{color:#666;margin-bottom:12px}table{border-collapse:collapse;width:100%}';
            echo 'th,td{border:1px solid #bbb;padding:4px 6px;text-align:left}th{background:#f1f5f9}';
            echo 'tr:nth-child(even) td{background:#fafafa}</style></head><body onload="window.print()">';
            echo '<h1>STOCKWISE — '.$esc($title).'</h1>';
            echo '<div class="meta">PT Surya Inti Gas · dicetak '.now()->format('d/m/Y H:i').'</div><table><thead><tr>';
            foreach ($headers as $h) {
                echo '<th>'.$esc($h).'</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($row as $v) {
                    echo '<td>'.$esc($v).'</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></body></html>';
        }, "{$filename}.html", ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
