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
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class StockIssueExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithStrictNullComparison,
    WithColumnFormatting
{
    protected Collection $items;
    protected array $customerRows = []; // baris judul "Customer: ..."
    protected int $colCount = 8;        // total kolom A..H

    public function __construct(Collection $items)
    {
        $this->items = $items;
    }

    public function collection()
    {
        $currentRow = 2; // row 1 heading
        $final = new Collection();

        $groups = $this->items->groupBy(function ($item) {
            $name = preg_replace('/\s+/', ' ', trim($item->NAME1 ?? ''));
            return $name === '' ? '(Unknown Customer)' : $name;
        });

        foreach ($groups as $customerName => $rows) {
            // baris judul customer (akan di-merge A..H)
            $customerHeader = (object) [
                'is_customer_header' => true,
                'customer_name'      => 'Customer: ' . $customerName,
            ];

            $final->push($customerHeader);
            $this->customerRows[] = $currentRow;
            $currentRow++;

            $no = 0;
            foreach ($rows as $item) {
                $no++;
                $item->is_customer_header = false;
                $item->row_no = $no; // nomor urut per customer
                $final->push($item);
                $currentRow++;
            }
        }

        return $final;
    }

    public function headings(): array
    {
        return [
            'No.',
            'Sales Order',
            'Item',
            'Material Finish',
            'Description',
            'Stock On Hand',
            'UoM',
            'Total Value',
        ];
    }

    protected function formatUom($uom): string
    {
        $clean = strtoupper(trim((string) $uom));
        return $clean === 'ST' ? 'PC' : $clean;
    }

    public function map($item): array
    {
        // baris judul customer: isi kolom A saja, sisanya kosong (A..H)
        if (property_exists($item, 'is_customer_header') && $item->is_customer_header) {
            return [$item->customer_name, '', '', '', '', '', '', ''];
        }

        return [
            (int)($item->row_no ?? 0),      // No.
            $item->VBELN ?? '',             // Sales Order
            (int)($item->POSNR ?? 0),       // Item
            $item->MATNH ?? '',             // Material Finish
            $item->MAKTXH ?? '',            // Description
            (float)($item->STOCK3 ?? 0),    // Stock On Hand
            $this->formatUom($item->MEINS ?? ''), // UoM
            (float)($item->TPRC ?? 0),      // Total Value
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $styles = [
            1 => ['font' => ['bold' => true]], // heading tebal
        ];

        // Merge baris judul customer melebar dari A sampai H
        foreach ($this->customerRows as $row) {
            $sheet->mergeCells("A{$row}:H{$row}");

            $styles[$row] = [
                'font' => ['bold' => true, 'size' => 12],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['rgb' => 'E9ECEF'],
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '333333'],
                    ],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                ],
            ];
        }

        return $styles;
    }

    public function columnFormats(): array
    {
        $intNoDecimal = '#,##0';
        $currencyUSD  = '[$$-409]#,##0';

        return [
            'A' => $intNoDecimal, // No.
            'F' => $intNoDecimal, // Stock On Hand
            'H' => $currencyUSD,  // Total Value
        ];
    }
}
