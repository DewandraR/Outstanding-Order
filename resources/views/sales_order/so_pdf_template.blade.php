<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Outstanding SO Items</title>
    <style>
        /* composer require barryvdh/laravel-dompdf */
        body {
            font-family: 'Helvetica', sans-serif;
            font-size: 9px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            table-layout: fixed;
        }

        .table th,
        .table td {
            border: 1px solid #333;
            padding: 5px;
            text-align: center;
            vertical-align: middle;
            word-wrap: break-word;
        }

        .table th {
            background-color: #f0f0f0;
            font-weight: bold;
        }

        .text-left {
            text-align: left;
        }

        .v-middle {
            vertical-align: middle;
        }

        .header-title {
            text-align: center;
            margin-bottom: 2px;
            text-transform: uppercase;
            font-size: 14px;
        }

        .header-subtitle {
            text-align: center;
            margin-top: 0;
            font-size: 11px;
        }

        /* Header tabel muncul tiap halaman & baris tidak terbelah */
        thead {
            display: table-header-group;
        }

        tr {
            page-break-inside: avoid;
        }

        /* Judul customer lebih besar & tebal */
        .customer-title {
            background: #e9ecef;
            font-weight: 700;
            font-size: 12px;
            /* > dari font body 9px */
            text-align: left;
            padding: 8px 6px;
        }
    </style>
</head>

<body>
    <h2 class="header-title">Outstanding SO {{ $locationName }} - {{ $auartDescription }}</h2>
    <p class="header-subtitle">{{ strtoupper(date('d F Y')) }}</p>

    @php
        $groups = collect($items)->groupBy(function ($it) {
            $name = preg_replace('/\s+/', ' ', trim($it->headerInfo->NAME1 ?? ''));
            return $name === '' ? '(Unknown Customer)' : $name;
        });
    @endphp

    @foreach ($groups as $customerName => $rows)
        <table class="table" style="margin-top:16px;">
            <thead style="display: table-header-group;"> {{-- diulang setiap halaman --}}
                <tr>
                    {{-- SEBELUMNYA 14, SEKARANG HANYA 13 KOLOM! --}}
                    <th colspan="13" class="customer-title">
                        Customer: {{ $customerName }}
                    </th>
                </tr>
                <tr>
                    {{-- PERUBAHAN LEBAR KOLOM UNTUK MEMBERI RUANG PADA REMARK --}}
                    <th style="width:7%;">PO</th>
                    <th style="width:6%;">SO</th>
                    <th style="width:3%;">Item</th>
                    <th style="width:8%;">Material FG</th>
                    <th class="text-left" style="width:19%;">Desc FG</th>
                    <th style="width:4%;">Qty SO</th>
                    <th style="width:4%;">Outs. SO</th>
                    <th style="width:4%;">WHFG</th>
                    <th style="width:4%;">Stock Packg.</th>
                    <th style="width:4%;">GR ASSY</th>
                    <th style="width:4%;">GR PAINT</th>
                    <th style="width:4%;">GR PKG</th>
                    <th class="text-left" style="width:20%;">Remark</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $item)
                    <tr>
                        {{-- Nomor reset per-customer --}}
                        <td>{{ $item->headerInfo->BSTNK ?? '' }}</td>
                        <td>{{ $item->VBELN }}</td>
                        <td>{{ (int) $item->POSNR }}</td>
                        <td>{{ $item->MATNR }}</td>
                        <td class="text-left">{{ $item->MAKTX }}</td>
                        <td>{{ number_format((float) $item->KWMENG, 0) }}</td>
                        <td>{{ number_format((float) ($item->PACKG ?? 0), 0) }}</td>
                        <td>{{ number_format((float) ($item->KALAB ?? 0), 0) }}</td>
                        <td>{{ number_format((float) ($item->KALAB2 ?? 0), 0) }}</td>
                        <td>{{ number_format((float) ($item->ASSYM ?? 0), 0) }}</td>
                        <td>{{ number_format((float) ($item->PAINT ?? 0), 0) }}</td>
                        <td>{{ number_format((float) ($item->MENGE ?? 0), 0) }}</td>
                        <td class="text-left">{{ $item->remark }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
</body>

</html>
