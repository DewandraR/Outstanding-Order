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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class PoItemsExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithStrictNullComparison,
    WithColumnFormatting
{
    /** @var \Illuminate\Support\Collection */
    protected $items;

    /** baris-baris yang harus di-merge untuk header “Customer: …” */
    protected array $customerRows = [];

    public function __construct(Collection $items)
    {
        $this->items = $items;
    }

    public function collection()
    {
        $final = new Collection();
        $rowNum = 2; // baris 1 = heading

        // group per customer (nama dibersihkan supaya konsisten)
        $groups = $this->items->groupBy(function ($it) {
            $name = preg_replace('/\s+/', ' ', trim($it->CUSTOMER ?? ''));
            return $name === '' ? '(Unknown Customer)' : $name;
        });

        foreach ($groups as $customerName => $rows) {
            // sisipkan baris header customer (akan di-merge A..K)
            $final->push((object)[
                'is_customer_header' => true,
                'customer_name'      => 'Customer: ' . $customerName,
            ]);
            $this->customerRows[] = $rowNum;
            $rowNum++;

            foreach ($rows as $it) {
                $it->is_customer_header = false;
                $final->push($it);
                $rowNum++;
            }
        }

        return $final;
    }

    public function headings(): array
    {
        // Tidak ada kolom “Customer” karena sudah dibuat header terpisah per group
        return [
            'PO',
            'SO',
            'Item',
            'Material FG',
            'Description',
            'Qty PO',
            'Shipped',
            'Outs. Ship',
            'WHFG',
            'FG',
            'Net Price',
        ];
    }

    public function map($it): array
    {
        // baris header customer → isi kolom A saja, sisanya kosong (total 11 kolom A..K)
        if (!empty($it->is_customer_header)) {
            return [$it->customer_name, '', '', '', '', '', '', '', '', '', ''];
        }

        // data item (semua angka integer kecuali Net Price)
        return [
            (string)($it->PO ?? ''),                 // A
            (string)($it->SO ?? ''),                 // B
            (int)   ($it->POSNR ?? 0),               // C
            (string)($it->MATNR ?? ''),              // D
            (string)($it->MAKTX ?? ''),              // E
            (int)   ($it->KWMENG ?? 0),              // F  Qty PO
            (int)   ($it->QTY_GI ?? 0),              // G  Shipped
            (int)   ($it->QTY_BALANCE2 ?? 0),        // H  Outs. Ship
            (int)   ($it->KALAB ?? 0),               // I  WHFG
            (int)   ($it->KALAB2 ?? 0),              // J  FG
            (float) ($it->NETPR ?? 0),               // K  Net Price (numeric)
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $styles = [
            1 => ['font' => ['bold' => true]], // header kolom
        ];

        // merge baris “Customer: …” dari A sampai K (11 kolom)
        foreach ($this->customerRows as $r) {
            $sheet->mergeCells("A{$r}:K{$r}");
            $styles[$r] = [
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
        // integer tanpa desimal untuk qty; 2 desimal untuk Net Price
        return [
            'F' => NumberFormat::FORMAT_NUMBER,        // Qty PO
            'G' => NumberFormat::FORMAT_NUMBER,        // Shipped
            'H' => NumberFormat::FORMAT_NUMBER,        // Outs. Ship
            'I' => NumberFormat::FORMAT_NUMBER,        // WHFG
            'J' => NumberFormat::FORMAT_NUMBER,        // FG
            'K' => NumberFormat::FORMAT_NUMBER_00,     // Net Price
        ];
    }
}
