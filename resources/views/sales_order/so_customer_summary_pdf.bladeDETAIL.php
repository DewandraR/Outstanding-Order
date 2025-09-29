/*Controller
public function exportCustomerSummary(Request $request)
    {
        if ($request->filled('q')) {
            try {
                $data = Crypt::decrypt($request->query('q'));
                $request->merge($data);
            } catch (DecryptException $e) {
                abort(404);
            }
        }
        $request->validate([
            'werks' => 'required|string',
            'auart' => 'required|string',
        ]);

        $werks = $request->query('werks');
        $auart = $request->query('auart');

        $locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locationName = $locationMap[$werks] ?? $werks;
        $auartDesc = DB::table('maping')->where('IV_WERKS', $werks)->where('IV_AUART', $auart)->value('Deskription');

        $safeEdatu = "COALESCE(
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(NULLIF(LEFT(CAST(t2.EDATU AS CHAR),10),'00-00-0000'),'0000-00-00'), '%d-%m-%Y')
    )";

        // 1. Ambil data customer (Level-1) yang memiliki item outstanding
        $customers = DB::table('so_yppr079_t2 as t2')
            ->where('t2.IV_WERKS_PARAM', $werks)
            ->where('t2.IV_AUART_PARAM', $auart)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('so_yppr079_t1 as t1_check')
                    ->whereColumn('t1_check.VBELN', 't2.VBELN')
                    ->where('t1_check.PACKG', '!=', 0);
            })
            ->whereNotNull('t2.NAME1')->where('t2.NAME1', '!=', '')
            ->select('t2.KUNNR', 't2.NAME1')
            ->distinct()
            ->orderBy('t2.NAME1', 'asc')
            ->get();

        // 2. Loop setiap customer untuk mengambil data SO (Level-2) dan Item (Level-3)
        foreach ($customers as $customer) {
            // Ambil SO untuk customer ini
            $customer->sales_orders = DB::table('so_yppr079_t1 as t1')
                ->join('so_yppr079_t2 as t2', 't1.VBELN', '=', 't2.VBELN')
                ->select(
                    't1.VBELN',
                    't2.EDATU',
                    't1.WAERK',
                    DB::raw('COUNT(DISTINCT t1.id) as item_count'),
                    DB::raw('SUM(t1.TOTPR2) as total_value')
                )
                ->where('t2.KUNNR', $customer->KUNNR)
                ->where('t1.IV_WERKS_PARAM', $werks)
                ->where('t1.IV_AUART_PARAM', $auart)
                ->whereRaw('CAST(t1.PACKG AS DECIMAL(18,3)) <> 0')
                ->groupBy('t1.VBELN', 't2.EDATU', 't1.WAERK')
                ->orderBy('t1.VBELN')
                ->get();

            $today = now()->startOfDay();
            foreach ($customer->sales_orders as $so) {
                // Hitung overdue untuk setiap SO
                $so->Overdue = '-';
                if (!empty($so->EDATU) && $so->EDATU !== '0000-00-00') {
                    try {
                        $edatuDate = Carbon::parse($so->EDATU)->startOfDay();
                        $so->FormattedEdatu = $edatuDate->format('d-m-Y');
                        $so->Overdue = $today->diffInDays($edatuDate, false);
                    } catch (\Exception $e) {
                    }
                }

                // Ambil item untuk setiap SO (Level-3)
                $so->items = DB::table('so_yppr079_t1')
                    ->where('VBELN', $so->VBELN)
                    ->where('IV_WERKS_PARAM', $werks)
                    ->where('IV_AUART_PARAM', $auart)
                    ->whereRaw('CAST(PACKG AS DECIMAL(18,3)) <> 0')
                    ->select(
                        DB::raw("TRIM(LEADING '0' FROM POSNR) as POSNR"),
                        DB::raw("CASE WHEN MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM MATNR) ELSE MATNR END as MATNR"),
                        'MAKTX',
                        'KWMENG',
                        'PACKG',
                        'KALAB2',
                        'MENGE',
                        'NETPR',
                        'TOTPR2',
                        'WAERK'
                    )
                    ->orderByRaw('CAST(POSNR AS UNSIGNED) asc')
                    ->get();
            }
        }

        $data = [
            'customers' => $customers,
            'locationName' => $locationName,
            'werks' => $werks,
            'auartDescription' => $auartDesc,
            'today' => now(),
        ];

        $fileName = "Detailed_Overview_SO_{$locationName}_{$auart}_" . date('Ymd_His') . ".pdf";
        $pdf = Pdf::loadView('sales_order.so_customer_summary_pdf', $data)
            ->setPaper('a4', 'landscape');

        return $pdf->stream($fileName);
    }

*/
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Detailed SO Overview</title>
    <style>
        @page {
            margin: 24px;
        }

        body {
            font-family: DejaVu Sans, Helvetica, sans-serif;
            font-size: 9px;
            color: #0f172a;
        }

        .title {
            font-size: 16px;
            font-weight: 700;
            background: #e9fbf2;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 8px;
            text-align: center;
        }

        .subtitle {
            font-size: 10px;
            margin-bottom: 12px;
            color: #334155;
            text-align: center;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }

        th,
        td {
            padding: 5px 6px;
            border: 1px solid #e2e8f0;
            vertical-align: middle;
            text-align: center;
            word-wrap: break-word;
        }

        /* Level 1: Customer */
        .customer-row td {
            background-color: #f1f5f9;
            font-weight: bold;
            font-size: 11px;
            text-align: left;
        }

        /* Level 2: Sales Order */
        .so-header th {
            background-color: #e7faf1;
            font-size: 10px;
        }

        .so-row td {
            background-color: #ffffff;
        }

        .so-row td:first-child {
            padding-left: 20px;
            text-align: left;
        }

        /* Level 3: Items */
        .item-header th {
            background-color: #fafafa;
            font-size: 9px;
            color: #4b5563;
        }

        .item-row td {
            font-size: 8.5px;
        }

        .item-row td:nth-child(2) {
            padding-left: 40px;
            text-align: left;
        }

        .text-right {
            text-align: right !important;
        }

        .text-center {
            text-align: center !important;
        }

        .text-left {
            text-align: left !important;
        }

        .no-border {
            border: none !important;
        }

        .no-data td {
            padding: 20px;
            text-align: center;
            background: #f8fafc;
        }
    </style>
</head>

<body>
    <div class="title">Detailed Outstanding SO — {{ $locationName }} ({{ $werks }}) — {{ $auartDescription }}
    </div>
    <div class="subtitle">Generated on: {{ $today->format('d F Y H:i') }}</div>

    <table>
        @forelse($customers as $customer)
            {{-- Level 1: Customer --}}
            <tr class="customer-row">
                <td colspan="10">{{ $customer->NAME1 }}</td>
            </tr>

            @if ($customer->sales_orders->isNotEmpty())
                {{-- Level 2: SO Header --}}
                <tr class="so-header">
                    <th class="text-left" colspan="2">SO</th>
                    <th style="width: 10%;">Item Count</th>
                    <th style="width: 12%;">Req. Deliv. Date</th>
                    <th style="width: 10%;">Overdue (Days)</th>
                    <th style="width: 15%;" class="text-right">Outs. Value</th>
                    <th colspan="4" class="no-border"></th>
                </tr>

                @foreach ($customer->sales_orders as $so)
                    {{-- Level 2: SO Data --}}
                    <tr class="so-row">
                        <td colspan="2" class="text-left">{{ $so->VBELN }}</td>
                        <td>{{ $so->item_count }}</td>
                        <td>{{ $so->FormattedEdatu ?? '-' }}</td>
                        <td>{{ $so->Overdue }}</td>
                        <td class="text-right">{{ number_format($so->total_value, 2) }} {{ $so->WAERK }}</td>
                        <td colspan="4" class="no-border"></td>
                    </tr>

                    @if ($so->items->isNotEmpty())
                        {{-- Level 3: Item Header --}}
                        <tr class="item-header">
                            <th style="width: 5%;"></th>
                            <th class="text-left" style="width: 25%;">Desc FG</th>
                            <th style="width: 10%;">Material FG</th>
                            <th style="width: 8%;">Qty SO</th>
                            <th style="width: 8%;">Outs. SO</th>
                            <th style="width: 8%;">Stock PKG</th>
                            <th style="width: 8%;">GR PKG</th>
                            <th style="width: 10%;" class="text-right">Net Price</th>
                            <th style="width: 10%;" class="text-right">Outs. Value</th>
                            <th style="width: 8%;">Item</th>
                        </tr>
                        @foreach ($so->items as $item)
                            {{-- Level 3: Item Data --}}
                            <tr class="item-row">
                                <td></td>
                                <td class="text-left">{{ $item->MAKTX }}</td>
                                <td>{{ $item->MATNR }}</td>
                                <td>{{ number_format($item->KWMENG, 0) }}</td>
                                <td>{{ number_format($item->PACKG, 0) }}</td>
                                <td>{{ number_format($item->KALAB2, 0) }}</td>
                                <td>{{ number_format($item->MENGE, 0) }}</td>
                                <td class="text-right">{{ number_format($item->NETPR, 2) }}</td>
                                <td class="text-right">{{ number_format($item->TOTPR2, 2) }}</td>
                                <td>{{ $item->POSNR }}</td>
                            </tr>
                        @endforeach
                    @endif
                @endforeach
            @else
                <tr>
                    <td colspan="10" class="text-center">No outstanding SO for this customer.</td>
                </tr>
            @endif
        @empty
            <tr class="no-data">
                <td colspan="10">Tidak ada data customer yang ditemukan untuk filter ini.</td>
            </tr>
        @endforelse
    </table>
</body>

</html>
