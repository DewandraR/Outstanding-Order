<!DOCTYPE html>
<html>

<head>
    <title>Outstanding SO Detail - {{ $locationName }} ({{ $auart }})</title>
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
        .group-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        /* Grup Gabungan Customer + Item Header: WAJIB THEAD agar berulang */
        .customer-header-group {
            display: table-header-group;
            page-break-inside: avoid;
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
            padding: 5px 3px;
            /* Kurangi padding horizontal untuk kolom banyak */
            text-align: center;
            border: 1px solid #ddd;
            border-top: none;
            /* Perataan khusus untuk deskripsi dan remark */
            word-wrap: break-word;
        }

        /* Baris Data Item */
        .item-row td {
            padding: 3px 3px;
            /* Kurangi padding horizontal untuk kolom banyak */
            border: 1px solid #ddd;
            vertical-align: middle;
            word-wrap: break-word;
        }

        .text-left {
            text-align: left;
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
        // FIX: Tambahkan pengecekan jika $items kosong
        if ($items->isEmpty()) {
            $currency = 'IDR';
            $locationName = $locationName ?? 'N/A';
            $auartDescription = $auartDescription ?? 'N/A';
        } else {
            $currency = $items->first()->WAERK ?? 'IDR';
        }

        // Helpers dan Variabel Lokal
        $formatNumber = function ($n, $d = 0) {
            return number_format((float) $n, $d, ',', '.');
        };
        $formatMoney = function ($value, $currency, $d = 2) {
            $n = (float) $value;
            $fn = function ($v, $d) use ($currency) {
                if ($currency === 'IDR') {
                    return number_format($v, $d, ',', '.');
                }
                if ($currency === 'USD') {
                    return number_format($v, $d, '.', ',');
                }
                return number_format($v, $d, ',', '.');
            };

            if ($currency === 'IDR') {
                return 'Rp ' . $fn($n, $d);
            }
            if ($currency === 'USD') {
                return '$' . $fn($n, $d);
            }
            return trim(($currency ?: '') . ' ' . $fn($n, $d));
        };

        // Memulai loop untuk membuat header berulang per Customer
        $itemsGrouped = collect($items)->groupBy('headerInfo.NAME1');
    @endphp

    <div class="header">
        <h1>OUTSTANDING SO DETAIL {{ $locationName }} - {{ $auartDescription }}</h1>
        <p class="date">{{ now()->format('d M Y') }}</p>
    </div>

    @forelse ($itemsGrouped as $customerName => $groupRows)
        <table class="group-table">
            @php
                $customerItemIndex = 0; // Reset index untuk setiap customer
                $currentCustomer = $customerName;
            @endphp

            {{-- THEAD: Blok yang akan berulang di setiap halaman --}}
            <thead class="customer-header-group">
                {{-- 1. Baris Customer Header (akan berulang) --}}
                <tr class="customer-header-row">
                    <td colspan="14">
                        Customer: {{ $currentCustomer }}
                    </td>
                </tr>

                {{-- 2. Header Kolom Item (akan berulang) --}}
                <tr class="item-thead-row">
                    <th style="width: 3%;">No.</th>
                    <th style="width: 7%;">PO</th>
                    <th style="width: 6%;">SO</th>
                    <th style="width: 3%;">Item</th>
                    <th style="width: 8%;">Material FG</th>
                    <th class="text-left" style="width:25%;">Desc FG</th>
                    <th style="width: 4%;">Qty SO</th>
                    <th style="width: 4%;">Outs. SO</th>
                    <th style="width: 4%;">WHFG</th>
                    <th style="width: 4%;">Stock Packg.</th>
                    <th style="width: 4%;">GR ASSY</th>
                    <th style="width: 4%;">GR PAINT</th>
                    <th style="width: 4%;">GR PKG</th>
                    <th class="text-left" style="width:14%;">Remark</th>
                </tr>
            </thead>

            {{-- TBODY: Konten item untuk customer ini --}}
            <tbody>
                @foreach ($groupRows as $item)
                    @php
                        $customerItemIndex++;
                        $poNumber = $item->headerInfo->BSTNK ?? '-';
                        // FIX: Menggunakan operator Null Coalescing (??) untuk menghindari error jika kolom tidak ada
                        $outsSo = (float) ($item->PACKG ?? 0);
                        $netPrice = (float) ($item->NETPR ?? 0); // FIX: Memastikan NETPR diset

                        // Kolom SO Report harus ada di query Controller Anda
                        $kalab = $item->KALAB ?? 0;
                        $kalab2 = $item->KALAB2 ?? 0;
                        $assym = $item->ASSYM ?? 0;
                        $paint = $item->PAINT ?? 0;
                        $menge = $item->MENGE ?? 0;
                    @endphp

                    <tr class="item-row">
                        <td class="text-center">{{ $customerItemIndex }}</td>
                        <td>{{ $poNumber }}</td>
                        <td>{{ $item->VBELN }}</td>
                        <td class="text-center">{{ (int) $item->POSNR }}</td>
                        <td>{{ $item->MATNR }}</td>
                        <td class="text-left">{{ $item->MAKTX }}</td>
                        <td class="text-right">{{ $formatNumber($item->KWMENG) }}</td>
                        <td class="text-right">{{ $formatNumber($outsSo) }}</td>
                        <td class="text-right">{{ $formatNumber($kalab) }}</td>
                        <td class="text-right">{{ $formatNumber($kalab2) }}</td>
                        <td class="text-right">{{ $formatNumber($assym) }}</td>
                        <td class="text-right">{{ $formatNumber($paint) }}</td>
                        <td class="text-right">{{ $formatNumber($menge) }}</td>
                        <td class="remark-cell">{!! nl2br(e($item->remark ?? '')) !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <table class="group-table">
            <tbody>
                <tr>
                    <td colspan="14" class="text-center item-row">Tidak ada item yang dipilih untuk diekspor.</td>
                </tr>
            </tbody>
        </table>
    @endforelse
</body>

</html>
