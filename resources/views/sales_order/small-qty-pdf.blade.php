<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    {{-- Menggunakan format title yang lebih detail --}}
    <title>Small Qty (≤5) SO — {{ $customerName }} ({{ $locationName }})</title>
    <style>
        @page {
            margin: 18px 18px 28px 18px;
        }

        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 9px;
            /* Ukuran font lebih kecil agar muat banyak data, konsisten dengan PO PDF */
            color: #333;
            margin: 0;
        }

        .header {
            text-align: center;
            margin: 0 0 14px 0;
        }

        .header h1 {
            margin: 0 0 4px 0;
            font-size: 14px;
            color: #111;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .header .date {
            font-size: 12px;
            text-align: center;
        }

        .group-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }

        /* header group agar thead berulang di tiap halaman */
        .customer-header-group {
            display: table-header-group;
            page-break-inside: avoid;
            page-break-after: avoid;
        }

        .customer-header-row td {
            background: #e0e0e0;
            font-weight: bold;
            font-size: 10px;
            padding: 5px 6px;
            border-top: 2px solid #555;
            border-bottom: 1px solid #555;
        }

        .item-thead-row th {
            background: #f2f2f2;
            border: 1px solid #ddd;
            border-top: none;
            padding: 5px 3px;
            font-size: 9px;
            text-align: center;
            word-wrap: break-word;
        }

        .item-row td {
            border: 1px solid #ddd;
            padding: 3px 3px;
            vertical-align: middle;
            word-wrap: break-word;
        }

        .text-left {
            text-align: left
        }

        .text-center {
            text-align: center
        }

        .text-right {
            text-align: right
        }
    </style>
</head>

<body>
    @php
        $items = collect($items ?? []);
        // Helper untuk format menjadi INTEGER (0 desimal), konsisten dengan PO Report
        $fmtInt = fn($n) => number_format((float) $n, 0, ',', '.');
        $rows = $items->sortBy([['SO', 'asc'], ['POSNR', 'asc']])->values();

        // Asumsi data yang dikirim dari controller (SmallQtyController.php) adalah:
        // 'items', 'customerName', 'locationName', 'generatedAt'
        $meta = [
            'customerName' => $customerName,
            'locationName' => $locationName,
            'generatedAt' => $generatedAt,
        ];
    @endphp

    <div class="header">
        <h1>SMALL QUANTITY (≤5) OUTSTANDING ITEMS - SO</h1>
        {{-- Menggunakan format yang sama dengan PO PDF --}}
        <div class="date">{{ $meta['generatedAt'] ?? now()->format('d-m-Y') }}</div>
    </div>

    <table class="group-table">
        <thead class="customer-header-group">
            {{-- TAMPILKAN Customer & Location --}}
            <tr class="customer-header-row">
                <td colspan="6">Customer: {{ $meta['customerName'] }} — Location: {{ $meta['locationName'] }}</td>
            </tr>
            <tr class="item-thead-row">
                <th style="width:4%;">No.</th>
                <th style="width:15%;">SO</th>
                <th style="width:8%;">Item</th>
                <th class="text-left" style="width:43%;">Description</th>
                <th style="width:15%;">Qty SO</th>
                <th style="width:15%;">Outs. SO</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $i => $r)
                <tr class="item-row">
                    <td class="text-center">{{ $i + 1 }}</td>
                    <td class="text-center">{{ $r->SO }}</td>
                    <td class="text-center">{{ (int) $r->POSNR }}</td>
                    <td class="text-left">{{ $r->MAKTX }}</td>
                    {{-- Menggunakan $fmtInt untuk menghilangkan 3 desimal --}}
                    <td class="text-right">{{ $fmtInt($r->KWMENG ?? 0) }}</td>
                    <td class="text-right">
                        <strong>{{ $fmtInt($r->PACKG ?? 0) }}</strong>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="item-row text-center">Tidak ada data.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>

</html>
