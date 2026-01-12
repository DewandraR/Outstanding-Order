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

    protected string $mode; // outstanding|complete

    /** baris-baris yang harus di-merge untuk header “Customer: …” */
    protected array $customerRows = [];

    public function __construct(Collection $items, string $mode = 'outstanding')
    {
        $this->items = $items;
        $mode = strtolower(trim($mode));
        $this->mode = in_array($mode, ['outstanding', 'complete'], true) ? $mode : 'outstanding';
    }

    private function isComplete(): bool
    {
        return $this->mode === 'complete';
    }

    private function lastColLetter(): string
    {
        // complete: 9 kolom (A..I), outstanding: 12 kolom (A..L)
        return $this->isComplete() ? 'I' : 'L';
    }

    private function remarkColLetter(): string
    {
        return $this->isComplete() ? 'I' : 'L';
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

    public function headings(): array
    {
        if ($this->isComplete()) {
            return [
                'PO',
                'SO',
                'Item',
                'Material FG',
                'Description',
                'Qty PO',
                'Shipped',
                'Container Number',
                'Remark',
            ];
        }

        // outstanding (format lama)
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
            'Req. Deliv. Date',
            'Remark',
        ];
    }

    public function map($it): array
    {
        // header customer
        if (!empty($it->is_customer_header)) {
            $cols = $this->isComplete() ? 9 : 12;
            $row = array_fill(0, $cols, '');
            $row[0] = $it->customer_name; // kolom A
            return $row;
        }

        // fallback container: CONTAINER_NUMBER -> NAME4 -> ''
        $container = trim((string)($it->CONTAINER_NUMBER ?? ''));
        if ($container === '') {
            $container = trim((string)($it->NAME4 ?? ''));
        }

        if ($this->isComplete()) {
            return [
                (string)($it->PO ?? ''),          // A
                (string)($it->SO ?? ''),          // B
                (int)   ($it->POSNR ?? 0),        // C
                (string)($it->MATNR ?? ''),       // D
                (string)($it->MAKTX ?? ''),       // E
                (int)   ($it->QTY_PO ?? 0),       // F
                (int)   ($it->QTY_GI ?? 0),       // G
                (string)($container),             // H
                (string)($it->REMARK ?? ''),      // I
            ];
        }

        // outstanding (format lama)
        return [
            (string)($it->PO ?? ''),             // A
            (string)($it->SO ?? ''),             // B
            (int)   ($it->POSNR ?? 0),           // C
            (string)($it->MATNR ?? ''),          // D
            (string)($it->MAKTX ?? ''),          // E
            (int)   ($it->QTY_PO ?? 0),          // F
            (int)   ($it->QTY_GI ?? 0),          // G
            (int)   ($it->QTY_BALANCE2 ?? 0),    // H
            (int)   ($it->KALAB ?? 0),           // I
            (int)   ($it->KALAB2 ?? 0),          // J
            (string)($it->EDATU_FORMATTED ?? ''),// K
            (string)($it->REMARK ?? ''),         // L
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $styles = [
            1 => ['font' => ['bold' => true]], // header kolom
        ];

        $lastCol = $this->lastColLetter();

        foreach ($this->customerRows as $r) {
            $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
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

    public function columnFormats(): array
    {
        if ($this->isComplete()) {
            return [
                'F' => NumberFormat::FORMAT_NUMBER, // Qty PO
                'G' => NumberFormat::FORMAT_NUMBER, // Shipped
                'H' => NumberFormat::FORMAT_TEXT,   // Container Number
            ];
        }

        return [
            'F' => NumberFormat::FORMAT_NUMBER, // Qty PO
            'G' => NumberFormat::FORMAT_NUMBER, // Shipped
            'H' => NumberFormat::FORMAT_NUMBER, // Outs. Ship
            'I' => NumberFormat::FORMAT_NUMBER, // WHFG
            'J' => NumberFormat::FORMAT_NUMBER, // Packing
            'K' => NumberFormat::FORMAT_TEXT,   // Req. Deliv. Date
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();

                $remarkCol = $this->remarkColLetter();

                // wrap & top align untuk kolom Remark
                $sheet->getStyle("{$remarkCol}2:{$remarkCol}{$highestRow}")
                    ->getAlignment()
                    ->setWrapText(true)
                    ->setVertical(Alignment::VERTICAL_TOP);

                // lebar kolom Remark yang nyaman
                $sheet->getColumnDimension($remarkCol)->setWidth(60);

                // outstanding: pusatkan tanggal kolom K
                if (!$this->isComplete()) {
                    $sheet->getStyle("K2:K{$highestRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }
            },
        ];
    }
}
