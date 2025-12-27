<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\StockItemsExport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;


class StockController extends Controller
{

    private int $exportTokenTtlMinutes = 15;

    private function packToToken(array $payload): string
    {
        $t = (string) Str::ulid();
        Cache::put("stockexp:$t", [
            'uid'  => Auth::id(),
            'data' => $payload,
        ], now()->addMinutes($this->exportTokenTtlMinutes));
        return $t;
    }

    private function unpackFromToken(?string $t): array
    {
        abort_unless($t, 400, 'Missing token');
        $bag = Cache::get("stockexp:$t"); // multi-use, tidak dihapus
        abort_if(!$bag, 410, 'Token expired or not found');
        abort_if(($bag['uid'] ?? null) !== Auth::id(), 403, 'Token owner mismatch');
        // refresh TTL supaya tombol Download di viewer tetap hidup
        Cache::put("stockexp:$t", $bag, now()->addMinutes($this->exportTokenTtlMinutes));
        return (array) ($bag['data'] ?? []);
    }

    private function resolveLocationName(string $werks): string
    {
        return ['2000' => 'Surabaya', '3000' => 'Semarang'][$werks] ?? $werks;
    }

    private function stockTypeLabel(string $type): string
    {
        return $type === 'fg' ? 'PACKING' : 'WHFG';
    }

    private function buildFileName(string $base, string $ext): string
    {
        return sprintf('%s_%s.%s', $base, now()->format('Ymd_His'), $ext);
    }

    private function exportAuartsForWerks(?string $werks): array
    {
        if (!$werks) return [];

        return DB::table('maping')
            ->where('IV_WERKS', $werks)
            ->pluck('IV_AUART')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function applyAuartFilter($query, array $auarts, string $alias = ''): void
    {
        if (empty($auarts)) return;

        $p = $alias ? ($alias . '.') : '';
        $query->where(function ($q) use ($p, $auarts) {
            $q->whereIn($p . 'AUART', $auarts)
            ->orWhereIn($p . 'AUART2', $auarts);
        });
    }

    public function index(Request $request)
    {
        // ⬅️ DECRYPT ?q jika ada lalu merge ke request
        if ($request->filled('q')) {
            try {
                $data = Crypt::decrypt($request->query('q'));
                $request->merge(is_array($data) ? $data : []);
            } catch (DecryptException $e) {
                abort(404);
            }
        }

        // Mapping untuk sidebar/label
        $mapping = DB::table('maping')
            ->select('IV_WERKS', 'IV_AUART', 'Deskription')
            ->orderBy('IV_WERKS')->orderBy('IV_AUART')
            ->get()
            ->groupBy('IV_WERKS')
            ->map(fn($g) => $g->unique('IV_AUART')->values());

        $werks = (string) $request->query('werks', '');

        // default type
        $type = $request->query('type') === 'fg' ? 'fg' : 'whfg';

        // default return kalau belum pilih plant/export
        if ($werks === '') {
            return view('stock_report', [
                'mapping'         => $mapping,
                'rows'            => null,
                'selected'        => ['werks' => null, 'type' => $type],
                'grandTotalQty'   => 0,
                'grandTotalsCurr' => [],
                'pillTotals'      => ['whfg_qty' => 0, 'fg_qty' => 0],
            ]);
        }

        // Ambil daftar AUART export berdasarkan werks yg dipilih
        $auarts = $this->exportAuartsForWerks($werks);

        // Kalau mapping kosong, JANGAN tampilkan semua data (mencegah “bocor”)
        abort_if(empty($auarts), 422, "Mapping AUART untuk WERKS {$werks} belum ada.");

        // =========================
        // Pill totals (qty)
        // =========================
        $pillTotals = ['whfg_qty' => 0, 'fg_qty' => 0];

        $qt1 = DB::table('so_yppr079_t1');
        $this->applyAuartFilter($qt1, $auarts);
        $pillTotals['whfg_qty'] = (float) $qt1->where('KALAB', '>', 0)->sum('KALAB');

        $qt2 = DB::table('so_yppr079_t1');
        $this->applyAuartFilter($qt2, $auarts);
        $pillTotals['fg_qty'] = (float) $qt2->where('KALAB2', '>', 0)->sum('KALAB2');

        // =========================
        // Tabel-1 (Stock by Customer)
        // =========================
        $rows = null;
        $grandTotalQty = 0;
        $grandTotalsCurr = [];

        $q = DB::table('so_yppr079_t1 as t1')
            ->whereNotNull('t1.NAME1')
            ->where('t1.NAME1', '!=', '');

        $this->applyAuartFilter($q, $auarts, 't1');

        if ($type === 'whfg') {
            $q->where('t1.KALAB', '>', 0)
            ->select(
                't1.KUNNR',
                't1.NAME1',
                't1.WAERK',
                DB::raw('SUM(t1.NETPR * t1.KALAB) AS TOTAL_VALUE'),
                DB::raw('COUNT(DISTINCT t1.VBELN) AS SO_COUNT'),
                DB::raw('SUM(t1.KALAB) AS TOTAL_QTY')
            );
        } else { // fg
            $q->where('t1.KALAB2', '>', 0)
            ->select(
                't1.KUNNR',
                't1.NAME1',
                't1.WAERK',
                DB::raw('SUM(t1.NETPR * t1.KALAB2) AS TOTAL_VALUE'),
                DB::raw('COUNT(DISTINCT t1.VBELN) AS SO_COUNT'),
                DB::raw('SUM(t1.KALAB2) AS TOTAL_QTY')
            );
        }

        $rows = $q->groupBy('t1.KUNNR', 't1.NAME1', 't1.WAERK')
            ->orderBy('t1.NAME1', 'asc')
            ->paginate(25);

        // hanya append ?q
        if ($request->filled('q')) {
            $rows->appends(['q' => $request->query('q')]);
        }

        // grand qty
        $grandTotalQty = ($type === 'whfg') ? $pillTotals['whfg_qty'] : $pillTotals['fg_qty'];

        // =========================
        // Grand total value per currency
        // =========================
        $valueQuery = DB::table('so_yppr079_t1')
            ->select('WAERK', DB::raw(
                $type === 'whfg'
                    ? 'SUM(NETPR * KALAB) as val'
                    : 'SUM(NETPR * KALAB2) as val'
            ));

        $this->applyAuartFilter($valueQuery, $auarts);

        $valueQuery = $valueQuery
            ->when($type === 'whfg', fn($qq) => $qq->where('KALAB', '>', 0))
            ->when($type === 'fg', fn($qq) => $qq->where('KALAB2', '>', 0))
            ->groupBy('WAERK')
            ->get();

        foreach ($valueQuery as $r) {
            $grandTotalsCurr[$r->WAERK] = (float) $r->val;
        }

        return view('stock_report', [
            'mapping'         => $mapping,
            'rows'            => $rows,
            'selected'        => ['werks' => $werks, 'type' => $type],
            'grandTotalQty'   => $grandTotalQty,
            'grandTotalsCurr' => $grandTotalsCurr,
            'pillTotals'      => $pillTotals,
        ]);
    }

    // (OPSIONAL) kalau mau redirect via POST dari JS
    public function redirector(Request $request)
    {
        $payload = $request->input('payload');
        $data = is_string($payload) ? json_decode($payload, true) : (array) $payload;
        abort_unless(is_array($data), 400, 'Invalid payload');
        $clean = array_filter($data, fn($v) => !is_null($v) && $v !== '');
        return redirect()->route('stock.index', ['q' => Crypt::encrypt($clean)]);
    }

    /**
     * API: Ambil daftar SO untuk 1 customer (Level-2).
     */
    public function getSoByCustomer(Request $request)
    {
        $request->validate(['kunnr' => 'required', 'werks' => 'required', 'type' => 'required']);
        $type = $request->type === 'fg' ? 'fg' : 'whfg';
        $auarts = $this->exportAuartsForWerks($request->werks);


        $rows = DB::table('so_yppr079_t1 as t1')
            // ✅ JOIN AMAN (TRIM/CAST)
            ->leftJoin(
                'so_yppr079_t2 as t2',
                DB::raw('TRIM(CAST(t1.VBELN AS CHAR))'),
                '=',
                DB::raw('TRIM(CAST(t2.VBELN AS CHAR))')
            )
            ->where('t1.KUNNR', $request->kunnr);

        $this->applyAuartFilter($rows, $auarts, 't1');
        if ($type === 'whfg') {
            $rows->where('t1.KALAB', '>', 0)
                ->select(
                    't1.VBELN',
                    't2.EDATU',
                    't1.WAERK',
                    DB::raw('SUM(t1.NETPR * t1.KALAB) AS total_value'),
                    DB::raw('COUNT(t1.id) AS item_count'),
                    DB::raw('SUM(t1.KALAB) AS total_qty')
                );
        } else {
            $rows->where('t1.KALAB2', '>', 0)
                ->select(
                    't1.VBELN',
                    't2.EDATU',
                    't1.WAERK',
                    DB::raw('SUM(t1.NETPR * t1.KALAB2) AS total_value'),
                    DB::raw('COUNT(t1.id) AS item_count'),
                    DB::raw('SUM(t1.KALAB2) AS total_qty')
                );
        }

        $rows = $rows->groupBy('t1.VBELN', 't2.EDATU', 't1.WAERK')->get();

        $today = now()->startOfDay();
        foreach ($rows as $row) {
            $overdue = 0;
            $formattedEdatu = '';
            if (!empty($row->EDATU) && $row->EDATU !== '0000-00-00') {
                try {
                    $edatuDate = Carbon::parse($row->EDATU);
                    $formattedEdatu = $edatuDate->format('d-m-Y');
                    $overdue = $today->diffInDays($edatuDate->startOfDay(), false);
                } catch (\Exception $e) {
                }
            }
            $row->Overdue = $overdue;
            $row->FormattedEdatu = $formattedEdatu;
        }

        return response()->json([
            'ok'   => true,
            'data' => collect($rows)->sortBy('Overdue')->values()
        ]);
    }

    /**
     * API: Ambil item untuk 1 SO (Level-3).
     */
    public function getItemsBySo(Request $request)
    {
        $request->validate([
            'vbeln' => 'required',
            'werks' => 'required',
            'type'  => 'required|in:whfg,fg',
        ]);

        $type = $request->type === 'whfg' ? 'whfg' : 'fg';
        $auarts = $this->exportAuartsForWerks($request->werks);
        abort_if(empty($auarts), 422, "Mapping AUART untuk WERKS {$request->werks} belum ada.");

        $itemsQ = DB::table('so_yppr079_t1')
            ->select(
                'id',
                DB::raw("TRIM(LEADING '0' FROM POSNR) as POSNR"),
                'MATNR',
                'MAKTX',
                'KWMENG',
                'KALAB2',
                'KALAB',
                'NETPR',
                'WAERK',
                'VBELN',
                DB::raw("CASE
                    WHEN '{$type}' = 'whfg' THEN (KALAB * NETPR)
                    ELSE (KALAB2 * NETPR)
                END as VALUE")
            )
            ->whereRaw('TRIM(CAST(VBELN AS CHAR)) = TRIM(?)', [$request->vbeln]);

        // FILTER UTAMA: AUART/AUART2
        $this->applyAuartFilter($itemsQ, $auarts);

        // FILTER STOCK SESUAI TYPE
        $items = $itemsQ
            ->when($type === 'whfg', fn($q) => $q->where('KALAB', '>', 0))
            ->when($type === 'fg', fn($q) => $q->where('KALAB2', '>', 0))
            ->orderByRaw('CAST(POSNR AS UNSIGNED) asc')
            ->get();

        return response()->json(['ok' => true, 'data' => $items]);
    }

    /* ============================== EXPORT ============================== */

    /**
     * POST starter: validasi & bungkus payload -> redirect ke GET streamer.
     * (Nama route Blade tetap: stock.export)
     */
    public function exportDataStart(Request $request)
    {
        $validated = $request->validate([
            'item_ids'    => 'required|array|min:1',
            'export_type' => 'required|string|in:pdf,excel',
            'werks'       => 'required|string',
            'type'        => 'required|string|in:whfg,fg',
        ]);

        // Sanitasi ID -> integer unik > 0
        $ids = collect($validated['item_ids'])
            ->map(fn($v) => (int)preg_replace('/\D+/', '', (string)$v))
            ->filter(fn($v) => $v > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($ids)) {
            return back()->withErrors('Tidak ada item yang valid untuk diekspor.');
        }

        $payload = [
            'item_ids'    => $ids,
            'export_type' => $validated['export_type'],
            'werks'       => $validated['werks'],
            'type'        => $validated['type'] === 'fg' ? 'fg' : 'whfg',
        ];

        // >>> token cache (multi-use)
        $t = $this->packToToken($payload);

        // Redirect 303 ke GET streamer (pakai t)
        return redirect()->route('stock.export.show', ['t' => $t], 303);
    }

    /**
     * GET streamer: decrypt payload & kirim file (Excel/PDF).
     * (Route name: stock.export.show)
     */
    public function exportDataShow(Request $request)
    {
        // 1) Ambil payload: prioritas token 't', fallback ke legacy 'q'
        if ($request->filled('t')) {
            $data = $this->unpackFromToken($request->query('t'));
        } else {
            if (!$request->filled('q')) {
                return back()->withErrors('Payload export tidak ditemukan.');
            }
            try {
                $data = Crypt::decrypt($request->query('q'));
            } catch (DecryptException $e) {
                return back()->withErrors('Token export tidak valid.');
            }
        }

        // 2) Validasi & normalisasi
        $werks      = (string) ($data['werks'] ?? '');
        $type       = ((string) ($data['type'] ?? 'whfg')) === 'fg' ? 'fg' : 'whfg';
        $exportType = (string) ($data['export_type'] ?? 'pdf');

        $ids = collect($data['item_ids'] ?? [])
            ->map(fn($v) => (int) preg_replace('/\D+/', '', (string) $v))
            ->filter(fn($v) => $v > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty() || $werks === '' || !in_array($exportType, ['pdf', 'excel'], true)) {
            return back()->withErrors('Parameter export tidak lengkap/valid.');
        }

        // 3) Ambil AUART export utk werks ini (dipakai sebagai “filter utama”)
        $auarts = $this->exportAuartsForWerks($werks);
        abort_if(empty($auarts), 422, "Mapping AUART untuk WERKS {$werks} belum ada.");

        // 4) Query item: HARUS sesuai filter AUART/AUART2 + sesuai type stock
        $itemsQ = DB::table('so_yppr079_t1 as t1')
            ->whereIn('t1.id', $ids->all());

        // Filter AUART/AUART2
        $this->applyAuartFilter($itemsQ, $auarts, 't1');

        // Filter sesuai stock type (supaya export sama persis dengan layar)
        if ($type === 'whfg') {
            $itemsQ->where('t1.KALAB', '>', 0);
        } else {
            $itemsQ->where('t1.KALAB2', '>', 0);
        }

        $items = $itemsQ->select(
                't1.VBELN',
                't1.KUNNR',
                DB::raw("TRIM(LEADING '0' FROM t1.POSNR) AS POSNR"),
                DB::raw("CASE WHEN t1.MATNR REGEXP '^[0-9]+$' THEN TRIM(LEADING '0' FROM t1.MATNR) ELSE t1.MATNR END AS MATNR"),
                't1.MAKTX',
                't1.KWMENG',
                't1.KALAB',
                't1.KALAB2',
                't1.NETPR',
                't1.WAERK',
                DB::raw("(SELECT NAME1 FROM so_yppr079_t2 WHERE VBELN = t1.VBELN LIMIT 1) AS NAME1"),
                DB::raw("(SELECT BSTNK FROM so_yppr079_t2 WHERE VBELN = t1.VBELN LIMIT 1) AS BSTNK")
            )
            ->orderBy('t1.KUNNR', 'asc')
            ->orderBy('t1.VBELN', 'asc')
            ->orderByRaw('CAST(t1.POSNR AS UNSIGNED) asc')
            ->get();

        if ($items->isEmpty()) {
            return back()->withErrors('Tidak ada item yang valid untuk diekspor (mungkin tidak masuk filter export ini).');
        }

        // 5) Nama lokasi & tipe stock (label file saja; filter utamanya tetap AUART/AUART2)
        $locationName = $this->resolveLocationName($werks);
        $stockType    = $this->stockTypeLabel($type);

        // 6) Excel
        if ($exportType === 'excel') {
            $fileName = $this->buildFileName("Stock_{$locationName}_{$stockType}", 'xlsx');
            return Excel::download(new \App\Exports\StockItemsExport($items, $type), $fileName);
        }

        // 7) PDF
        $pdfBinary = Pdf::loadView('sales_order.stock_pdf_template', [
                'items'        => $items,
                'locationName' => $locationName,
                'werks'        => $werks,
                'stockType'    => $stockType,
                'today'        => now(),
            ])
            ->setPaper('a4', 'landscape')
            ->output();

        $fileBase = 'Stock_' . $locationName . '_' . $stockType;
        $fileName = $this->buildFileName($fileBase, 'pdf');

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response()->stream(function () use ($pdfBinary) {
            echo $pdfBinary;
        }, 200, [
            'Content-Type'           => 'application/pdf',
            'Content-Disposition'    => $disposition . '; filename="' . $fileName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=60, must-revalidate',
        ]);
    }
}
