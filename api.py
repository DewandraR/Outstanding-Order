# -*- coding: utf-8 -*-
"""
API/CLI untuk memanggil Z_FM_YPPR079_SO dan menyimpan T_DATA1/2/3 ke MySQL.

Mode CLI (tanpa HTTP):
  python api.py --sync --werks 2000 --auart ZOR3 --timeout 3000
  python api.py --sync --werks 2000
  python api.py --sync                # semua pair di tabel `maping`

Mode server (opsional):
  python api.py --serve

Laravel Task Scheduling
    php artisan yppr:sync (Menjalankan sinkronisasi penuh)

    php artisan schedule:work (PENTING)(melihat apakah ada skedul berjalan)
    ATAU BISA
    php artisan schedule:work 2>&1 | Where-Object {$_ -and ($_ -notmatch "No scheduled commands are ready to run")}     (supaya tidak terlalu banyak output)

    php artisan schedule:list (melihat jadwal akan dijalankan)


    
Buka file log langsung

cat storage/logs/yppr_sync.log


Lihat bagian akhir log (berguna kalau file sudah panjang):

tail -n 50 storage/logs/yppr_sync.log


(menampilkan 50 baris terakhir)

Pantau log secara real time (mirip live console):

tail -f storage/logs/yppr_sync.log


Get-Content -Wait storage\logs\yppr_sync.log
"""

import os, json, decimal, datetime
from typing import Any, Dict, List, Optional

from flask import Flask, jsonify, request
import mysql.connector
from pyrfc import Connection
from concurrent.futures import ThreadPoolExecutor, TimeoutError as FuturesTimeout
from dotenv import load_dotenv
import signal
signal.signal(signal.SIGINT, signal.SIG_IGN)  # abaikan KeyboardInterrupt

load_dotenv()  # otomatis baca .env dari working directory

# ---------- Konfigurasi ----------
DEFAULT_SAP = {
    "ashost": os.environ.get("SAP_ASHOST", "192.168.254.154"),
    "sysnr":  os.environ.get("SAP_SYSNR",  "01"),
    "client": os.environ.get("SAP_CLIENT", "300"),
    "lang":   os.environ.get("SAP_LANG",   "EN"),
}
DB_CFG = {
    "host": os.environ.get("DB_HOST", "localhost"),
    "user": os.environ.get("DB_USER", "root"),
    "password": os.environ.get("DB_PASS", ""),
    "database": os.environ.get("DB_NAME", "outstanding_yppr"),
}
RFC_NAME = "Z_FM_YPPR079_SO"

app = Flask(__name__)

# ---------- Koneksi ----------
def connect_mysql():
    return mysql.connector.connect(**DB_CFG)

def connect_sap(username: str, password: str) -> Connection:
    return Connection(
        user=username,
        passwd=password,
        ashost=DEFAULT_SAP["ashost"],
        sysnr=DEFAULT_SAP["sysnr"],
        client=DEFAULT_SAP["client"],
        lang=DEFAULT_SAP["lang"],
    )

# ---------- Util ----------
def get_sap_credentials_from_headers():
    u = request.headers.get("X-SAP-Username") or os.getenv("SAP_USERNAME", "auto_email")
    p = request.headers.get("X-SAP-Password") or os.getenv("SAP_PASSWORD", "11223344")
    print(
        f"[INFO] Using SAP user={u or '(empty)'} "
        f"client={DEFAULT_SAP['client']} ashost={DEFAULT_SAP['ashost']} "
        f"sysnr={DEFAULT_SAP['sysnr']} lang={DEFAULT_SAP['lang']}",
        flush=True
    )
    return (u, p)

def get_sap_credentials_from_env():
    return (
        os.getenv("SAP_USERNAME", "auto_email"),
        os.getenv("SAP_PASSWORD", "11223344"),
    )

def to_jsonable(obj: Any) -> Any:
    if obj is None: return None
    if isinstance(obj, (str, int, float, bool)): return obj
    if isinstance(obj, (bytes, bytearray)): return obj.decode("utf-8", errors="replace")
    if isinstance(obj, decimal.Decimal): return float(obj)
    if isinstance(obj, (datetime.date, datetime.datetime)): return obj.isoformat()
    if isinstance(obj, dict): return {k: to_jsonable(v) for k, v in obj.items()}
    if isinstance(obj, (list, tuple, set)): return [to_jsonable(x) for x in obj]
    return str(obj)

def fnum(x):
    try:
        if x in ("", None): return None
        return float(x)
    except:
        return None

def fdate_yyyymmdd(s):
    try:
        s = (s or "").strip()
        if len(s) == 8 and s.isdigit():
            return datetime.datetime.strptime(s, "%Y%m%d").date()
    except:
        pass
    return None

def call_rfc_with_timeout(conn: Connection, seconds: int, fm: str, **params) -> Dict[str, Any]:
    # Kirim CHAR/NUMC sebagai string
    for key in ("IV_WERKS", "IV_AUART"):
        if key in params and params[key] is not None:
            params[key] = str(params[key])
    with ThreadPoolExecutor(max_workers=1) as ex:
        fut = ex.submit(conn.call, fm, **params)
        try:
            return fut.result(timeout=seconds)
        except FuturesTimeout:
            raise TimeoutError(f"SAP RFC '{fm}' timed out after {seconds}s")

def fetch_pairs_from_maping(filter_werks=None, filter_auart=None, limit: int = None):
    """
    filter_werks: str atau list[str]
    filter_auart: str atau list[str]
    """
    db = connect_mysql()
    cur = db.cursor(dictionary=True)
    try:
        sql = "SELECT IV_WERKS, IV_AUART FROM maping"
        cond, args = [], []

        # WERKS bisa string atau list
        if filter_werks:
            if isinstance(filter_werks, (list, tuple)):
                placeholders = ",".join(["%s"] * len(filter_werks))
                cond.append(f"IV_WERKS IN ({placeholders})")
                args.extend([str(w) for w in filter_werks])
            else:
                cond.append("IV_WERKS = %s")
                args.append(str(filter_werks))

        # AUART bisa string atau list
        if filter_auart:
            if isinstance(filter_auart, (list, tuple)):
                placeholders = ",".join(["%s"] * len(filter_auart))
                cond.append(f"IV_AUART IN ({placeholders})")
                args.extend([str(a) for a in filter_auart])
            else:
                cond.append("IV_AUART = %s")
                args.append(str(filter_auart))

        if cond:
            sql += " WHERE " + " AND ".join(cond)
        sql += " ORDER BY IV_WERKS, IV_AUART"
        if limit and limit > 0:
            sql += f" LIMIT {int(limit)}"

        cur.execute(sql, tuple(args))
        return cur.fetchall()
    finally:
        cur.close(); db.close()

# ---------- DDL ----------
def ensure_tables():
    # T1 TANPA UNIQUE agar semua baris RFC benar-benar masuk
    ddl1 = """
    CREATE TABLE IF NOT EXISTS so_yppr079_t1 (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      IV_WERKS_PARAM VARCHAR(10) NOT NULL,
      IV_AUART_PARAM VARCHAR(10) NOT NULL,
      VBELN VARCHAR(20), POSNR VARCHAR(10),
      MANDT VARCHAR(10), KUNNR VARCHAR(20), NAME1 VARCHAR(120),
      AUART VARCHAR(10), NETPR DECIMAL(18,2), NETWR DECIMAL(18,2),
      TOTPR DECIMAL(18,2), TOTPR2 DECIMAL(18,2), WAERK VARCHAR(5),
      EDATU DATE, WERKS VARCHAR(10), BSTNK VARCHAR(80),
      KWMENG DECIMAL(18,3), BMENG DECIMAL(18,3), VRKME VARCHAR(6), MEINS VARCHAR(6),
      MATNR VARCHAR(40), MAKTX VARCHAR(200),
      KALAB DECIMAL(18,3), KALAB2 DECIMAL(18,3),
      QTY_DELIVERY DECIMAL(18,3),
      QTY_GI DECIMAL(18,3), QTY_BALANCE DECIMAL(18,3), QTY_BALANCE2 DECIMAL(18,3),
      MENGX1 DECIMAL(18,3), MENGX2 DECIMAL(18,3), MENGE DECIMAL(18,3),
      ASSYM DECIMAL(18,3), PAINT DECIMAL(18,3), PACKG DECIMAL(18,3),
      QTYS DECIMAL(18,3), MACHI DECIMAL(18,3), EBDIN DECIMAL(18,3),
      MACHP DECIMAL(18,3), EBDIP DECIMAL(18,3),
      TYPE1 VARCHAR(25), TYPE2 VARCHAR(25), TYPE VARCHAR(25), DAYX INT,
      fetched_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """
    ddl2 = "CREATE TABLE IF NOT EXISTS so_yppr079_t2 LIKE so_yppr079_t1;"
    ddl2_fix = """
    ALTER TABLE so_yppr079_t2
      MODIFY NAME1 VARCHAR(120),
      MODIFY TYPE VARCHAR(25),
      MODIFY TYPE1 VARCHAR(25),
      MODIFY TYPE2 VARCHAR(25);
    """
    # T3 tetap punya UNIQUE (seperti sebelumnya)
    ddl3 = """
    CREATE TABLE IF NOT EXISTS so_yppr079_t3 (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      IV_WERKS_PARAM VARCHAR(10) NOT NULL,
      IV_AUART_PARAM VARCHAR(10) NOT NULL,
      VBELN VARCHAR(20), POSNR VARCHAR(10),
      MANDT VARCHAR(10), KUNNR VARCHAR(20), NAME1 VARCHAR(120),
      AUART VARCHAR(10), NETPR DECIMAL(18,2), NETWR DECIMAL(18,2),
      TOTPR DECIMAL(18,2), TOTPR2 DECIMAL(18,2), WAERK VARCHAR(5),
      EDATU DATE, WERKS VARCHAR(10), BSTNK VARCHAR(80),
      KWMENG DECIMAL(18,3), BMENG DECIMAL(18,3), VRKME VARCHAR(6), MEINS VARCHAR(6),
      MATNR VARCHAR(40), MAKTX VARCHAR(200),
      KALAB DECIMAL(18,3), KALAB2 DECIMAL(18,3),
      QTY_DELIVERY DECIMAL(18,3),
      QTY_GI DECIMAL(18,3), QTY_BALANCE DECIMAL(18,3), QTY_BALANCE2 DECIMAL(18,3),
      MENGX1 DECIMAL(18,3), MENGX2 DECIMAL(18,3), MENGE DECIMAL(18,3),
      ASSYM DECIMAL(18,3), PAINT DECIMAL(18,3), PACKG DECIMAL(18,3),
      QTYS DECIMAL(18,3), MACHI DECIMAL(18,3), EBDIN DECIMAL(18,3),
      MACHP DECIMAL(18,3), EBDIP DECIMAL(18,3),
      TYPE1 VARCHAR(25), TYPE2 VARCHAR(25), TYPE VARCHAR(25), DAYX INT,
      fetched_at DATETIME NOT NULL,
      UNIQUE KEY uq_t3 (IV_WERKS_PARAM, IV_AUART_PARAM, VBELN, POSNR)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """
    db = connect_mysql()
    cur = db.cursor()
    try:
        # Buat tabel dasar
        cur.execute(ddl1)
        cur.execute(ddl2)
        cur.execute(ddl2_fix)
        cur.execute(ddl3)

        # Pastikan kolom KALAB2 ada di semua tabel (untuk kompatibilitas ke belakang)
        for _tbl in ("so_yppr079_t1", "so_yppr079_t2", "so_yppr079_t3"):
            try:
                cur.execute(f"ALTER TABLE {_tbl} ADD COLUMN KALAB2 DECIMAL(18,3) AFTER KALAB")
            except Exception:
                # kolom sudah ada → aman
                pass

        # ---- Perapihan index unik lama (kalau ada) ----
        # T1: buang UNIQUE lawas agar boleh menyimpan semua baris RFC
        try:
            cur.execute("ALTER TABLE so_yppr079_t1 DROP INDEX uq_t1")
        except Exception:
            pass  # index tidak ada → aman

        # T2: pastikan T2 PUNYA UNIQUE (karena dibuat LIKE T1 yang kini tanpa unique)
        #     agar ON DUPLICATE KEY UPDATE di T2 tetap bekerja
        try:
            cur.execute("""
                ALTER TABLE so_yppr079_t2
                ADD UNIQUE KEY uq_t2 (IV_WERKS_PARAM, IV_AUART_PARAM, VBELN, POSNR)
            """)
        except Exception:
            pass  # mungkin sudah ada → aman

        db.commit()
    finally:
        cur.close(); db.close()

# ---------- UPSERT (tetap disimpan untuk kompatibilitas lama) ----------
def upsert_generic(cur, table, werks, auart, rows):
    """
    Simpan baris T_DATAx ke tabel (t1/t2). Placeholder & value dibangun
    dari list kolom, sehingga jumlahnya selalu cocok.
    """
    cols = [
        "IV_WERKS_PARAM", "IV_AUART_PARAM", "VBELN", "POSNR",
        "MANDT", "KUNNR", "NAME1", "AUART", "NETPR", "NETWR",
        "TOTPR", "TOTPR2", "WAERK",
        "EDATU", "WERKS", "BSTNK",
        "KWMENG", "BMENG", "VRKME", "MEINS",
        "MATNR", "MAKTX",
        "KALAB", "KALAB2", "QTY_DELIVERY", "QTY_GI", "QTY_BALANCE", "QTY_BALANCE2",
        "MENGX1", "MENGX2", "MENGE",
        "ASSYM", "PAINT", "PACKG",
        "QTYS", "MACHI", "EBDIN", "MACHP", "EBDIP",
        "TYPE1", "TYPE2", "TYPE", "DAYX",
        "fetched_at",
    ]
    col_list = ", ".join(cols)
    placeholders = ", ".join(["%s"] * len(cols))
    upd_cols = [c for c in cols if c not in ("IV_WERKS_PARAM", "IV_AUART_PARAM", "VBELN", "POSNR")]
    set_clause = ", ".join([f"{c}=VALUES({c})" for c in upd_cols])

    sql = f"""
    INSERT INTO {table} ({col_list})
    VALUES ({placeholders})
    ON DUPLICATE KEY UPDATE {set_clause}
    """

    now = datetime.datetime.now()

    def param_tuple(r):
        return (
            werks, auart,
            r.get("VBELN"),
            str(r.get("POSNR")) if r.get("POSNR") is not None else None,
            r.get("MANDT"), r.get("KUNNR"), r.get("NAME1"), r.get("AUART"),
            fnum(r.get("NETPR")), fnum(r.get("NETWR")),
            fnum(r.get("TOTPR")), fnum(r.get("TOTPR2")), r.get("WAERK"),
            fdate_yyyymmdd(r.get("EDATU")), r.get("WERKS"), r.get("BSTNK"),
            fnum(r.get("KWMENG")), fnum(r.get("BMENG")), r.get("VRKME"), r.get("MEINS"),
            r.get("MATNR"), r.get("MAKTX"),
            fnum(r.get("KALAB")), fnum(r.get("KALAB2")), fnum(r.get("QTY_DELIVERY")), fnum(r.get("QTY_GI")),
            fnum(r.get("QTY_BALANCE")), fnum(r.get("QTY_BALANCE2")),
            fnum(r.get("MENGX1")), fnum(r.get("MENGX2")), fnum(r.get("MENGE")),
            fnum(r.get("ASSYM")), fnum(r.get("PAINT")), fnum(r.get("PACKG")),
            fnum(r.get("QTYS")), fnum(r.get("MACHI")), fnum(r.get("EBDIN")),
            fnum(r.get("MACHP")), fnum(r.get("EBDIP")),
            r.get("TYPE1"), r.get("TYPE2"), r.get("TYPE"),
            int(r.get("DAYX") or 0),
            now,
        )

    batch = [param_tuple(r) for r in (rows or [])]
    if batch:
        cur.executemany(sql, batch)

def upsert_t3(cur, werks, auart, rows):
    """
    Simpan T_DATA3 ke tabel T3 (bukan JSON). Skemanya sama dengan T1/T2.
    """
    cols = [
        "IV_WERKS_PARAM", "IV_AUART_PARAM", "VBELN", "POSNR",
        "MANDT", "KUNNR", "NAME1", "AUART", "NETPR", "NETWR",
        "TOTPR", "TOTPR2", "WAERK",
        "EDATU", "WERKS", "BSTNK",
        "KWMENG", "BMENG", "VRKME", "MEINS",
        "MATNR", "MAKTX",
        "KALAB", "KALAB2", "QTY_DELIVERY", "QTY_GI", "QTY_BALANCE", "QTY_BALANCE2",
        "MENGX1", "MENGX2", "MENGE",
        "ASSYM", "PAINT", "PACKG",
        "QTYS", "MACHI", "EBDIN", "MACHP", "EBDIP",
        "TYPE1", "TYPE2", "TYPE", "DAYX",
        "fetched_at",
    ]
    col_list = ", ".join(cols)
    placeholders = ", ".join(["%s"] * len(cols))
    upd_cols = [c for c in cols if c not in ("IV_WERKS_PARAM","IV_AUART_PARAM","VBELN","POSNR")]
    set_clause = ", ".join([f"{c}=VALUES({c})" for c in upd_cols])

    sql = f"""
    INSERT INTO so_yppr079_t3 ({col_list})
    VALUES ({placeholders})
    ON DUPLICATE KEY UPDATE {set_clause}
    """

    now = datetime.datetime.now()

    def param_tuple(r):
        return (
            werks, auart,
            r.get("VBELN"),
            str(r.get("POSNR")) if r.get("POSNR") is not None else None,
            r.get("MANDT"), r.get("KUNNR"), r.get("NAME1"), r.get("AUART"),
            fnum(r.get("NETPR")), fnum(r.get("NETWR")),
            fnum(r.get("TOTPR")), fnum(r.get("TOTPR2")), r.get("WAERK"),
            fdate_yyyymmdd(r.get("EDATU")), r.get("WERKS"), r.get("BSTNK"),
            fnum(r.get("KWMENG")), fnum(r.get("BMENG")), r.get("VRKME"), r.get("MEINS"),
            r.get("MATNR"), r.get("MAKTX"),
            fnum(r.get("KALAB")), fnum(r.get("KALAB2")), fnum(r.get("QTY_DELIVERY")), fnum(r.get("QTY_GI")),
            fnum(r.get("QTY_BALANCE")), fnum(r.get("QTY_BALANCE2")),
            fnum(r.get("MENGX1")), fnum(r.get("MENGX2")), fnum(r.get("MENGE")),
            fnum(r.get("ASSYM")), fnum(r.get("PAINT")), fnum(r.get("PACKG")),
            fnum(r.get("QTYS")), fnum(r.get("MACHI")), fnum(r.get("EBDIN")),
            fnum(r.get("MACHP")), fnum(r.get("EBDIP")),
            r.get("TYPE1"), r.get("TYPE2"), r.get("TYPE"),
            int(r.get("DAYX") or 0),
            now,
        )

    batch = [param_tuple(r) for r in (rows or [])]
    if batch:
        cur.executemany(sql, batch)

# ---------- Bulk-buffer helpers ----------
COMMON_COLS = [
    "IV_WERKS_PARAM", "IV_AUART_PARAM", "VBELN", "POSNR",
    "MANDT", "KUNNR", "NAME1", "AUART", "NETPR", "NETWR",
    "TOTPR", "TOTPR2", "WAERK",
    "EDATU", "WERKS", "BSTNK",
    "KWMENG", "BMENG", "VRKME", "MEINS",
    "MATNR", "MAKTX",
    "KALAB", "KALAB2", "QTY_DELIVERY", "QTY_GI", "QTY_BALANCE", "QTY_BALANCE2",
    "MENGX1", "MENGX2", "MENGE",
    "ASSYM", "PAINT", "PACKG",
    "QTYS", "MACHI", "EBDIN", "MACHP", "EBDIP",
    "TYPE1", "TYPE2", "TYPE", "DAYX",
    "fetched_at",
]
COL_LIST = ", ".join(COMMON_COLS)
PLACEHOLDERS = ", ".join(["%s"] * len(COMMON_COLS))
_NON_KEY_UPDATE = [c for c in COMMON_COLS if c not in ("IV_WERKS_PARAM","IV_AUART_PARAM","VBELN","POSNR")]
_SET_CLAUSE = ", ".join([f"{c}=VALUES({c})" for c in _NON_KEY_UPDATE])

def _row_params(werks: str, auart: str, r: Dict[str, Any], now: datetime.datetime):
    """Bangun 1 tuple parameter insert sesuai kolom COMMON_COLS."""
    return (
        werks, auart,
        r.get("VBELN"),
        str(r.get("POSNR")) if r.get("POSNR") is not None else None,
        r.get("MANDT"), r.get("KUNNR"), r.get("NAME1"), r.get("AUART"),
        fnum(r.get("NETPR")), fnum(r.get("NETWR")),
        fnum(r.get("TOTPR")), fnum(r.get("TOTPR2")), r.get("WAERK"),
        fdate_yyyymmdd(r.get("EDATU")), r.get("WERKS"), r.get("BSTNK"),
        fnum(r.get("KWMENG")), fnum(r.get("BMENG")), r.get("VRKME"), r.get("MEINS"),
        r.get("MATNR"), r.get("MAKTX"),
        fnum(r.get("KALAB")), fnum(r.get("KALAB2")), fnum(r.get("QTY_DELIVERY")), fnum(r.get("QTY_GI")),
        fnum(r.get("QTY_BALANCE")), fnum(r.get("QTY_BALANCE2")),
        fnum(r.get("MENGX1")), fnum(r.get("MENGX2")), fnum(r.get("MENGE")),
        fnum(r.get("ASSYM")), fnum(r.get("PAINT")), fnum(r.get("PACKG")),
        fnum(r.get("QTYS")), fnum(r.get("MACHI")), fnum(r.get("EBDIN")),
        fnum(r.get("MACHP")), fnum(r.get("EBDIP")),
        r.get("TYPE1"), r.get("TYPE2"), r.get("TYPE"),
        int(r.get("DAYX") or 0),
        now,
    )

def _bulk_insert(cur, table: str, params: list, batch_size: int = 1000):
    """Bulk insert dengan ON DUPLICATE KEY UPDATE agar duplikat dalam 1 batch tidak error."""
    if not params:
        return 0
    sql = (
        f"INSERT INTO {table} ({COL_LIST}) VALUES ({PLACEHOLDERS}) "
        f"ON DUPLICATE KEY UPDATE {_SET_CLAUSE}"
    )
    total = 0
    for i in range(0, len(params), batch_size):
        chunk = params[i:i+batch_size]
        cur.executemany(sql, chunk)
        total += len(chunk)
    return total

def _bulk_insert_plain(cur, table: str, params: list, batch_size: int = 1000):
    """Bulk insert tanpa upsert (dipakai untuk T1 setelah TRUNCATE)."""
    if not params:
        return 0
    sql = f"INSERT INTO {table} ({COL_LIST}) VALUES ({PLACEHOLDERS})"
    total = 0
    for i in range(0, len(params), batch_size):
        chunk = params[i:i+batch_size]
        cur.executemany(sql, chunk)
        total += len(chunk)
    return total

# ---------- LOGIKA SYNC ----------
def do_sync(filter_werks=None, filter_auart=None, limit: Optional[int] = None, timeout_sec: int = 3000):
    """
    Versi buffer:
    - Kumpulkan semua hasil ke array (t1_buf/t2_buf/t3_buf) sepanjang loop pair
    - Setelah semua pair selesai, kosongkan tabel (TRUNCATE)
    - Lalu masukkan seluruh buffer sekaligus
    """
    ensure_tables()

    username, password = get_sap_credentials_from_env()
    if not username or not password:
        return {
            "ok": False,
            "error": "Missing SAP_USERNAME or SAP_PASSWORD (dotenv not loaded or .env values empty)."
        }

    start_ts = datetime.datetime.now()
    print(f"[INFO] Start do_sync at {start_ts:%Y-%m-%d %H:%M:%S}", flush=True)

    pairs = fetch_pairs_from_maping(filter_werks, filter_auart, limit)
    total = len(pairs)
    print(f"[INFO] Total pairs: {total}", flush=True)
    if not pairs:
        return {"ok": False, "error": "No mapping pairs found for given filter.", "pairs": []}

    # koneksi SAP sekali
    try:
        print("[INFO] Connecting to SAP...", flush=True)
        sap = connect_sap(username, password)
        print("[INFO] SAP connected.", flush=True)
    except Exception as e:
        print(f"[ERROR] SAP connection failed: {e}", flush=True)
        return {"ok": False, "error": f"SAP connection failed: {e}", "pairs": []}

    # Buffer untuk semua tabel
    t1_buf: List[tuple] = []
    t2_buf: List[tuple] = []
    t3_buf: List[tuple] = []
    total_t1 = total_t2 = total_t3 = 0
    summary: List[Dict[str, Any]] = []

    try:
        for idx, p in enumerate(pairs, 1):
            werks = str(p["IV_WERKS"]); auart = str(p["IV_AUART"])
            try:
                print(f"[{idx}/{total}] {werks}/{auart} - calling RFC...", flush=True)
                r = call_rfc_with_timeout(sap, timeout_sec, RFC_NAME, IV_WERKS=werks, IV_AUART=auart)

                t1 = r.get("T_DATA1", []) or []
                t2 = r.get("T_DATA2", []) or []
                t3 = r.get("T_DATA3", []) or []

                now = datetime.datetime.now()

                # Simpan ke buffer (array)
                t1_buf.extend(_row_params(werks, auart, row, now) for row in t1)
                t2_buf.extend(_row_params(werks, auart, row, now) for row in t2)
                t3_buf.extend(_row_params(werks, auart, row, now) for row in t3)

                total_t1 += len(t1); total_t2 += len(t2); total_t3 += len(t3)
                summary.append({"werks": werks, "auart": auart, "t1_saved": len(t1), "t2_saved": len(t2), "t3_saved": len(t3)})

                print(f"[{idx}/{total}] {werks}/{auart} - saved t1={len(t1)} t2={len(t2)} t3={len(t3)} to array", flush=True)

            except FuturesTimeout as te:
                print(f"[{idx}/{total}] {werks}/{auart} - TIMEOUT: {te}", flush=True)
                summary.append({"werks": werks, "auart": auart, "error": f"timeout: {te}"})
            except TimeoutError as te:
                print(f"[{idx}/{total}] {werks}/{auart} - TIMEOUT: {te}", flush=True)
                summary.append({"werks": werks, "auart": auart, "error": f"timeout: {te}"})
            except Exception as e:
                print(f"[{idx}/{total}] {werks}/{auart} - ERROR: {e}", flush=True)
                summary.append({"werks": werks, "auart": auart, "error": f"RFC/parse error: {e}"})
    finally:
        try: sap.close()
        except Exception: pass

    print("Semua pair telah tersimpan ke buffer.", flush=True)

    # Tulis ke DB: kosongkan tabel lama, lalu insert semua buffer
    db  = connect_mysql()
    cur = db.cursor()
    inserted_t1 = inserted_t2 = inserted_t3 = 0
    try:
        print("Menghapus tabel lama (TRUNCATE)…", flush=True)
        cur.execute("SET FOREIGN_KEY_CHECKS=0")
        cur.execute("TRUNCATE TABLE so_yppr079_t1")
        cur.execute("TRUNCATE TABLE so_yppr079_t2")
        cur.execute("TRUNCATE TABLE so_yppr079_t3")
        cur.execute("SET FOREIGN_KEY_CHECKS=1")

        print("Memasukkan query SQL buffered ke database…", flush=True)

        # T1: INSERT plain (tanpa upsert) → jumlah row di DB = jumlah baris RFC T1
        inserted_t1 = _bulk_insert_plain(cur, "so_yppr079_t1", t1_buf)

        # T2/T3: tetap upsert (punya UNIQUE), seperti semula
        inserted_t2 = _bulk_insert(cur, "so_yppr079_t2", t2_buf)
        inserted_t3 = _bulk_insert(cur, "so_yppr079_t3", t3_buf)

        db.commit()
        print(f"[INFO] Inserted buffered rows: t1={inserted_t1} t2={inserted_t2} t3={inserted_t3}", flush=True)
    except Exception as e:
        db.rollback()
        print(f"[ERROR] Gagal menulis buffered data: {e}", flush=True)
        return {"ok": False, "error": f"DB insert failed: {e}", "summary": summary}
    finally:
        try: cur.close()
        except Exception: pass
        try: db.close()
        except Exception: pass

    done_ts = datetime.datetime.now()
    print(f"[INFO] Done. totals: t1={total_t1} t2={total_t2} t3={total_t3} @ {done_ts:%Y-%m-%d %H:%M:%S}", flush=True)

    return {
        "ok": True,
        "totals": {"t1": total_t1, "t2": total_t2, "t3": total_t3},
        "inserted": {"t1": inserted_t1, "t2": inserted_t2, "t3": inserted_t3},
        "pairs": summary,
        "started_at": start_ts.isoformat(),
        "finished_at": done_ts.isoformat(),
    }

# ---------- Endpoints (opsional) ----------
@app.route("/", methods=["GET"])
def home():
    return (
        "OK. POST /api/yppr079/sync?werks=2000&auart=ZOR3&timeout=3000 "
        "Headers (opsional): X-SAP-Username / X-SAP-Password; ENV juga didukung.",
        200, {"Content-Type": "text/plain"}
    )

@app.route("/api/yppr079/sync", methods=["POST"])
def sync_yppr079_http():
    ensure_tables()
    u, p = get_sap_credentials_from_headers()
    filter_werks = request.args.get("werks")
    filter_auart = request.args.get("auart")
    limit_param  = request.args.get("limit", type=int)
    timeout_sec  = request.args.get("timeout", default=3000, type=int)

    # sementara pakai ENV helper di do_sync; override ENV dulu
    os.environ["SAP_USERNAME"] = u
    os.environ["SAP_PASSWORD"] = p

    result = do_sync(filter_werks, filter_auart, limit_param, timeout_sec)
    return app.response_class(
        response=json.dumps(to_jsonable(result), ensure_ascii=False),
        status=(200 if result.get("ok") else 500),
        mimetype="application/json",
    )

# ---------- Main ----------
if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser(description="YPPR079 API/Sync")
    parser.add_argument("--serve", action="store_true", help="Jalankan server Flask")
    parser.add_argument("--sync", action="store_true", help="Jalankan sinkronisasi langsung (tanpa HTTP)")
    parser.add_argument("--werks", type=str, help="Filter IV_WERKS")
    parser.add_argument("--auart", nargs="+", help="Filter IV_AUART(Boleh lebih dari satu AUART)")
    parser.add_argument("--limit", type=int, help="Batas jumlah pair dari tabel maping")
    parser.add_argument("--timeout", type=int, default=3000, help="Timeout per panggilan RFC (detik)")
    args = parser.parse_args()

    if args.sync:
        result = do_sync(
            filter_werks=args.werks,
            filter_auart=args.auart,   # boleh None, string, atau list
            limit=args.limit,
            timeout_sec=args.timeout
        )
        print(json.dumps(to_jsonable(result), ensure_ascii=False, indent=2))
    else:
        app.run(host="127.0.0.1", port=5000, debug=True)
