<?php

namespace App\Support\Import;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Thin, read-only wrapper over PhpSpreadsheet for the STOCKWISE Excel imports.
 * Streams a single sheet, maps a header row to keys, yields associative rows.
 * A row/column LimitFilter keeps memory bounded against phantom rows.
 */
class SpreadsheetReader
{
    /**
     * @param  int  $headerRow  1-based row holding column headers
     * @param  int|null  $firstDataRow  1-based first data row (defaults headerRow + 1)
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $path, string $sheet, int $headerRow = 1, ?int $firstDataRow = null): Collection
    {
        $firstDataRow ??= $headerRow + 1;
        $grid = $this->grid($path, $sheet);

        $headers = array_map(
            fn ($h) => is_string($h) ? trim($h) : ($h === null ? '' : (string) $h),
            $grid[$headerRow - 1] ?? []
        );

        $out = new Collection;

        foreach (array_slice($grid, $firstDataRow - 1) as $cells) {
            $row = [];
            $hasValue = false;

            foreach ($headers as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $value = $cells[$i] ?? null;
                $row[$key] = $value;
                $hasValue = $hasValue || $value !== null;
            }

            if ($hasValue) {
                $out->push($row);
            }
        }

        return $out;
    }

    /**
     * Raw 2D grid (0-indexed rows & cols), strings trimmed, blanks as null.
     *
     * @return list<list<mixed>>
     */
    public function grid(string $path, string $sheet, int $maxRow = 12000, int $maxColumn = 60): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Excel file not found: {$path}");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new LimitFilter($maxRow, $maxColumn));
        if (method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly([$sheet]);
        }

        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getSheetByName($sheet)
            ?? throw new RuntimeException("Sheet '{$sheet}' not found in {$path}");

        $grid = $worksheet->toArray(null, true, false, false);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $worksheet, $reader);
        gc_collect_cycles();

        return array_map(
            fn ($row) => array_map(
                fn ($c) => is_string($c) ? (trim($c) === '' ? null : trim($c)) : $c,
                $row
            ),
            $grid
        );
    }

    /**
     * Grid built with the row iterator, stopping after `$stopAfterEmpty` consecutive
     * blank rows. Much faster than grid() on sheets padded with phantom rows.
     *
     * @return list<list<mixed>>
     */
    public function scan(string $path, string $sheet, int $maxColumn = 30, int $stopAfterEmpty = 80): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Excel file not found: {$path}");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new LimitFilter(1_048_576, $maxColumn));
        if (method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly([$sheet]);
        }

        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getSheetByName($sheet)
            ?? throw new RuntimeException("Sheet '{$sheet}' not found in {$path}");

        $out = [];
        $emptyStreak = 0;

        foreach ($worksheet->getRowIterator() as $row) {
            $cells = [];
            $hasValue = false;
            $cellIterator = $row->getCellIterator('A', \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($maxColumn));
            $cellIterator->setIterateOnlyExistingCells(false);

            foreach ($cellIterator as $cell) {
                $v = $cell->getValue();
                if (is_string($v)) {
                    $v = trim($v) === '' ? null : trim($v);
                }
                $cells[] = $v;
                $hasValue = $hasValue || $v !== null;
            }

            $out[] = $cells;
            $emptyStreak = $hasValue ? 0 : $emptyStreak + 1;
            if ($emptyStreak >= $stopAfterEmpty) {
                break;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $worksheet, $reader);
        gc_collect_cycles();

        return $out;
    }

    /** @return list<string> sheet names in the workbook */
    public function sheetNames(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        return $reader->listWorksheetNames($path);
    }
}
