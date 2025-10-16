<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class SoItemsExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithStrictNullComparison,
    WithColumnFormatting
{
    protected Collection $items;
    protected array $customerRows = []; // baris-baris judul "Customer: ..."

    public function __construct(Collection $items)
    {
        $this->items = $items;
    }

    public function collection()
    {
        $currentRow = 2; // baris 1 = heading
        $final = new Collection();

        $groups = $this->items->groupBy(function ($item) {
            $name = preg_replace('/\s+/', ' ', trim($item->headerInfo->NAME1 ?? ''));
            return $name === '' ? '(Unknown Customer)' : $name;
        });

        foreach ($groups as $customerName => $rows) {
            // baris judul customer (akan di-merge A..N)
            $customerHeader = (object) [
                'is_customer_header' => true,
                'customer_name'      => 'Customer: ' . $customerName,
            ];
            $final->push($customerHeader);
            $this->customerRows[] = $currentRow;
            $currentRow++;

            foreach ($rows as $item) {
                $item->is_customer_header = false;
                $final->push($item);
                $currentRow++;
            }
        }

        return $final;
    }

    public function headings(): array
    {
        // Kolom: 14 kolom total (A..N)
        return [
            'PO',               // A
            'SO',               // B
            'Item',             // C
            'Material FG',      // D
            'Description',      // E
            'Qty SO',           // F
            'Outs. SO',         // G
            'WHFG',             // H
            'Stock Packg.',     // I
            '% MACHI',          // J  (PRSM)
            '% ASSY',           // K  (PRSA)
            '% PAINT',          // L  (PRSI)
            '% PACKING',        // M  (PRSP)
            'Remark',           // N
        ];
    }

    public function map($item): array
    {
        // baris judul customer: isi kolom A saja, sisanya kosong (A..N = 14 kolom)
        if (property_exists($item, 'is_customer_header') && $item->is_customer_header) {
            return [$item->customer_name, '', '', '', '', '', '', '', '', '', '', '', '', ''];
        }

        // Pastikan nilai persen tetap 0..100 (integer)
        $clamp = function ($n) {
            $n = (int)($n ?? 0);
            if ($n < 0)   $n = 0;
            if ($n > 100) $n = 100;
            return $n;
        };

        return [
            $item->headerInfo->BSTNK ?? '',  // A: PO
            $item->VBELN ?? '',              // B: SO
            (int)($item->POSNR ?? 0),        // C: Item
            $item->MATNR ?? '',              // D: Material FG
            $item->MAKTX ?? '',              // E: Description

            (int)($item->KWMENG ?? 0),       // F: Qty SO
            (int)($item->PACKG  ?? 0),       // G: Outs. SO
            (int)($item->KALAB  ?? 0),       // H: WHFG
            (int)($item->KALAB2 ?? 0),       // I: Stock Packg.

            $clamp($item->PRSM ?? 0),        // J: % MACHI
            $clamp($item->PRSA ?? 0),        // K: % ASSY
            $clamp($item->PRSI ?? 0),        // L: % PAINT
            $clamp($item->PRSP ?? 0),        // M: % PACKING

            $item->remark ?? '',             // N: Remark
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $styles = [
            1 => ['font' => ['bold' => true]], // heading tebal
        ];

        // Merge baris judul customer melebar dari A sampai N (14 kolom)
        foreach ($this->customerRows as $row) {
            $sheet->mergeCells("A{$row}:N{$row}");
            $styles[$row] = [
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'E9ECEF']],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '333333'],
                    ],
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                ],
            ];
        }

        return $styles;
    }

    public function columnFormats(): array
    {
        // Angka bulat tanpa desimal
        $intNoDecimal = '#,##0';
        // Tampilkan 0..100 dengan tanda % tanpa perlu bagi 100
        $pct0to100 = '0"%"';

        return [
            // Kuantitas
            'F' => $intNoDecimal, // Qty SO
            'G' => $intNoDecimal, // Outs. SO
            'H' => $intNoDecimal, // WHFG
            'I' => $intNoDecimal, // Stock Packg.

            // Persentase proses
            'J' => $pct0to100,    // % MACHI
            'K' => $pct0to100,    // % ASSY
            'L' => $pct0to100,    // % PAINT
            'M' => $pct0to100,    // % PACKING
        ];
    }
}
