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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
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
    protected $items;
    protected $customerRows = []; // baris-baris header "Customer: ..."

    public function __construct(Collection $items)
    {
        $this->items = $items;
    }

    public function collection()
    {
        $currentRow = 2; // baris 1 = heading
        $finalCollection = new Collection();

        $groups = $this->items->groupBy(function ($item) {
            $name = preg_replace('/\s+/', ' ', trim($item->headerInfo->NAME1 ?? ''));
            return $name === '' ? '(Unknown Customer)' : $name;
        });

        foreach ($groups as $customerName => $rows) {
            // baris judul customer (akan di-merge A..M)
            $customerHeader = (object) [
                'is_customer_header' => true,
                'customer_name'      => 'Customer: ' . $customerName,
            ];
            $finalCollection->push($customerHeader);
            $this->customerRows[] = $currentRow;
            $currentRow++;

            foreach ($rows as $item) {
                $item->is_customer_header = false;
                $finalCollection->push($item);
                $currentRow++;
            }
        }

        return $finalCollection;
    }

    public function headings(): array
    {
        // ❌ Kolom "Customer" dihapus.
        return [
            'PO',
            'SO',
            'Item',
            'Material FG',
            'Description',
            'Qty SO',
            'Outs. SO',
            'WHFG',
            'Stock Packg.',
            'GR ASSY',
            'GR PAINT',
            'GR PKG',
            'Remark',
        ];
    }

    public function map($item): array
    {
        // baris header customer: taruh teks di kolom A, sisanya kosong (total 13 kolom)
        if (property_exists($item, 'is_customer_header') && $item->is_customer_header) {
            return [$item->customer_name, '', '', '', '', '', '', '', '', '', '', '', ''];
        }

        // Baris data item — semua angka jadi integer (tanpa desimal)
        return [
            $item->headerInfo->BSTNK ?? '', // A: PO
            $item->VBELN,                   // B: SO
            (int) ($item->POSNR ?? 0),      // C: Item
            $item->MATNR,                   // D: Material FG
            $item->MAKTX,                   // E: Description

            (int) ($item->KWMENG ?? 0),     // F: Qty SO
            (int) ($item->PACKG  ?? 0),     // G: Outs. SO
            (int) ($item->KALAB  ?? 0),     // H: WHFG
            (int) ($item->KALAB2 ?? 0),     // I: Stock Packg.
            (int) ($item->ASSYM  ?? 0),     // J: GR ASSY
            (int) ($item->PAINT  ?? 0),     // K: GR PAINT
            (int) ($item->MENGE  ?? 0),     // L: GR PKG

            $item->remark,                  // M: Remark
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $styles = [
            1 => ['font' => ['bold' => true]], // heading tebal
        ];

        // Merge baris judul customer melebar dari A sampai M (13 kolom)
        foreach ($this->customerRows as $row) {
            $sheet->mergeCells("A{$row}:M{$row}");
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
        // Format integer tanpa desimal; jika tak ingin pemisah ribuan, ganti ke '0'
        $intNoDecimal = '#,##0';

        // Kolom angka sekarang F..L (Qty SO sampai GR PKG)
        return [
            'F' => $intNoDecimal, // Qty SO
            'G' => $intNoDecimal, // Outs. SO
            'H' => $intNoDecimal, // WHFG
            'I' => $intNoDecimal, // Stock Packg.
            'J' => $intNoDecimal, // GR ASSY
            'K' => $intNoDecimal, // GR PAINT
            'L' => $intNoDecimal, // GR PKG
        ];
    }
}
