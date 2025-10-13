<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Outstanding PO Detail - {{ $locationName }} ({{ $auart }})</title>
    <style>
        /* ====== BASE ====== */
        @page {
            margin: 18px 18px 28px 18px;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9px;
            color: #333;
            margin: 0;
            padding: 0;
        }

        /* ====== HEADER / FOOTER ====== */
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
            font-size: 11px;
        }

        .footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            text-align: right;
            font-size: 8px;
            padding-top: 3px;
            border-top: 1px solid #ccc;
        }

        /* ====== TABLE ====== */
        .group-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }

        /* header group (customer + thead kolom) harus jadi thead agar berulang */
        .customer-header-group {
            display: table-header-group;
            page-break-inside: avoid;
            page-break-after: avoid;
        }

        /* Baris judul customer */
        .customer-header-row td {
            background: #e0e0e0;
            font-weight: bold;
            font-size: 10px;
            padding: 5px 6px;
            border-top: 2px solid #555;
            border-bottom: 1px solid #555;
        }

        /* Header kolom item */
        .item-thead-row th {
            background: #f2f2f2;
            border: 1px solid #ddd;
            border-top: none;
            padding: 5px 3px;
            font-size: 9px;
            text-align: center;
            word-wrap: break-word;
        }

        /* Baris data */
        .item-row td {
            border: 1px solid #ddd;
            padding: 3px 3px;
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

        .remark-cell {
            white-space: pre-wrap;
            word-break: break-word;
        }
    </style>
</head>

<body>

    @php
        // ===== Helpers =====
        $items = $items ?? collect();
        $currency = $items->first()->WAERK ?? 'IDR';

        $formatNumber = function ($n, $d = 0) {
            $n = (float) $n;
            return number_format($n, $d, ',', '.');
        };
        $formatMoney = function ($value, $currency, $d = 2) {
            $n = (float) $value;
            $fmt = function ($v, $d) use ($currency) {
                if ($currency === 'IDR') {
                    return number_format($v, $d, ',', '.');
                }
                if ($currency === 'USD') {
                    return number_format($v, $d, '.', ',');
                }
                return number_format($v, $d, ',', '.');
            };
            if ($currency === 'IDR') {
                return 'Rp ' . $fmt($n, $d);
            }
            if ($currency === 'USD') {
                return '$' . $fmt($n, $d);
            }
            return trim(($currency ?: '') . ' ' . $fmt($n, $d));
        };

        // Grouping per customer
        $grouped = $items->groupBy('CUSTOMER'); // field dari controller: 't2.NAME1 as CUSTOMER'
    @endphp

    <div class="header">
        <h1>OUTSTANDING PO DETAIL {{ $locationName }} - {{ $auartDescription }}</h1>
        <div class="date">{{ \Carbon\Carbon::parse($today ?? now())->format('d M Y') }}</div>
    </div>

    @forelse ($grouped as $customer => $rows)
        <table class="group-table">
            {{-- THEAD berulang di setiap page --}}
            <thead class="customer-header-group">
                <tr class="customer-header-row">
                    <td colspan="13">Customer: {{ $customer }}</td>
                </tr>
                <tr class="item-thead-row">
                    <th style="width:3%;">No.</th>
                    <th style="width:9%;">PO</th>
                    <th style="width:9%;">SO</th>
                    <th style="width:4%;">Item</th>
                    <th style="width:10%;">Material FG</th>
                    <th class="text-left" style="width:28%;">Desc FG</th>
                    <th style="width:6%;">Qty PO</th>
                    <th style="width:6%;">Shipped</th>
                    <th style="width:7%;">Outs. Ship</th>
                    <th style="width:6%;">WHFG</th>
                    <th style="width:6%;">FG</th>
                    <th style="width:10%;">Net Price</th>
                    <th style="width:16%;">Remark</th>
                </tr>
            </thead>

            <tbody>
                @php $i = 0; @endphp
                @foreach ($rows->sortBy(['SO', 'POSNR']) as $r)
                    @php
                        $i++;
                        // Semua kolom sudah disiapkan di controller:
                        // PO: $r->PO, SO: $r->SO, POSNR, MATNR, MAKTX, KWMENG (Qty PO),
                        // QTY_GI (Shipped), QTY_BALANCE2 (Outs. Ship), KALAB (WHFG), KALAB2 (FG), NETPR (Net Price), WAERK
                    @endphp
                    <tr class="item-row">
                        <td class="text-center">{{ $i }}</td>
                        <td>{{ $r->PO }}</td>
                        <td>{{ $r->SO }}</td>
                        <td class="text-center">{{ (int) $r->POSNR }}</td>
                        <td>{{ $r->MATNR }}</td>
                        <td class="text-left">{{ $r->MAKTX }}</td>
                        <td class="text-right">{{ $formatNumber($r->KWMENG) }}</td>
                        <td class="text-right">{{ $formatNumber($r->QTY_GI) }}</td>
                        <td class="text-right">{{ $formatNumber($r->QTY_BALANCE2) }}</td>
                        <td class="text-right">{{ $formatNumber($r->KALAB) }}</td>
                        <td class="text-right">{{ $formatNumber($r->KALAB2) }}</td>
                        <td class="text-right">{{ $formatMoney($r->NETPR, $r->WAERK ?? $currency) }}</td>
                        <td class="remark-cell">{{ $r->REMARK }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <table class="group-table">
            <tbody>
                <tr>
                    <td class="item-row text-center">Tidak ada item yang dipilih untuk diekspor.</td>
                </tr>
            </tbody>
        </table>
    @endforelse

</body>

</html>
