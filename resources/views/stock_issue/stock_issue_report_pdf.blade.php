<!DOCTYPE html>
<html>
<head>
    <title>{{ $title }} - {{ $werks ?? 'N/A' }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 9px; margin: 0; padding: 0; color: #333; }

        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { font-size: 14px; margin-bottom: 5px; color: #1a1a1a; }
        .header .date { font-size: 12px; margin-top: 5px; font-style: normal; }

        .footer {
            position: fixed; bottom: 0; left: 0; right: 0;
            text-align: right; font-size: 8px; padding-top: 5px;
            border-top: 1px solid #ccc;
        }

        .group-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }

        .customer-header-group { display: table-header-group; page-break-inside: avoid; page-break-after: avoid; }

        .customer-header-row td {
            background-color: #e0e0e0;
            font-weight: bold;
            font-size: 10px;
            padding: 4px 6px;
            border-top: 2px solid #555;
            border-bottom: 1px solid #555;
        }

        .item-thead-row th {
            background-color: #f2f2f2;
            font-size: 9px;
            padding: 5px 3px;
            text-align: center;
            border: 1px solid #ddd;
            border-top: none;
            word-wrap: break-word;
        }

        .item-row td {
            padding: 3px 3px;
            border: 1px solid #ddd;
            vertical-align: middle;
            word-wrap: break-word;
        }

        .text-left { text-align: left; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
    </style>
</head>

<body>
@php
    $formatNumber = fn($n, $d = 0) => number_format((float)$n, $d, ',', '.');
    $formatMoney  = fn($n) => '$' . number_format((float)$n, 0, '.', ',');
    $formatUom    = fn($u) => strtoupper(trim((string)$u)) === 'ST' ? 'PC' : $u;

    $itemsGrouped = collect($stockData)->groupBy('NAME1');
@endphp

<div class="header">
    <h1>{{ strtoupper($title) }}</h1>
    <p class="date">{{ now()->format('d M Y') }}</p>
</div>

@forelse ($itemsGrouped as $customerName => $groupRows)
    <table class="group-table">
        @php
            $idx = 0;
            $currentCustomer = $customerName ?: '-';
        @endphp

        <thead class="customer-header-group">
            <tr class="customer-header-row">
                {{-- total kolom = 8 --}}
                <td colspan="8">
                    Customer: {{ $currentCustomer }}
                </td>
            </tr>

            <tr class="item-thead-row">
                <th style="width: 4%;">No.</th>
                <th style="width: 10%;">Sales Order</th>
                <th style="width: 6%;">Item</th>
                <th style="width: 14%;">Material Finish</th>
                <th class="text-left" style="width: 32%;">Description</th>
                <th style="width: 10%;">Stock On Hand</th>
                <th style="width: 6%;">UoM</th>
                <th style="width: 18%;">Total Value</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($groupRows as $item)
                @php $idx++; @endphp
                <tr class="item-row">
                    <td class="text-center">{{ $idx }}</td>
                    <td class="text-center">{{ $item->VBELN ?? '-' }}</td>
                    <td class="text-center">{{ $item->POSNR ?? '-' }}</td>
                    <td class="text-center">{{ $item->MATNH ?? '-' }}</td>
                    <td class="text-left">{{ $item->MAKTXH ?? '-' }}</td>
                    <td class="text-right">{{ $formatNumber($item->STOCK3 ?? 0) }}</td>
                    <td class="text-center">{{ $formatUom($item->MEINS ?? '') }}</td>
                    <td class="text-right">{{ $formatMoney($item->TPRC ?? 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@empty
    <table class="group-table">
        <tbody>
            <tr>
                <td colspan="8" class="text-center item-row">Tidak ada data untuk diekspor.</td>
            </tr>
        </tbody>
    </table>
@endforelse

<div class="footer">
    Dicetak: {{ now()->format('d-m-Y H:i') }}
</div>
</body>
</html>
