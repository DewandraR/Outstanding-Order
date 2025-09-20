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

        /* Ukuran font sedikit dikecilkan untuk lebih banyak ruang */
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            /* Memaksa tabel untuk mengikuti lebar kolom yang ditentukan */
            table-layout: fixed;
        }

        .table th,
        .table td {
            border: 1px solid #333;
            padding: 5px;
            /* Padding sedikit dikurangi */
            text-align: center;
            vertical-align: middle;
            /* Aturan ini akan memecah teks yang sangat panjang */
            word-wrap: break-word;
        }

        .table th {
            background-color: #f0f0f0;
            font-weight: bold;
        }

        .text-left {
            text-align: left;
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
    </style>
</head>

<body>
    <h2 class="header-title">Outstanding SO {{ $locationName }} ({{ $werks }}) - {{ $auartDescription }}</h2>
    <p class="header-subtitle">{{ strtoupper(date('d F Y')) }}</p>

    <table class="table">
        <thead style="font-size: 9px;">
            <tr>
                <th style="width: 14%;">Customer</th>
                <th style="width: 8%;">PO</th>
                <th style="width: 6%;">SO</th>
                <th style="width: 4%;">Item</th>
                <th style="width: 10%;">Material FG</th>
                <th class="text-left" style="width: 21%;">Desc FG</th>
                <th style="width: 5%;">Qty SO</th>
                <th style="width: 5%;">Outs. SO</th>
                <th style="width: 5%;">WHFG</th>
                <th style="width: 6%;">Stock Packg.</th>
                <th class="text-left" style="width: 16%;">Remark</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
                <tr>
                    <td class="text-left">{{ $item->headerInfo->NAME1 ?? '' }}</td>
                    <td>{{ $item->headerInfo->BSTNK ?? '' }}</td>
                    <td>{{ $item->VBELN }}</td>
                    <td>{{ (int) $item->POSNR }}</td>
                    <td>{{ $item->MATNR }}</td>
                    <td class="text-left">{{ $item->MAKTX }}</td>
                    <td>{{ number_format($item->KWMENG, 0) }}</td>
                    <td>{{ number_format($item->PACKG, 0) }}</td>
                    <td>{{ number_format($item->KALAB ?? 0, 0) }}</td>
                    <td>{{ number_format($item->KALAB2 ?? 0, 0) }}</td>
                    <td class="text-left">{{ $item->remark }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="11">Tidak ada item yang dipilih untuk diekspor.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>

</html>
