<?php
/* composer update maatwebsite/excel -- -W */

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

class SoItemsExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithStrictNullComparison,   // pastikan 0 tidak dianggap kosong
    WithColumnFormatting        // format angka untuk kolom kuantitas
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
            'Costumer',
            'PO',
            'SO',
            'Item',
            'Material FG',
            'Description',
            'Qty SO',
            'Outs. SO',
            'WHFG',
            'Stock Packing',
            'GR ASSY',
            'GR PAINT',
            'GR PKG',
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
            $item->headerInfo->NAME1 ?? '',
            $item->headerInfo->BSTNK ?? '',
            $item->VBELN,
            (int) ($item->POSNR ?? 0),
            $item->MATNR,
            $item->MAKTX,
            (float) ($item->KWMENG ?? 0),
            (float) ($item->PACKG  ?? 0),
            (float) ($item->KALAB  ?? 0),
            (float) ($item->KALAB2 ?? 0),
            (float) ($item->ASSYM  ?? 0),  // GR ASSY
            (float) ($item->PAINT  ?? 0),  // GR PAINT
            (float) ($item->MENGE  ?? 0),  // GR PKG
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
            1 => ['font' => ['bold' => true]], // Header tebal
        ];
    }

    /**
     * Format kolom angka agar Excel selalu menampilkan 0 (bukan blank)
     * dan tidak mengubahnya ke scientific notation.
     *
     * @return array
     */
    public function columnFormats(): array
    {
        // Kolom:
        // A Costumer, B PO, C SO, D Item, E Material FG, F Description,
        // G Qty SO, H Outs. SO, I WHFG, J Stock Packing,
        // K GR ASSY, L GR PAINT, M GR PKG, N Remark
        return [
            'G' => NumberFormat::FORMAT_NUMBER, // Qty SO
            'H' => NumberFormat::FORMAT_NUMBER, // Outs. SO
            'I' => NumberFormat::FORMAT_NUMBER, // WHFG
            'J' => NumberFormat::FORMAT_NUMBER, // Stock Packing
            'K' => NumberFormat::FORMAT_NUMBER, // GR ASSY
            'L' => NumberFormat::FORMAT_NUMBER, // GR PAINT
            'M' => NumberFormat::FORMAT_NUMBER, // GR PKG
        ];
    }
}
