<?php

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a header row + data rows into a downloadable file (xlsx / csv / pdf-print).
 * PHASE 10 — brief §AG. Keeps output streaming so large datasets stay memory-light.
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
        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
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
