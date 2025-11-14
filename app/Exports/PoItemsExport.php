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
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class PoItemsExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithStrictNullComparison,
    WithColumnFormatting,
    WithEvents
{
    /** @var \Illuminate\Support\Collection */
    protected $items;

    /** baris-baris yang harus di-merge untuk header “Customer: …” */
    protected array $customerRows = [];

    public function __construct(Collection $items)
    {
        // Menerima koleksi $items dari PoReportController
        // Koleksi ini sekarang berisi EDATU_FORMATTED dan QTY_PO
        $this->items = $items;
    }

    public function collection()
    {
        $final = new Collection();
        $rowNum = 2; // baris 1 = heading

        $groups = $this->items->groupBy(function ($it) {
            $name = preg_replace('/\s+/', ' ', trim($it->CUSTOMER ?? ''));
            return $name === '' ? '(Unknown Customer)' : $name;
        });

        foreach ($groups as $customerName => $rows) {
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

    /**
     * Mengganti header kolom 'Net Price' menjadi 'Req. Deliv. Date'.
     */
    public function headings(): array
    {
        // tidak ada kolom “Customer”; pakai header group
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
            'Packing',
            'Req. Deliv. Date', // <<< DIUBAH (sebelumnya 'Net Price')
            'Remark',
        ];
    }

    /**
     * Menyesuaikan pemetaan data ke kolom.
     */
    public function map($it): array
    {
        // header customer: isi kolom A saja, total 12 kolom (A..L)
        if (!empty($it->is_customer_header)) {
            return [$it->customer_name, '', '', '', '', '', '', '', '', '', '', ''];
        }

        // data item; REMARK diambil dari controller (alias REMARK)
        return [
            (string)($it->PO ?? ''),            // A
            (string)($it->SO ?? ''),            // B
            (int)   ($it->POSNR ?? 0),           // C
            (string)($it->MATNR ?? ''),         // D
            (string)($it->MAKTX ?? ''),         // E
            (int)   ($it->QTY_PO ?? 0),          // F (DIUBAH dari KWMENG)
            (int)   ($it->QTY_GI ?? 0),          // G
            (int)   ($it->QTY_BALANCE2 ?? 0),    // H
            (int)   ($it->KALAB ?? 0),           // I
            (int)   ($it->KALAB2 ?? 0),          // J
            (string)($it->EDATU_FORMATTED ?? ''), // K (DIUBAH dari NETPR)
            (string)($it->REMARK ?? ''),        // L
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $styles = [
            1 => ['font' => ['bold' => true]], // header kolom
        ];

        // merge baris “Customer: …” dari A sampai L (12 kolom)
        // Jumlah kolom tidak berubah, jadi 'A{$r}:L{$r}' tetap valid.
        foreach ($this->customerRows as $r) {
            $sheet->mergeCells("A{$r}:L{$r}");
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
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                ],
            ];
        }

        return $styles;
    }

    /**
     * Mengganti format kolom K (Net Price) menjadi format Teks (untuk Tanggal).
     */
    public function columnFormats(): array
    {
        // integer untuk qty; Teks untuk Tanggal (Kolom K)
        return [
            'F' => NumberFormat::FORMAT_NUMBER,        // Qty PO
            'G' => NumberFormat::FORMAT_NUMBER,        // Shipped
            'H' => NumberFormat::FORMAT_NUMBER,        // Outs. Ship
            'I' => NumberFormat::FORMAT_NUMBER,        // WHFG
            'J' => NumberFormat::FORMAT_NUMBER,        // Packing
            'K' => NumberFormat::FORMAT_TEXT,          // Req. Deliv. Date (DIUBAH)
        ];
    }

    /**
     * Event untuk styling (tidak perlu diubah).
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();

                // wrap & top align untuk kolom Remark (Kolom L, masih sama)
                $sheet->getStyle("L2:L{$highestRow}")
                    ->getAlignment()
                    ->setWrapText(true)
                    ->setVertical(Alignment::VERTICAL_TOP);

                // lebar kolom Remark yang nyaman (override autosize)
                $sheet->getColumnDimension('L')->setWidth(60);

                // (Opsional) Pusatkan kolom K (Tanggal)
                $sheet->getStyle("K2:K{$highestRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            },
        ];
    }
}
