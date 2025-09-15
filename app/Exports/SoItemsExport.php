<?php
/* composer update maatwebsite/excel -- -W */
namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SoItemsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $items;

    /**
     * Menerima koleksi data item dari controller saat class ini dibuat.
     *
     * @param \Illuminate\Support\Collection $items
     */
    public function __construct(Collection $items)
    {
        $this->items = $items;
    }

    /**
     * Mengembalikan koleksi data yang akan diproses oleh library Excel.
     *
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return $this->items;
    }

    /**
     * Mendefinisikan judul untuk setiap kolom di baris pertama file Excel.
     *
     * @return array
     */
    public function headings(): array
    {
        return [
            'PO',
            'SO',
            'Item',
            'Material FG',
            'Description',
            'Qty SO',
            'Outs. SO',
            'WHFG',
            'Stock Packing',
            'Remark',
        ];
    }

    /**
     * Memetakan data dari setiap item objek ke dalam format array.
     * Urutan array ini harus sama persis dengan urutan di headings().
     *
     * @param mixed $item Satu baris data dari koleksi.
     * @return array
     */
    public function map($item): array
    {
        return [
            $item->headerInfo->BSTNK ?? '',
            $item->VBELN,
            (int)$item->POSNR,
            $item->MATNR, // MATNR di sini sudah diformat di controller
            $item->MAKTX,
            (float)$item->KWMENG,
            (float)$item->PACKG,
            (float)($item->KALAB ?? 0),
            (float)($item->KALAB2 ?? 0),
            $item->remark,
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
        return [
            // Menerapkan style 'bold' pada seluruh baris pertama (A1, B1, C1, dst.)
            1    => ['font' => ['bold' => true]],
        ];
    }
}
