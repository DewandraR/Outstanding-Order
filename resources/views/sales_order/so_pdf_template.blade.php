<!DOCTYPE html>
<html>
<head>
    <title>Outstanding SO Detail - {{ $locationName }} ({{ $auart }})</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 9px; margin:0; padding:0; color:#333; }

        .header { text-align:center; margin-bottom:20px; }
        .header h1 { font-size:14px; margin-bottom:5px; color:#1a1a1a; }
        .header .date { font-size:12px; margin-top:5px; font-style:normal; }

        .footer {
            position: fixed; bottom:0; left:0; right:0;
            text-align:right; font-size:8px; padding-top:5px; border-top:1px solid #ccc;
        }

        .group-table { width:100%; border-collapse:collapse; margin-bottom:15px; }

        .customer-header-group { display: table-header-group; page-break-inside: avoid; page-break-after: avoid; }

        .customer-header-row td {
            background-color:#e0e0e0;
            font-weight:bold;
            font-size:10px;
            padding:4px 6px;
            border-top:2px solid #555;
            border-bottom:1px solid #555;
        }

        .item-thead-row th {
            background-color:#f2f2f2;
            font-size:9px;
            padding:5px 3px;
            text-align:center;
            border:1px solid #ddd;
            border-top:none;
            word-wrap:break-word;
        }

        .item-row td {
            padding:3px 3px;
            border:1px solid #ddd;
            vertical-align:middle;
            word-wrap:break-word;
        }

        .text-left { text-align:left; }
        .text-center { text-align:center; }
        .text-right { text-align:right; }
        .remark-cell { text-align:left; }
    </style>
</head>

<body>
@php
    // Default safety
    $locationName     = $locationName ?? 'N/A';
    $auartDescription = $auartDescription ?? ($auart ?? 'N/A');

    // mode: wood|metal
    $mode    = strtolower((string)($mode ?? 'wood'));
    $isMetal = $mode === 'metal';

    // total kolom:
    // wood  = 15 (No..Remark)
    // metal = 16 (tambahan PRIMER)
    $colspan = $isMetal ? 16 : 15;

    // Helpers
    $formatNumber = function ($n, $d = 0) {
        return number_format((float)$n, $d, ',', '.');
    };

    // untuk qty proses (sesuai template Anda: integer)
    $formatQty = function ($n) {
        return number_format((float)$n, 0, ',', '.');
    };

    // Group per customer
    $itemsGrouped = collect($items ?? [])->groupBy('headerInfo.NAME1');
@endphp

<div class="header">
    <h1>OUTSTANDING SO DETAIL {{ $locationName }} - {{ $auartDescription }}</h1>
    <p class="date">{{ now()->format('d M Y') }}</p>
</div>

@forelse ($itemsGrouped as $customerName => $groupRows)
    <table class="group-table">
        @php
            $customerItemIndex = 0;
            $currentCustomer = $customerName ?: '-';
        @endphp

        <thead class="customer-header-group">
            <tr class="customer-header-row">
                <td colspan="{{ $colspan }}">
                    Customer: {{ $currentCustomer }}
                </td>
            </tr>

            <tr class="item-thead-row">
                <th style="width: 3%;">No.</th>
                <th style="width: 7%;">PO</th>
                <th style="width: 6%;">SO</th>
                <th style="width: 3%;">Item</th>
                <th style="width: 8%;">Material FG</th>
                <th class="text-left" style="width:22%;">Desc FG</th>
                <th style="width: 4%;">Qty SO</th>
                <th style="width: 4%;">Outs. SO</th>
                <th style="width: 4%;">WHFG</th>
                <th style="width: 5%;">Stock Packg.</th>

                @if($isMetal)
                    <th style="width: 4%;">CUTTING GR</th>
                    <th style="width: 4%;">ASSY GR</th>
                    <th style="width: 4%;">PRIMER GR</th>
                    <th style="width: 4%;">PAINT GR</th>
                    <th style="width: 4%;">PACKING GR</th>
                @else
                    <th style="width: 4%;">MACHI GR</th>
                    <th style="width: 4%;">ASSY GR</th>
                    <th style="width: 4%;">PAINT GR</th>
                    <th style="width: 4%;">PACKING GR</th>
                @endif

                <th class="text-left" style="width:12%;">Remark</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($groupRows as $item)
                @php
                    $customerItemIndex++;

                    $poNumber = $item->headerInfo->BSTNK ?? '-';

                    $qtySo   = (float)($item->KWMENG ?? 0);
                    $outsSo  = (float)($item->PACKG  ?? 0);
                    $whfg    = (float)($item->KALAB  ?? 0);
                    $stockPk = (float)($item->KALAB2 ?? 0);

                    // pilih field proses sesuai mode
                    if ($isMetal) {
                        $p1 = (float)($item->CUTT   ?? 0);    // CUTTING
                        $p2 = (float)($item->ASSYMT ?? 0);    // ASSY (metal)
                        $p3 = (float)($item->PRIMER ?? 0);    // PRIMER
                        $p4 = (float)($item->PAINTMT?? 0);    // PAINT (metal)
                        $p5 = (float)($item->PRSIMT ?? 0);    // PACKING (metal)
                    } else {
                        $p1 = (float)($item->MACHI  ?? 0);    // MACHI (wood)
                        $p2 = (float)($item->ASSYM  ?? 0);    // ASSY (wood)
                        $p3 = null;                            // tidak ada primer di wood
                        $p4 = (float)($item->PAINTM ?? 0);    // PAINT (wood)
                        $p5 = (float)($item->PACKGM ?? 0);    // PACKING (wood)
                    }

                    $vbeln = $item->VBELN ?? '-';
                    $posnr = (int)($item->POSNR ?? 0);
                    $matnr = $item->MATNR ?? '-';
                    $maktx = $item->MAKTX ?? '-';
                @endphp

                <tr class="item-row">
                    <td class="text-center">{{ $customerItemIndex }}</td>
                    <td>{{ $poNumber }}</td>
                    <td>{{ $vbeln }}</td>
                    <td class="text-center">{{ $posnr }}</td>
                    <td>{{ $matnr }}</td>
                    <td class="text-left">{{ $maktx }}</td>

                    <td class="text-center">{{ $formatNumber($qtySo) }}</td>
                    <td class="text-center">{{ $formatNumber($outsSo) }}</td>
                    <td class="text-center">{{ $formatNumber($whfg) }}</td>
                    <td class="text-center">{{ $formatNumber($stockPk) }}</td>

                    @if($isMetal)
                        <td class="text-center">{{ $formatQty($p1) }}</td>
                        <td class="text-center">{{ $formatQty($p2) }}</td>
                        <td class="text-center">{{ $formatQty($p3) }}</td>
                        <td class="text-center">{{ $formatQty($p4) }}</td>
                        <td class="text-center">{{ $formatQty($p5) }}</td>
                    @else
                        <td class="text-center">{{ $formatQty($p1) }}</td>
                        <td class="text-center">{{ $formatQty($p2) }}</td>
                        <td class="text-center">{{ $formatQty($p4) }}</td>
                        <td class="text-center">{{ $formatQty($p5) }}</td>
                    @endif

                    <td class="remark-cell">{!! nl2br(e($item->remark ?? '')) !!}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@empty
    <table class="group-table">
        <tbody>
            <tr>
                <td colspan="{{ $colspan }}" class="text-center item-row">Tidak ada item yang dipilih untuk diekspor.</td>
            </tr>
        </tbody>
    </table>
@endforelse

</body>
</html>
