<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class StockItemsExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithColumnFormatting
{
    protected Collection $items;
    protected string $stockType;     // 'whfg' | 'pack'
    protected string $stockColumn;   // 'KALAB' | 'KALAB2'

    // Menandai baris judul customer agar bisa di-style & merge
    protected array $customerRows = [];
    protected string $currency;

    public function __construct(Collection $items, string $stockType)
    {
        $this->items       = $items;
        $this->stockType   = $stockType;
        $this->stockColumn = $stockType === 'whfg' ? 'KALAB' : 'KALAB2';
        $this->currency    = (string) ($items->first()->WAERK ?? 'IDR');
    }

    /**
     * Bangun koleksi final: selingi data dengan baris judul "Customer: ..."
     */
    public function collection()
    {
        $final = new Collection();
        $currentRow = 2; // baris 1 adalah headings

        // Kelompokkan data per customer (NAME1)
        $groups = $this->items->groupBy(function ($it) {
            $name = preg_replace('/\s+/', ' ', trim($it->NAME1 ?? ''));
            return $name === '' ? '(Unknown Customer)' : $name;
        });

        foreach ($groups as $customerName => $rows) {
            // Sisipkan baris header customer
            $final->push((object)[
                'is_customer_header' => true,
                'customer_name'      => 'Customer: ' . $customerName,
            ]);
            $this->customerRows[] = $currentRow;
            $currentRow++;

            // Lanjutkan baris data item
            foreach ($rows as $item) {
                $item->is_customer_header = false;
                $final->push($item);
                $currentRow++;
            }
        }

        return $final;
    }

    /**
     * Header kolom (tanpa kolom Customer).
     */
    public function headings(): array
    {
        $stockHeader = $this->stockType === 'whfg' ? 'WHFG' : 'Stock Packg.';
        return [
            'PO',
            'SO',
            'Item',
            'Material FG',
            'Deskripsi FG',
            'Qty SO',
            $stockHeader,
            "Net Price ({$this->currency})",
        ];
    }

    /**
     * Map setiap baris; baris header customer vs baris data.
     */
    public function map($item): array
    {
        if (!empty($item->is_customer_header)) {
            // 8 kolom total -> isi kolom A dengan judul, sisanya kosong
            return [$item->customer_name, '', '', '', '', '', '', ''];
        }

        // Data item; pastikan angka jadi integer
        $stockValue = $item->{$this->stockColumn} ?? 0;

        return [
            $item->BSTNK ?? '-',            // PO
            $item->VBELN,                   // SO
            (int) ($item->POSNR ?? 0),      // Item
            $item->MATNR,                   // Material FG
            $item->MAKTX,                   // Deskripsi FG
            (int) ($item->KWMENG ?? 0),     // Qty SO
            (int) ($stockValue ?? 0),       // WHFG / Stock Packg.
            (int) ($item->NETPR ?? 0),      // Net Price
        ];
    }

    /**
     * Styling header + baris judul customer (merge A..H).
     */
    public function styles(Worksheet $sheet)
    {
        $styles = [
            1 => [
                'font' => ['bold' => true, 'size' => 10],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFE0E0E0'],
                ],
            ],
        ];

        foreach ($this->customerRows as $row) {
            // Merge dari kolom A sampai H (8 kolom)
            $sheet->mergeCells("A{$row}:H{$row}");
            $styles[$row] = [
                'font' => ['bold' => true, 'size' => 12],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFF4F6F8'],
                ],
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

    /**
     * Format angka jadi bilangan bulat tanpa desimal (kolom F..H).
     */
    public function columnFormats(): array
    {
        $intNoDecimal = '#,##0'; // ganti '0' jika tak ingin pemisah ribuan
        return [
            'F' => $intNoDecimal, // Qty SO
            'G' => $intNoDecimal, // WHFG / Stock Packg.
            'H' => $intNoDecimal, // Net Price
        ];
    }
}
