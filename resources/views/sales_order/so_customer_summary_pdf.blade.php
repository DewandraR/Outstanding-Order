<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Overview Customer</title>
    <style>
        /* DomPDF friendly CSS */
        @page { margin: 24px 24px 28px 24px; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 11px; color:#0f172a; }

        .title {
            font-size: 18px; font-weight: 700; text-transform: none;
            background: #e9fbf2; padding: 10px 14px; border-radius: 8px; margin-bottom: 12px;
        }
        .subtitle { font-size: 11px; margin: 2px 0 12px 0; color:#334155; }

        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead th {
            background: #e7faf1; color:#0f5132; font-size: 11px; letter-spacing:.4px;
            padding: 8px 10px; border-bottom: 2px solid #7cc8a3; text-align: center;
        }
        thead th.th-customer { text-align: left; }
        tbody td {
            padding: 10px; vertical-align: middle; border-bottom: 1px solid #e5e7eb; text-align: center;
        }
        tbody td.td-customer { text-align: left; font-weight: 700; }
        tfoot th, tfoot td {
            padding: 10px; background: #f3faf6; border-top: 2px solid #cfe9dd;
            font-weight: 700;
        }
        .text-right { text-align: right; }
        .text-left  { text-align: left; }
        .text-center{ text-align: center; }

        /* Rounded row feel (simulasi chip/rounded list) */
        tbody tr { border-left: 1px solid #f1f5f9; border-right: 1px solid #f1f5f9; }
        tbody tr:first-child td { border-top: 1px solid #f1f5f9; }
    </style>
</head>
<body>
    <div class="title">Overview Customer — {{ $locationName }} ({{ $werks }}) — {{ $auartDescription }}</div>
    <div class="subtitle">{{ $today->format('d F Y') }}</div>

    <table>
        <thead>
            <tr>
                <th class="th-customer" style="width:48%;">CUSTOMER</th>
                <th style="width:14%;">OVERDUE SO</th>
                <th style="width:18%;">OVERDUE RATE</th>
                <th style="width:20%;">VALUE</th>
            </tr>
        </thead>
        <tbody>
            @php
                $fmt = function($cur, $val) {
                    if ($cur === 'IDR') return 'Rp ' . number_format($val, 2, ',', '.');
                    if ($cur === 'USD') return '$'  . number_format($val, 2, '.', ',');
                    return ($cur ?? '') . ' ' . number_format($val, 2, ',', '.');
                };
            @endphp

            @forelse($rows as $r)
                <tr>
                    <td class="td-customer">{{ $r->NAME1 }}</td>
                    <td>{{ (int)$r->SO_LATE_COUNT }}</td>
                    <td>{{ is_null($r->LATE_PCT) ? '—' : number_format($r->LATE_PCT, 2) . '%' }}</td>
                    <td class="text-right">{{ $fmt($r->WAERK, $r->TOTAL_VALUE) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3" class="text-right">Total</th>
                <th class="text-right">
                    @php
                        if($totals && count($totals)){
                            $parts = [];
                            foreach ($totals as $cur => $sum) { $parts[] = $fmt($cur, $sum); }
                            echo implode(' | ', $parts);
                        } else {
                            echo '—';
                        }
                    @endphp
                </th>
            </tr>
        </tfoot>
    </table>
</body>
</html>
