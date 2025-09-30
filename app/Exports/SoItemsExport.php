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
    protected $customerRows = []; // Untuk melacak baris-baris header customer

    /**
     * Menerima koleksi data item dari controller saat class ini dibuat.
     *
     * @param \Illuminate\Support\Collection $items
     */
    public function __construct(Collection $items)
    {
        // Data asli
        $this->items = $items;
    }

    /**
     * Mengembalikan koleksi data yang akan diproses oleh library Excel.
     * Logika utama untuk menyisipkan header customer berada di sini.
     *
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        // Baris awal dimulai dari 2 (karena baris 1 adalah Heading/Judul kolom)
        $currentRow = 2;
        $finalCollection = new Collection();
        $groups = $this->items->groupBy(function ($item) {
            $name = preg_replace('/\s+/', ' ', trim($item->headerInfo->NAME1 ?? ''));
            return $name === '' ? '(Unknown Customer)' : $name;
        });

        foreach ($groups as $customerName => $rows) {
            // 1. Tambahkan baris header customer
            $customerHeader = (object) [
                'is_customer_header' => true,
                'customer_name' => "Customer: " . $customerName,
            ];
            $finalCollection->push($customerHeader);
            $this->customerRows[] = $currentRow; // Simpan baris untuk styling
            $currentRow++;

            // 2. Tambahkan baris data item
            foreach ($rows as $item) {
                // Tambahkan headerInfo agar bisa diakses di map
                $item->is_customer_header = false;
                $finalCollection->push($item);
                $currentRow++;
            }
        }

        return $finalCollection;
    }

    /**
     * Mendefinisikan judul untuk setiap kolom di baris pertama file Excel.
     *
     * @return array
     */
    public function headings(): array
    {
        return [
            'Customer',
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

    /**
     * Memetakan data dari setiap item objek ke dalam format array.
     *
     * @param mixed $item Satu baris data dari koleksi.
     * @return array
     */
    public function map($item): array
    {
        // Periksa apakah ini baris header customer
        if (property_exists($item, 'is_customer_header') && $item->is_customer_header) {
            // Untuk baris header, letakkan nama customer di kolom pertama dan sisanya kosong
            return [$item->customer_name, '', '', '', '', '', '', '', '', '', '', '', '', ''];
        }

        // Baris data item biasa
        return [
            $item->headerInfo->NAME1 ?? '', // Kolom Customer
            $item->headerInfo->BSTNK ?? '', // Kolom PO
            $item->VBELN,                   // Kolom SO
            (int) ($item->POSNR ?? 0),      // Kolom Item
            $item->MATNR,                   // Kolom Material FG
            $item->MAKTX,                   // Kolom Description
            (float) ($item->KWMENG ?? 0),   // Kolom Qty SO
            (float) ($item->PACKG  ?? 0),   // Kolom Outs. SO
            (float) ($item->KALAB  ?? 0),   // Kolom WHFG
            (float) ($item->KALAB2 ?? 0),   // Kolom Stock Packg.
            (float) ($item->ASSYM  ?? 0),   // Kolom GR ASSY
            (float) ($item->PAINT  ?? 0),   // Kolom GR PAINT
            (float) ($item->MENGE  ?? 0),   // Kolom GR PKG
            $item->remark,                  // Kolom Remark
        ];
    }

    /**
     * Menerapkan style pada sheet Excel.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @return array
     */
    public function styles(Worksheet $sheet)
    {
        $styles = [
            1 => ['font' => ['bold' => true]], // Header tebal
        ];

        // Terapkan style untuk baris customer (gabung cell dan beri warna latar)
        foreach ($this->customerRows as $row) {
            // Gabungkan kolom A sampai N (sesuaikan jika jumlah kolom berubah)
            $sheet->mergeCells("A{$row}:N{$row}");

            $styles[$row] = [
                'font' => [
                    'bold' => true,
                    'size' => 12, // Ukuran font lebih besar
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['rgb' => 'E9ECEF'] // Warna latar abu-abu muda
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
     * Format kolom angka agar Excel selalu menampilkan 0 (bukan blank)
     * dan tidak mengubahnya ke scientific notation.
     *
     * @return array
     */
    public function columnFormats(): array
    {
        return [
            // Kolom G-M untuk kuantitas
            'G' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // Qty SO
            'H' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // Outs. SO
            'I' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // WHFG
            'J' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // Stock Packg.
            'K' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // GR ASSY
            'L' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // GR PAINT
            'M' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // GR PKG
        ];
    }
}
