<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StockItemsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $items;
    protected $stockType;
    protected $stockColumn;

    public function __construct(Collection $items, string $stockType)
    {
        $this->items = $items;
        $this->stockType = $stockType;
        // Menentukan kolom stok yang relevan
        $this->stockColumn = $stockType === 'whfg' ? 'KALAB' : 'KALAB2';
    }

    /**
     * Mengembalikan Collection data item dari Controller.
     */
    public function collection()
    {
        return $this->items;
    }

    /**
     * Mendefinisikan baris header (Header Kolom Excel).
     */
    public function headings(): array
    {
        // 🟢 FIX DI SINI: Menggunakan $this->stockType untuk menentukan judul dinamis
        $stockHeader = $this->stockType === 'whfg' ? 'WHFG' : 'Stock Packing';
        $currency = $this->items->first()->WAERK ?? 'IDR';

        return [
            'Customer',
            'PO',
            'SO',
            'Item',
            'Material FG',
            'Deskripsi FG',
            'Qty SO',
            $stockHeader, // Sekarang akan menampilkan 'WHFG' atau 'Stock Packing'
            "Net Price ({$currency})",
        ];
    }

    /**
     * Memetakan data dari setiap objek item ke baris Excel.
     * @param mixed $item
     */
    public function map($item): array
    {
        // Mengambil nilai KALAB atau KALAB2 berdasarkan $this->stockColumn
        $stockValue = $item->{$this->stockColumn};

        return [
            $item->NAME1 ?? 'N/A',
            $item->BSTNK ?? '-',
            $item->VBELN,
            $item->POSNR,
            $item->MATNR,
            $item->MAKTX,
            (float) $item->KWMENG,
            (float) $stockValue,
            (float) $item->NETPR,
        ];
    }

    /**
     * Menambahkan styling dasar (header bold dan auto-size).
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'size' => 10],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFE0E0E0']
                ]
            ],
        ];
    }
}
