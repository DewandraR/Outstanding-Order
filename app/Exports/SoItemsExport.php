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
            'MACHI GR',         // J  (MACHI qty)
            'ASSY GR',          // K  (ASSYM qty)
            'PAINT GR',         // L  (PAINTM qty)
            'PACKING GR',       // M  (PACKGM qty)
            'Remark',           // N
        ];
    }

    public function map($item): array
    {
        // baris judul customer: isi kolom A saja, sisanya kosong (A..N = 14 kolom)
        if (property_exists($item, 'is_customer_header') && $item->is_customer_header) {
            return [$item->customer_name, '', '', '', '', '', '', '', '', '', '', '', '', ''];
        }

        return [
            $item->headerInfo->BSTNK ?? '',          // A: PO
            $item->VBELN ?? '',                      // B: SO
            (int)($item->POSNR ?? 0),                // C: Item
            $item->MATNR ?? '',                      // D: Material FG
            $item->MAKTX ?? '',                      // E: Description

            (float)($item->KWMENG ?? 0),             // F: Qty SO
            (float)($item->PACKG  ?? 0),             // G: Outs. SO
            (float)($item->KALAB  ?? 0),             // H: WHFG
            (float)($item->KALAB2 ?? 0),             // I: Stock Packg.

            (float)($item->MACHI  ?? 0),             // J: MACHI GR
            (float)($item->ASSYM  ?? 0),             // K: ASSY GR
            (float)($item->PAINTM ?? 0),             // L: PAINT GR
            (float)($item->PACKGM ?? 0),             // M: PACKING GR

            $item->remark ?? '',                     // N: Remark
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
        // Angka dengan hingga 3 desimal TANPA nol ekor (53 -> "53", 1.92 -> "1.92", 1.234 -> "1.234")
        $upTo3Decimals = '#,##0.###';

        return [
            // Kuantitas header
            'F' => $intNoDecimal,   // Qty SO (biasanya integer)
            'G' => $intNoDecimal,   // Outs. SO
            'H' => $intNoDecimal,   // WHFG
            'I' => $intNoDecimal,   // Stock Packg.

            // GR per proses (qty) — tampilkan sampai 3 desimal, tanpa ",000"
            'J' => $intNoDecimal,  // MACHI GR
            'K' => $intNoDecimal,  // ASSY GR
            'L' => $intNoDecimal,  // PAINT GR
            'M' => $intNoDecimal,  // PACKING GR
        ];
    }
}
