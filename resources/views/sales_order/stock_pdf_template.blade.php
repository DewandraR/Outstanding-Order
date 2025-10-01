<!DOCTYPE html>
<html>

<head>
    <title>Stock Report - {{ $locationName }} ({{ $stockType }})</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 9px;
            margin: 0;
            padding: 0;
            color: #333;
        }

        /* --- HEADER & FOOTER --- */
        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 14px;
            margin-bottom: 5px;
            color: #1a1a1a;
        }

        .header .date {
            font-size: 12px;
            margin-top: 5px;
            font-style: normal;
        }

        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: right;
            font-size: 8px;
            padding-top: 5px;
            border-top: 1px solid #ccc;
        }

        /* --- TABLE STYLING --- */
        /* Catatan: Kita tidak bisa menggunakan satu <table> root karena grouping THEAD harus memisah */
        .group-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            /* Spasi antar grup customer */
        }

        /* Grup Gabungan Customer + Item Header: WAJIB THEAD agar berulang */
        .customer-header-group {
            display: table-header-group;
            page-break-inside: avoid;
            /* Pastikan header ini tidak terpotong dari item pertamanya */
            page-break-after: avoid;
        }

        /* Baris Customer Header */
        .customer-header-row td {
            background-color: #e0e0e0;
            font-weight: bold;
            font-size: 10px;
            padding: 4px 6px;
            border-top: 2px solid #555;
            border-bottom: 1px solid #555;
        }

        /* Header Kolom Item */
        .item-thead-row th {
            background-color: #f2f2f2;
            font-size: 9px;
            padding: 5px 6px;
            text-align: center;
            border: 1px solid #ddd;
            border-top: none;
        }

        /* Baris Data Item */
        .item-row td {
            padding: 3px 6px;
            border: 1px solid #ddd;
            vertical-align: middle;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }
    </style>
</head>

<body>

    @php
        // Helpers dan Variabel Lokal
        $stockColumn = $stockType === 'WHFG' ? 'KALAB' : 'KALAB2';
        $currency = $items->first()->WAERK ?? 'IDR';

        $stockDisplay = $stockType === 'WHFG' ? 'WHFG' : 'Stock Packing';

        $formatNumber = function ($n, $d = 0) {
            return number_format((float) $n, $d, ',', '.');
        };

        $formatMoney = function ($value, $currency, $d = 2) {
            $n = (float) $value;
            if ($currency === 'IDR') {
                return 'Rp ' . $formatNumber($n, $d);
            }
            if ($currency === 'USD') {
                return '$' . number_format($n, $d, '.', ',');
            }
            return trim(($currency ?: '') . ' ' . $formatNumber($n, $d));
        };

        // Memulai loop untuk membuat header berulang per Customer
        $itemsGrouped = $items->groupBy('NAME1');
    @endphp

    <div class="header">
        <h1>LAPORAN STOK DETAIL {{ $locationName }} - {{ $stockDisplay }}</h1>
        <p class="date">{{ $today->format('d M Y') }}</p>
    </div>

    @forelse ($itemsGrouped as $customerName => $groupItems)
        <table class="group-table">
            @php
                $customerItemIndex = 0; // Reset index untuk setiap customer
            @endphp

            {{-- THEAD: Blok yang akan berulang di setiap halaman --}}
            <thead class="customer-header-group">
                {{-- 1. Baris Customer Header (akan berulang) --}}
                <tr class="customer-header-row">
                    <td colspan="9">
                        Customer: {{ $customerName }}
                    </td>
                </tr>

                {{-- 2. Header Kolom Item (akan berulang) --}}
                <tr class="item-thead-row">
                    <th style="width: 3%;">No.</th>
                    <th style="width: 10%;">PO</th>
                    <th style="width: 7%;">SO</th>
                    <th style="width: 5%;">Item</th>
                    <th style="width: 10%;">Material FG</th>
                    <th style="width: 35%;">Deskripsi FG</th>
                    <th style="width: 7%;">Qty SO</th>
                    <th style="width: 8%;">{{ $stockDisplay }}</th>
                    <th style="width: 15%;">Net Price ({{ $currency }})</th>
                </tr>
            </thead>

            {{-- TBODY: Konten item untuk customer ini --}}
            <tbody>
                @foreach ($groupItems as $item)
                    @php
                        $customerItemIndex++;
                        $poNumber = $item->BSTNK ?? '-';
                    @endphp

                    <tr class="item-row">
                        <td class="text-center">{{ $customerItemIndex }}</td>
                        <td>{{ $poNumber }}</td>
                        <td>{{ $item->VBELN }}</td>
                        <td class="text-center">{{ $item->POSNR }}</td>
                        <td>{{ $item->MATNR }}</td>
                        <td>{{ $item->MAKTX }}</td>
                        <td class="text-right">{{ $formatNumber($item->KWMENG) }}</td>
                        <td class="text-right">{{ $formatNumber($item->$stockColumn) }}</td>
                        <td class="text-right">{{ $formatMoney($item->NETPR, $currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <table class="group-table">
            <tbody>
                <tr>
                    <td colspan="9" class="text-center item-row">Tidak ada item yang dipilih untuk diekspor.</td>
                </tr>
            </tbody>
        </table>
    @endforelse

</body>

</html>
