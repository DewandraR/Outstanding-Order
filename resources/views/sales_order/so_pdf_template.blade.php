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
            text-align: center;
            border: 1px solid #ddd;
            border-top: none;
            word-wrap: break-word;
        }

        /* Baris Data Item */
        .item-row td {
            padding: 3px 3px;
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

        /* Supaya remark muat */
        .remark-cell {
            white-space: pre-line;
        }
    </style>
</head>

<body>

    @php
        // Jika kosong, set default seperlunya
        $locationName = $locationName ?? 'N/A';
        $auartDescription = $auartDescription ?? ($auart ?? 'N/A');

        // Helpers
        $formatNumber = function ($n, $d = 0) {
            return number_format((float) $n, $d, ',', '.');
        };
        $formatPct = function ($v) {
            $n = is_null($v) ? 0 : (float) $v;
            // amankan agar 0..100
            if ($n < 0) {
                $n = 0;
            }
            if ($n > 100) {
                $n = 100;
            }
            return number_format($n, 0, ',', '.') . '%';
        };

        // Grouping per customer (headerInfo dibuat di controller)
        $itemsGrouped = collect($items)->groupBy('headerInfo.NAME1');
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

            {{-- THEAD: Berulang per halaman --}}
            <thead class="customer-header-group">
                {{-- 1) Judul Customer --}}
                <tr class="customer-header-row">
                    {{-- ⬇️ Total kolom sekarang 15, jadi colspan=15 --}}
                    <td colspan="15">
                        Customer: {{ $currentCustomer }}
                    </td>
                </tr>

                {{-- 2) Header Kolom Item --}}
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

                    {{-- ⬇️ Kolom persentase proses (baru) --}}
                    <th style="width: 4%;">MACHI&nbsp;%</th>
                    <th style="width: 4%;">ASSY&nbsp;%</th>
                    <th style="width: 4%;">PAINT&nbsp;%</th>
                    <th style="width: 4%;">PACKING&nbsp;%</th>

                    <th class="text-left" style="width:12%;">Remark</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($groupRows as $item)
                    @php
                        $customerItemIndex++;

                        $poNumber = $item->headerInfo->BSTNK ?? '-';

                        $qtySo = (float) ($item->KWMENG ?? 0);
                        $outsSo = (float) ($item->PACKG ?? 0);
                        $whfg = (float) ($item->KALAB ?? 0);
                        $stockPk = (float) ($item->KALAB2 ?? 0);

                        // Persentase proses (0..100)
                        $pMach = $item->PRSM ?? 0; // MACHI %
                        $pAssy = $item->PRSA ?? 0; // ASSY  %
                        $pPaint = $item->PRSI ?? 0; // PAINT %
                        $pPack = $item->PRSP ?? 0; // PACKING %

                        // Field dasar
                        $vbeln = $item->VBELN ?? '-';
                        $posnr = (int) ($item->POSNR ?? 0);
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

                        <td class="text-right">{{ $formatNumber($qtySo) }}</td>
                        <td class="text-right">{{ $formatNumber($outsSo) }}</td>
                        <td class="text-right">{{ $formatNumber($whfg) }}</td>
                        <td class="text-right">{{ $formatNumber($stockPk) }}</td>

                        <td class="text-center">{{ $formatPct($pMach) }}</td>
                        <td class="text-center">{{ $formatPct($pAssy) }}</td>
                        <td class="text-center">{{ $formatPct($pPaint) }}</td>
                        <td class="text-center">{{ $formatPct($pPack) }}</td>

                        <td class="remark-cell">{!! nl2br(e($item->remark ?? '')) !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <table class="group-table">
            <tbody>
                {{-- ⬇️ Sesuaikan colspan 15 --}}
                <tr>
                    <td colspan="15" class="text-center item-row">Tidak ada item yang dipilih untuk diekspor.</td>
                </tr>
            </tbody>
        </table>
    @endforelse

</body>

</html>
