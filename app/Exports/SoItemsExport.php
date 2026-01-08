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

class SoItemsExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithStrictNullComparison,
    WithColumnFormatting
{
    protected Collection $items;
    protected array $customerRows = [];  // baris judul "Customer: ..."
    protected string $mode;             // wood|metal

    public function __construct(Collection $items, string $mode = 'wood')
    {
        $this->items = $items;
        $mode = strtolower(trim($mode));
        $this->mode = in_array($mode, ['wood','metal'], true) ? $mode : 'wood';
    }

    protected function isMetal(): bool
    {
        return $this->mode === 'metal';
    }

    /**
     * Last column letter depends on mode:
     * wood  = 14 columns (A..N)
     * metal = 15 columns (A..O) (add PRIMER GR)
     */
    protected function lastColumnLetter(): string
    {
        return $this->isMetal() ? 'O' : 'N';
    }

    protected function totalColumns(): int
    {
        return $this->isMetal() ? 15 : 14;
    }

    public function collection()
    {
        $currentRow = 2; // row 1 = headings
        $final = new Collection();

        $groups = $this->items->groupBy(function ($item) {
            $name = preg_replace('/\s+/', ' ', trim($item->headerInfo->NAME1 ?? ''));
            return $name === '' ? '(Unknown Customer)' : $name;
        });

        foreach ($groups as $customerName => $rows) {
            // customer header row
            $customerHeader = (object)[
                'is_customer_header' => true,
                'customer_name'      => 'Customer: ' . $customerName,
            ];
            $final->push($customerHeader);
            $this->customerRows[] = $currentRow;
            $currentRow++;

            foreach ($rows as $item) {
                $item->is_customer_header = false;
                $final->push($item);
                $currentRow++;
            }
        }

        return $final;
    }

    public function headings(): array
    {
        // Base columns always same
        $base = [
            'PO',            // A
            'SO',            // B
            'Item',          // C
            'Material FG',   // D
            'Description',   // E
            'Qty SO',        // F
            'Outs. SO',      // G
            'WHFG',          // H
            'Stock Packg.',  // I
        ];

        if ($this->isMetal()) {
            // add 5 process columns (CUTTING, ASSY, PRIMER, PAINT, PACKING) + Remark
            return array_merge($base, [
                'CUTTING GR',  // J
                'ASSY GR',     // K
                'PRIMER GR',   // L
                'PAINT GR',    // M
                'PACKING GR',  // N
                'Remark',      // O
            ]);
        }

        // wood: 4 process columns + Remark
        return array_merge($base, [
            'MACHI GR',    // J
            'ASSY GR',     // K
            'PAINT GR',    // L
            'PACKING GR',  // M
            'Remark',      // N
        ]);
    }

    public function map($item): array
    {
        // customer header row: fill only first cell, rest empty (must match total columns)
        if (property_exists($item, 'is_customer_header') && $item->is_customer_header) {
            $cols = $this->totalColumns();
            $row = array_fill(0, $cols, '');
            $row[0] = $item->customer_name ?? '';
            return $row;
        }

        $po   = $item->headerInfo->BSTNK ?? '';
        $so   = $item->VBELN ?? '';
        $pos  = (int)($item->POSNR ?? 0);
        $mat  = $item->MATNR ?? '';
        $desc = $item->MAKTX ?? '';

        $qtySo   = (float)($item->KWMENG ?? 0);
        $outsSo  = (float)($item->PACKG  ?? 0);
        $whfg    = (float)($item->KALAB  ?? 0);
        $stockPk = (float)($item->KALAB2 ?? 0);

        $remark = $item->remark ?? '';

        if ($this->isMetal()) {
            // METAL
            $cut    = (float)($item->CUTT    ?? 0);
            $assy   = (float)($item->ASSYMT  ?? 0);
            $primer = (float)($item->PRIMER  ?? 0);
            $paint  = (float)($item->PAINTMT ?? 0);
            $pack   = (float)($item->PRSIMT  ?? 0);

            return [
                $po, $so, $pos, $mat, $desc,
                $qtySo, $outsSo, $whfg, $stockPk,
                $cut, $assy, $primer, $paint, $pack,
                $remark,
            ];
        }

        // WOOD
        $machi = (float)($item->MACHI  ?? 0);
        $assy  = (float)($item->ASSYM  ?? 0);
        $paint = (float)($item->PAINTM ?? 0);
        $pack  = (float)($item->PACKGM ?? 0);

        return [
            $po, $so, $pos, $mat, $desc,
            $qtySo, $outsSo, $whfg, $stockPk,
            $machi, $assy, $paint, $pack,
            $remark,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $lastCol = $this->lastColumnLetter();

        // Heading row style
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'F2F2F2']],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '999999']],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
        ]);

        // Merge + style customer header rows
        foreach ($this->customerRows as $row) {
            $sheet->mergeCells("A{$row}:{$lastCol}{$row}");

            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'E9ECEF']],
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '333333']],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                ],
            ]);
        }

        return [];
    }

    public function columnFormats(): array
    {
        $intNoDecimal = '#,##0';

        // Base fixed columns
        $formats = [
            'F' => $intNoDecimal, // Qty SO
            'G' => $intNoDecimal, // Outs SO
            'H' => $intNoDecimal, // WHFG
            'I' => $intNoDecimal, // Stock Packg
        ];

        if ($this->isMetal()) {
            // METAL: process columns J..N
            $formats['J'] = $intNoDecimal; // CUTTING
            $formats['K'] = $intNoDecimal; // ASSY
            $formats['L'] = $intNoDecimal; // PRIMER
            $formats['M'] = $intNoDecimal; // PAINT
            $formats['N'] = $intNoDecimal; // PACKING
            // Remark is O (text) -> no format needed
            return $formats;
        }

        // WOOD: process columns J..M
        $formats['J'] = $intNoDecimal; // MACHI
        $formats['K'] = $intNoDecimal; // ASSY
        $formats['L'] = $intNoDecimal; // PAINT
        $formats['M'] = $intNoDecimal; // PACKING
        // Remark is N (text)
        return $formats;
    }
}
