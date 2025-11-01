# -*- coding: utf-8 -*-
"""
API/CLI untuk memanggil Z_FM_YPPR079_SO dan Z_FM_YSDR048 dan menyimpan T_DATA1/2/3 (+ T_DATA4) ke MySQL.


Mode CLI (tanpa HTTP):
  python api.py --sync --werks 2000 --auart ZOR3 --timeout 3000
  python api.py --sync --werks 2000
  python api.py --sync             # semua pair di tabel `maping`

UNTUK STOCK
  python api.py --sync_stock
  python api.py --sync_stock --timeout 3000

Mode server (opsional):
  python api.py --serve

Laravel Task Scheduling
  php artisan yppr:sync
  php artisan schedule:work
  php artisan schedule:list

Buka file log langsung
  cat storage/logs/yppr_sync.log

Lihat bagian akhir log
  tail -n 50 storage/logs/yppr_sync.log

Pantau log secara real time
  tail -f storage/logs/yppr_sync.log
  Get-Content -Wait storage\logs\yppr_sync.log

PENTING AGAR TIDAK VERBOSE
php artisan schedule:work 2>&1 | Where-Object {$_ -and ($_ -notmatch "No scheduled commands are ready to run")}

BASH NOT VERBOSE
stdbuf -oL -eL php artisan --no-ansi schedule:work 2>&1 | grep -v -E 'No scheduled commands are ready to run|^\s*$|^stdout is not a tty$'
"""

import os, json, decimal, datetime, math
import sys
from typing import Any, Dict, List, Optional, Tuple

from flask import Flask, jsonify, request
import mysql.connector
from pyrfc import Connection
from concurrent.futures import ThreadPoolExecutor, TimeoutError as FuturesTimeout
from dotenv import load_dotenv
import signal

# abaikan KeyboardInterrupt agar thread pool/IO tidak membatalkan proses di tengah bulk insert
try:
    signal.signal(signal.SIGINT, signal.SIG_IGN)
except Exception:
    pass

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
RFC_NAME_SO     = "Z_FM_YPPR079_SO"
RFC_NAME_STOCK  = "Z_FM_YSDR048"

# 🌟 PEMETAAN BARU UNTUK SYNC STOCK (DITAMBAH IV_SIPM)
STOCK_TABLE_MAP = {
    "IV_SIAD": "stock_assy",
    "IV_SIPD": "stock_ptg",
    "IV_SIPP": "stock_pkg",
    "IV_SIPM": "stock_ptg_m",  # <-- PENAMBAHAN
}

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

def connect_sap_with_fallback(username: str, password: str) -> Tuple[Connection, str]:
    """
    Coba koneksi SAP dengan user awal. Jika user awal adalah 'auto_email' dan gagal,
    otomatis fallback ke 'sap_automation' dengan password yang sama.
    Mengembalikan (conn, used_username).
    """
    primary_user = (username or "auto_email").strip()
    fallback_user = os.getenv("SAP_FALLBACK_USER", "sap_automation").strip()

    try:
        print(f"[INFO] Connecting to SAP as '{primary_user}'...", flush=True)
        conn = connect_sap(primary_user, password)
        print(f"[INFO] SAP connected as '{primary_user}'.", flush=True)
        return conn, primary_user
    except Exception as e1:
        # Fallback hanya jika user awal adalah auto_email (sesuai permintaan)
        if primary_user.lower() == "auto_email":
            print(f"[WARN] Login '{primary_user}' failed ({e1}). Trying fallback '{fallback_user}'...", flush=True)
            try:
                conn = connect_sap(fallback_user, password)
                print(f"[INFO] SAP connected as fallback '{fallback_user}'.", flush=True)
                return conn, fallback_user
            except Exception as e2:
                print(f"[ERROR] Fallback '{fallback_user}' also failed: {e2}", flush=True)
                raise
        else:
            print(f"[ERROR] SAP connection failed for user '{primary_user}': {e1}", flush=True)
            raise


# ---------- Util ----------
def get_sap_credentials_from_headers() -> Tuple[str, str]:
    u = request.headers.get("X-SAP-Username") or os.getenv("SAP_USERNAME", "auto_email")
    p = request.headers.get("X-SAP-Password") or os.getenv("SAP_PASSWORD", "11223344")
    print(
        f"[INFO] Using SAP user={u or '(empty)'} "
        f"client={DEFAULT_SAP['client']} ashost={DEFAULT_SAP['ashost']} "
        f"sysnr={DEFAULT_SAP['sysnr']} lang={DEFAULT_SAP['lang']}",
        flush=True
    )
    return (u, p)

def get_sap_credentials_from_env() -> Tuple[str, str]:
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
    """Konversi 'angka' ke float yang aman untuk kolom DECIMAL.
    - Non-finite (NaN/Inf) -> None
    - String dengan koma desimal -> normalisasi
    - decimal.Decimal -> cek is_finite()
    """
    try:
        if x in ("", None):
            return None
        # Jika Decimal
        if isinstance(x, decimal.Decimal):
            if not x.is_finite():  # NaN/Inf
                return None
            return float(x)
        # Jika string
        if isinstance(x, str):
            sx = x.strip()
            if sx.lower() in ("nan", "+nan", "-nan", "inf", "+inf", "-inf", "infinity", "+infinity", "-infinity"):
                return None
            # normalisasi koma -> titik bila perlu
            if sx.count(",") == 1 and sx.count(".") == 0:
                sx = sx.replace(",", ".")
            val = float(sx)
            return val if math.isfinite(val) else None
        # Jika numerik biasa
        if isinstance(x, (int, float)):
            val = float(x)
            return val if math.isfinite(val) else None
        # Fallback coba-coba
        val = float(x)
        return val if math.isfinite(val) else None
    except Exception:
        return None

def fdate_yyyymmdd(s):
    try:
        s = (s or "").strip()
        if len(s) == 8 and s.isdigit():
            return datetime.datetime.strptime(s, "%Y%m%d").date()
    except:
        pass
    return None

def _sap_call_no_decimal_trap(conn: Connection, fm: str, **params):
    """Panggil RFC sambil mematikan trap InvalidOperation di context decimal thread ini."""
    ctx = decimal.getcontext()
    # Pastikan NaN/Inf dari SAP tidak raise saat dibentuk Decimal oleh pyrfc
    ctx.traps[decimal.InvalidOperation] = False
    return conn.call(fm, **params)

def call_rfc_with_timeout(conn: Connection, seconds: int, fm: str, **params) -> Dict[str, Any]:
    # Kirim CHAR/NUMC sebagai string
    for key in ("IV_WERKS", "IV_AUART", "IV_SIAD", "IV_SIPD", "IV_SIPP", "IV_SIPM"):
        if key in params and params[key] is not None:
            params[key] = str(params[key])
    with ThreadPoolExecutor(max_workers=1) as ex:
        fut = ex.submit(_sap_call_no_decimal_trap, conn, fm, **params)
        try:
            return fut.result(timeout=seconds)
        except FuturesTimeout:
            raise TimeoutError(f"SAP RFC '{fm}' timed out after {seconds}s")

def fetch_pairs_from_maping(filter_werks=None, filter_auart=None, limit: int = None):
    """Mengambil pasangan WERKS/AUART dari tabel `maping`. Digunakan oleh Z_FM_YPPR079_SO."""
    db = connect_mysql()
    cur = db.cursor(dictionary=True)
    try:
        sql = "SELECT IV_WERKS, IV_AUART FROM maping"
        cond, args = [], []
        if filter_werks:
            if isinstance(filter_werks, (list, tuple)):
                placeholders = ",".join(["%s"] * len(filter_werks))
                cond.append(f"IV_WERKS IN ({placeholders})")
                args.extend([str(w) for w in filter_werks])
            else:
                cond.append("IV_WERKS = %s")
                args.append(str(filter_werks))

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

# -------------------- DDL & Buffer Logic for Z_FM_YPPR079_SO (SO Sync) --------------------

COMMON_SO_COLS = [
    "IV_WERKS_PARAM", "IV_AUART_PARAM", "VBELN", "POSNR",
    "MANDT", "KUNNR", "NAME1", "AUART", "NETPR", "NETWR",
    "TOTPR", "TOTPR2", "WAERK",
    "EDATU", "WERKS", "BSTNK",
    "KWMENG", "BMENG", "VRKME", "MEINS",
    "MATNR", "MAKTX",
    "KALAB", "KALAB2", "QTY_DELIVERY", "QTY_GI",
    "QTY_BALANCE", "QTY_BALANCE2",
    "MENGX1", "MENGX2", "MENGE",
    "ASSYM", "PAINT", "PACKG",
    "QTYS", "MACHI", "EBDIN",
    "MACHP", "EBDIP",
    "TYPE1", "TYPE2", "TYPE", "DAYX",

    # --- kolom baru (sebelum fetched_at)
    "ASSY", "PAINTM", "PACKGM",
    "PRSM", "PRSM2",
    "QPROM", "QODRM", "QPROA", "QODRA",
    "PRSA",
    "QPROI", "QODRI",
    "PRSI",
    "QPROP", "QODRP",
    "PRSP",
    "PRSC", "PRSAM", "PRSIR",

    # Koreksi & tambahan ke T1:
    "CUTT", "QPROC", "ASSYMT", "QPROAM", "PRIMER", "QPROIR",
    "PAINTMT", "QPROIMT", "QODRIMT", "PRSIMT",
    # ---------------------------------------------

    "fetched_at",
]
SO_COL_LIST = ", ".join(COMMON_SO_COLS)
SO_PLACEHOLDERS = ", ".join(["%s"] * len(COMMON_SO_COLS))
_SO_NON_KEY_UPDATE = [c for c in COMMON_SO_COLS if c not in ("IV_WERKS_PARAM","IV_AUART_PARAM","VBELN","POSNR")]
_SO_SET_CLAUSE = ", ".join([f"{c}=VALUES({c})" for c in _SO_NON_KEY_UPDATE])

# -------------------- Kolom & helper untuk T_DATA4 (SO Detail ringkas) --------------------
# T4 SEKARANG TANPA kolom CUTT/QPROC/ASSYMT/QPROAM/PRIMER/QPROIR (dipindah ke T1)
T4_COLS = [
    "IV_WERKS_PARAM", "IV_AUART_PARAM",
    "MATNR", "MAKTX",
    "PSMNG", "WEMNG", "PRSN", "PRSN2",
    "TOTTP", "TOTREQ",
    "KDAUF", "KDPOS",
    "fetched_at",
]
T4_COL_LIST = ", ".join(T4_COLS)
T4_PLACEHOLDERS = ", ".join(["%s"] * len(T4_COLS))
_T4_NON_KEY_UPDATE = [
    "MAKTX", "PSMNG", "WEMNG", "PRSN", "PRSN2",
    "TOTTP", "TOTREQ",
    "fetched_at",
]
T4_SET_CLAUSE = ", ".join([f"{c}=VALUES({c})" for c in _T4_NON_KEY_UPDATE])

# ==================== PERUBAHAN OPSI A DIMULAI DI SINI ====================
# - T4 tanpa UNIQUE KEY
# - Insert T4 pakai insert biasa (bukan upsert)

def ensure_tables():
    """Memastikan tabel untuk Z_FM_YPPR079_SO ada."""
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

      -- kolom baru (sebelum fetched_at)
      ASSY   DECIMAL(18,3), PAINTM DECIMAL(18,3), PACKGM DECIMAL(18,3),
      PRSM   DECIMAL(18,2),
      PRSM2  DECIMAL(18,2),
      QPROM  DECIMAL(18,3), QODRM DECIMAL(18,3), QPROA DECIMAL(18,3), QODRA DECIMAL(18,3),
      PRSA   DECIMAL(18,2),
      QPROI  DECIMAL(18,3), QODRI DECIMAL(18,3),
      PRSI   DECIMAL(18,2),
      QPROP  DECIMAL(18,3), QODRP DECIMAL(18,3),
      PRSP   DECIMAL(18,2),
      PRSC   DECIMAL(18,2),
      PRSAM  DECIMAL(18,2),
      PRSIR  DECIMAL(18,2),
      CUTT   DECIMAL(18,3),
      QPROC  DECIMAL(18,3),
      ASSYMT DECIMAL(18,3),
      QPROAM DECIMAL(18,3),
      PRIMER DECIMAL(18,3),
      QPROIR DECIMAL(18,3),
      PAINTMT DECIMAL(18,3),
      QPROIMT DECIMAL(18,3),
      QODRIMT DECIMAL(18,3),
      PRSIMT DECIMAL(18,2),

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

      -- kolom baru (sebelum fetched_at)
      ASSY   DECIMAL(18,3), PAINTM DECIMAL(18,3), PACKGM DECIMAL(18,3),
      PRSM   DECIMAL(18,2),
      PRSM2  DECIMAL(18,2),
      QPROM  DECIMAL(18,3), QODRM DECIMAL(18,3), QPROA DECIMAL(18,3), QODRA DECIMAL(18,3),
      PRSA   DECIMAL(18,2),
      QPROI  DECIMAL(18,3), QODRI DECIMAL(18,3),
      PRSI   DECIMAL(18,2),
      QPROP  DECIMAL(18,3), QODRP DECIMAL(18,3),
      PRSP   DECIMAL(18,2),
      PRSC   DECIMAL(18,2),
      PRSAM  DECIMAL(18,2),
      PRSIR  DECIMAL(18,2),
      CUTT   DECIMAL(18,3),
      QPROC  DECIMAL(18,3),
      ASSYMT DECIMAL(18,3),
      QPROAM DECIMAL(18,3),
      PRIMER DECIMAL(18,3),
      QPROIR DECIMAL(18,3),
      PAINTMT DECIMAL(18,3),
      QPROIMT DECIMAL(18,3),
      QODRIMT DECIMAL(18,3),
      PRSIMT DECIMAL(18,2),

      fetched_at DATETIME NOT NULL,
      UNIQUE KEY uq_t3 (IV_WERKS_PARAM, IV_AUART_PARAM, VBELN, POSNR)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """
    db = connect_mysql()
    cur = db.cursor()
    try:
        cur.execute(ddl1)
        cur.execute(ddl2)
        cur.execute(ddl2_fix)
        cur.execute(ddl3)

        # Pastikan KALAB2 ada (kompatibilitas lama)
        for _tbl in ("so_yppr079_t1", "so_yppr079_t2", "so_yppr079_t3"):
            try:
                cur.execute(f"ALTER TABLE {_tbl} ADD COLUMN KALAB2 DECIMAL(18,3) AFTER KALAB")
            except Exception:
                pass

        # Buang index unik t1 jika ada (disamakan dengan skema awal Anda)
        try:
            cur.execute("ALTER TABLE so_yppr079_t1 DROP INDEX uq_t1")
        except Exception:
            pass

        # Tambah unique key t2 (meniru skema awal)
        try:
            cur.execute("""
                ALTER TABLE so_yppr079_t2
                ADD UNIQUE KEY uq_t2 (IV_WERKS_PARAM, IV_AUART_PARAM, VBELN, POSNR)
            """)
        except Exception:
            pass

        # Tambah kolom-kolom baru bila belum ada, dengan urutan tepat sebelum fetched_at
        new_cols = [
            ("ASSY",   "DECIMAL(18,3)"),
            ("PAINTM", "DECIMAL(18,3)"),
            ("PACKGM", "DECIMAL(18,3)"),
            ("PRSM",   "DECIMAL(18,2)"),
            ("PRSM2",  "DECIMAL(18,2)"),
            ("QPROM",  "DECIMAL(18,3)"),
            ("QODRM",  "DECIMAL(18,3)"),
            ("QPROA",  "DECIMAL(18,3)"),
            ("QODRA",  "DECIMAL(18,3)"),
            ("PRSA",   "DECIMAL(18,2)"),
            ("QPROI",  "DECIMAL(18,3)"),
            ("QODRI",  "DECIMAL(18,3)"),
            ("PRSI",   "DECIMAL(18,2)"),
            ("QPROP",  "DECIMAL(18,3)"),
            ("QODRP",  "DECIMAL(18,3)"),
            ("PRSP",   "DECIMAL(18,2)"),
            ("PRSC",   "DECIMAL(18,2)"),
            ("PRSAM",  "DECIMAL(18,2)"),
            ("PRSIR",  "DECIMAL(18,2)"),
            ("CUTT",   "DECIMAL(18,3)"),
            ("QPROC",  "DECIMAL(18,3)"),
            ("ASSYMT", "DECIMAL(18,3)"),
            ("QPROAM", "DECIMAL(18,3)"),
            ("PRIMER", "DECIMAL(18,3)"),
            ("QPROIR", "DECIMAL(18,3)"),
            ("PAINTMT","DECIMAL(18,3)"),
            ("QPROIMT","DECIMAL(18,3)"),
            ("QODRIMT","DECIMAL(18,3)"),
            ("PRSIMT", "DECIMAL(18,2)"),
        ]
        # urutkan mulai AFTER DAYX supaya berakhir tepat sebelum fetched_at
        for _tbl in ("so_yppr079_t1", "so_yppr079_t2", "so_yppr079_t3"):
            after_col = "DAYX"
            for col_name, col_type in new_cols:
                try:
                    cur.execute(
                        f"ALTER TABLE {_tbl} ADD COLUMN {col_name} {col_type} AFTER {after_col}"
                    )
                except Exception:
                    pass  # kolom mungkin sudah ada
                after_col = col_name

        # Khusus T1: pastikan PRSM2 ada (DB lama)
        try:
            cur.execute("ALTER TABLE so_yppr079_t1 ADD COLUMN PRSM2 DECIMAL(18,2) AFTER PRSM")
        except Exception:
            pass

        # -------------------- DDL untuk T4 (TANPA UNIQUE) --------------------
        ddl4 = """
        CREATE TABLE IF NOT EXISTS so_yppr079_t4 (
          id BIGINT AUTO_INCREMENT PRIMARY KEY,
          IV_WERKS_PARAM VARCHAR(10) NOT NULL,
          IV_AUART_PARAM VARCHAR(10) NOT NULL,
          MATNR VARCHAR(40),
          MAKTX VARCHAR(200),
          PSMNG DECIMAL(18,3),
          WEMNG DECIMAL(18,3),
          PRSN  DECIMAL(18,2),
          PRSN2 DECIMAL(18,2),
          TOTTP DECIMAL(18,3),
          TOTREQ DECIMAL(18,3),
          KDAUF VARCHAR(20),
          KDPOS VARCHAR(10),
          fetched_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        """
        cur.execute(ddl4)

        # Pastikan index unik lama di-drop bila masih ada
        try:
            cur.execute("ALTER TABLE so_yppr079_t4 DROP INDEX uq_t4")
        except Exception:
            pass

        # Pastikan kolom PRSN2 ada (untuk DB lama)
        try:
            cur.execute("ALTER TABLE so_yppr079_t4 ADD COLUMN PRSN2 DECIMAL(18,2) AFTER PRSN")
        except Exception:
            pass

        # Pastikan kolom TOTTP/TOTREQ ada (untuk DB lama)
        try:
            cur.execute("ALTER TABLE so_yppr079_t4 ADD COLUMN TOTTP DECIMAL(18,3) AFTER PRSN2")
        except Exception:
            pass
        try:
            cur.execute("ALTER TABLE so_yppr079_t4 ADD COLUMN TOTREQ DECIMAL(18,3) AFTER TOTTP")
        except Exception:
            pass

        # (Tidak ada lagi kolom CUTT/QPROC/... di T4)

        # Index non-unik opsional untuk performa query
        try:
            cur.execute("CREATE INDEX idx_t4_orderpos ON so_yppr079_t4 (KDAUF, KDPOS, MATNR)")
        except Exception:
            pass

        db.commit()
    finally:
        cur.close(); db.close()

# ==================== PERUBAHAN OPSI A SELESAI ====================

def _so_row_params(werks: str, auart: str, r: Dict[str, Any], now: datetime.datetime) -> tuple:
    """Bangun 1 tuple parameter insert sesuai kolom COMMON_SO_COLS."""
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

        # --- kolom baru ---
        fnum(r.get("ASSY")),
        fnum(r.get("PAINTM")),
        fnum(r.get("PACKGM")),
        fnum(r.get("PRSM")), fnum(r.get("PRSM2")),
        fnum(r.get("QPROM")), fnum(r.get("QODRM")), fnum(r.get("QPROA")), fnum(r.get("QODRA")),
        fnum(r.get("PRSA")),
        fnum(r.get("QPROI")), fnum(r.get("QODRI")),
        fnum(r.get("PRSI")),
        fnum(r.get("QPROP")), fnum(r.get("QODRP")),
        fnum(r.get("PRSP")),
        fnum(r.get("PRSC")), fnum(r.get("PRSAM")), fnum(r.get("PRSIR")),
        fnum(r.get("CUTT")), fnum(r.get("QPROC")), fnum(r.get("ASSYMT")), fnum(r.get("QPROAM")), fnum(r.get("PRIMER")), fnum(r.get("QPROIR")),
        fnum(r.get("PAINTMT")), fnum(r.get("QPROIMT")), fnum(r.get("QODRIMT")), fnum(r.get("PRSIMT")),
        # -------------------

        now,
    )

def _bulk_insert_so_upsert(cur, table: str, params: list, batch_size: int = 1000):
    """Bulk insert dengan ON DUPLICATE KEY UPDATE untuk T2/T3."""
    if not params: return 0
    sql = (
        f"INSERT INTO {table} ({SO_COL_LIST}) VALUES ({SO_PLACEHOLDERS}) "
        f"ON DUPLICATE KEY UPDATE {_SO_SET_CLAUSE}"
    )
    total = 0
    for i in range(0, len(params), batch_size):
        chunk = params[i:i+batch_size]
        cur.executemany(sql, chunk)
        total += len(chunk)
    return total

def _bulk_insert_so_plain(cur, table: str, params: list, batch_size: int = 1000):
    """Bulk insert tanpa upsert (dipakai untuk T1/T4 setelah TRUNCATE)."""
    if not params: return 0
    sql = f"INSERT INTO {table} ({SO_COL_LIST}) VALUES ({SO_PLACEHOLDERS})"
    total = 0
    for i in range(0, len(params), batch_size):
        chunk = params[i:i+batch_size]
        cur.executemany(sql, chunk)
        total += len(chunk)
    return total

# -------------------- Helper untuk T_DATA4 --------------------

def _so_t4_row_params(werks: str, auart: str, r: Dict[str, Any], now: datetime.datetime) -> tuple:
    """Bangun 1 tuple parameter insert untuk T4_COLS (SO detail ringkas)."""
    kdauf = r.get("KDAUF")
    kdpos = r.get("KDPOS")
    return (
        werks, auart,
        r.get("MATNR"),
        r.get("MAKTX"),
        fnum(r.get("PSMNG")), fnum(r.get("WEMNG")), fnum(r.get("PRSN")), fnum(r.get("PRSN2")),
        fnum(r.get("TOTTP")), fnum(r.get("TOTREQ")),
        kdauf, (str(kdpos) if kdpos is not None else None),
        now,
    )

def _bulk_insert_t4_upsert(cur, table: str, params: list, batch_size: int = 1000):
    """(TIDAK DIGUNAKAN DI OPSI A) Bulk insert untuk T4 dengan ON DUPLICATE KEY UPDATE."""
    if not params: return 0
    sql = (
        f"INSERT INTO {table} ({T4_COL_LIST}) VALUES ({T4_PLACEHOLDERS}) "
        f"ON DUPLICATE KEY UPDATE {T4_SET_CLAUSE}"
    )
    total = 0
    for i in range(0, len(params), batch_size):
        chunk = params[i:i+batch_size]
        cur.executemany(sql, chunk)
        total += len(chunk)
    return total

# -------------------- DDL & Buffer Logic for Z_FM_YSDR048 (Stock Sync) --------------------

STOCK_COLS = [
    "NAME1", "BSTNK", "VBELN", "POSNR",
    "MATNH", "MAKTXH", "MATNR", "MAKTX",
    "MATNRX", "IDNRK", "STOCK3", "MEINS",
    "STATS", "BUDAT", "PSMNG", "WEMNG",
    "NETPR", "TPRC", "TTIME",
]
STOCK_COL_LIST = ", ".join(STOCK_COLS + ["IV_PARAM", "fetched_at"])
STOCK_PLACEHOLDERS = ", ".join(["%s"] * (len(STOCK_COLS) + 2))

def ensure_stock_tables():
    """Memastikan tabel untuk Z_FM_YSDR048 ada (termasuk stock_ptg_m)."""
    base_ddl = """
    CREATE TABLE IF NOT EXISTS {table_name} (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      IV_PARAM VARCHAR(10) NOT NULL,
      NAME1 VARCHAR(120), BSTNK VARCHAR(80), VBELN VARCHAR(20), POSNR VARCHAR(10),
      MATNH VARCHAR(40), MAKTXH VARCHAR(200), MATNR VARCHAR(40), MAKTX VARCHAR(200),
      MATNRX VARCHAR(40), IDNRK VARCHAR(40), STOCK3 DECIMAL(18,3), MEINS VARCHAR(6),
      STATS VARCHAR(25), BUDAT DATE, PSMNG DECIMAL(18,3), WEMNG DECIMAL(18,3),
      NETPR DECIMAL(18,2), TPRC DECIMAL(18,2), TTIME VARCHAR(25),
      fetched_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """

    table_names = list(STOCK_TABLE_MAP.values())

    db = connect_mysql()
    cur = db.cursor()
    try:
        for name in table_names:
            ddl = base_ddl.format(table_name=name)
            cur.execute(ddl)

        for name in table_names:
            try:
                cur.execute(f"ALTER TABLE {name} ADD INDEX idx_vbeln (VBELN)")
                cur.execute(f"ALTER TABLE {name} ADD INDEX idx_matnr (MATNR)")
            except Exception:
                pass

        db.commit()
    finally:
        cur.close(); db.close()

def _stock_row_params(param: str, r: Dict[str, Any], now: datetime.datetime) -> tuple:
    """Bangun 1 tuple parameter insert sesuai kolom STOCK_COLS."""
    posnr = str(r.get("POSNR")).zfill(4) if r.get("POSNR") is not None else None

    return (
        r.get("NAME1"), r.get("BSTNK"), r.get("VBELN"), posnr,
        r.get("MATNH"), r.get("MAKTXH"), r.get("MATNR"), r.get("MAKTX"),
        r.get("MATNRX"), r.get("IDNRK"), fnum(r.get("STOCK3")), r.get("MEINS"),
        r.get("STATS"), fdate_yyyymmdd(r.get("BUDAT")), fnum(r.get("PSMNG")), fnum(r.get("WEMNG")),
        fnum(r.get("NETPR")), fnum(r.get("TPRC")), r.get("TTIME"),
        param,
        now,
    )

def _bulk_insert_stock_plain(cur, table: str, params: list, batch_size: int = 1000):
    """Bulk insert tanpa upsert (dipakai untuk tabel stok setelah TRUNCATE)."""
    if not params: return 0
    sql = f"INSERT INTO {table} ({STOCK_COL_LIST}) VALUES ({STOCK_PLACEHOLDERS})"
    total = 0
    for i in range(0, len(params), batch_size):
        chunk = params[i:i+batch_size]
        cur.executemany(sql, chunk)
        total += len(chunk)
    return total

# -------------------- LOGIKA SYNC LAMA (Z_FM_YPPR079_SO) --------------------

def do_sync(filter_werks=None, filter_auart=None, limit: Optional[int] = None, timeout_sec: int = 3000):
    """Logika sinkronisasi untuk Z_FM_YPPR079_SO (T_DATA1/2/3/4)."""
    ensure_tables()
    username, password = get_sap_credentials_from_env()
    if not username or not password:
        return {"ok": False, "error": "Missing SAP_USERNAME or SAP_PASSWORD."}

    start_ts = datetime.datetime.now()
    print(f"[INFO] Start do_sync SO at {start_ts:%Y-%m-%d %H:%M:%S}", flush=True)

    pairs = fetch_pairs_from_maping(filter_werks, filter_auart, limit)
    total = len(pairs)
    print(f"[INFO] Total pairs: {total}", flush=True)
    if not pairs:
        return {"ok": False, "error": "No mapping pairs found for given filter.", "pairs": []}

    try:
        print("[INFO] Connecting to SAP (with fallback if needed)...", flush=True)
        sap, used_user = connect_sap_with_fallback(username, password)
        print(f"[INFO] Using SAP user '{used_user}' for RFC {RFC_NAME_SO}.", flush=True)
    except Exception as e:
        print(f"[ERROR] SAP connection failed: {e}", flush=True)
        return {"ok": False, "error": f"SAP connection failed: {e}", "pairs": []}


    t1_buf: List[tuple] = []
    t2_buf: List[tuple] = []
    t3_buf: List[tuple] = []
    t4_buf: List[tuple] = []
    total_t1 = total_t2 = total_t3 = 0
    total_t4 = 0
    summary: List[Dict[str, Any]] = []

    try:
        for idx, p in enumerate(pairs, 1):
            werks = str(p["IV_WERKS"]); auart = str(p["IV_AUART"])
            try:
                print(f"[{idx}/{total}] {werks}/{auart} - calling RFC {RFC_NAME_SO}...", flush=True)
                r = call_rfc_with_timeout(sap, timeout_sec, RFC_NAME_SO, IV_WERKS=werks, IV_AUART=auart)

                t1 = r.get("T_DATA1", []) or []; t2 = r.get("T_DATA2", []) or []; t3 = r.get("T_DATA3", []) or []
                t4 = r.get("T_DATA4", []) or []
                now = datetime.datetime.now()
                t1_buf.extend(_so_row_params(werks, auart, row, now) for row in t1)
                t2_buf.extend(_so_row_params(werks, auart, row, now) for row in t2)
                t3_buf.extend(_so_row_params(werks, auart, row, now) for row in t3)
                t4_buf.extend(_so_t4_row_params(werks, auart, row, now) for row in t4)
                total_t1 += len(t1); total_t2 += len(t2); total_t3 += len(t3); total_t4 += len(t4)
                summary.append({
                    "werks": werks, "auart": auart,
                    "t1_saved": len(t1), "t2_saved": len(t2), "t3_saved": len(t3),
                    "t4_saved": len(t4)
                })
                print(f"[{idx}/{total}] {werks}/{auart} - saved t1={len(t1)} t2={len(t2)} t3={len(t3)} t4={len(t4)} to array", flush=True)

            except (FuturesTimeout, TimeoutError) as te:
                print(f"[{idx}/{total}] {werks}/{auart} - TIMEOUT: {te}", flush=True)
                summary.append({"werks": werks, "auart": auart, "error": f"timeout: {te}"})
            except Exception as e:
                print(f"[{idx}/{total}] {werks}/{auart} - ERROR: {e}", flush=True)
                summary.append({"werks": werks, "auart": auart, "error": f"RFC/parse error: {e}"})
    finally:
        try: sap.close()
        except Exception: pass

    print("Semua pair telah tersimpan ke buffer.", flush=True)
    db  = connect_mysql(); cur = db.cursor()
    inserted_t1 = inserted_t2 = inserted_t3 = inserted_t4 = 0
    try:
        print("Menghapus tabel SO lama (TRUNCATE)…", flush=True)
        cur.execute("SET FOREIGN_KEY_CHECKS=0")
        cur.execute("TRUNCATE TABLE so_yppr079_t1")
        cur.execute("TRUNCATE TABLE so_yppr079_t2")
        cur.execute("TRUNCATE TABLE so_yppr079_t3")
        cur.execute("TRUNCATE TABLE so_yppr079_t4")
        cur.execute("SET FOREIGN_KEY_CHECKS=1")
        print("Memasukkan query SQL buffered ke database…", flush=True)
        inserted_t1 = _bulk_insert_so_plain(cur, "so_yppr079_t1", t1_buf)
        inserted_t2 = _bulk_insert_so_upsert(cur, "so_yppr079_t2", t2_buf)
        inserted_t3 = _bulk_insert_so_upsert(cur, "so_yppr079_t3", t3_buf)
        # ==================== PERUBAHAN OPSI A: insert biasa untuk T4 ====================
        inserted_t4 = 0
        if t4_buf:
            sql_t4 = f"INSERT INTO so_yppr079_t4 ({T4_COL_LIST}) VALUES ({T4_PLACEHOLDERS})"
            for i in range(0, len(t4_buf), 1000):
                chunk = t4_buf[i:i+1000]
                cur.executemany(sql_t4, chunk)
                inserted_t4 += len(chunk)
        # =============================================================================
        db.commit()

        # Verifikasi jumlah riil di tabel (khusus T4)
        cur.execute("SELECT COUNT(*) FROM so_yppr079_t4")
        real_t4 = cur.fetchone()[0]
        print(f"[INFO] Inserted buffered rows: t1={inserted_t1} t2={inserted_t2} t3={inserted_t3} t4={inserted_t4}", flush=True)
        print(f"[INFO] Actually in table t4={real_t4} rows (buffer={len(t4_buf)})", flush=True)
    except Exception as e:
        db.rollback(); print(f"[ERROR] Gagal menulis buffered data: {e}", flush=True)
        return {"ok": False, "error": f"DB insert failed: {e}", "summary": summary}
    finally:
        try: cur.close(); db.close()
        except Exception: pass

    done_ts = datetime.datetime.now()
    print(f"[INFO] Done SO sync. totals: t1={total_t1} t2={total_t2} t3={total_t3} t4={total_t4} @ {done_ts:%Y-%m-%d %H:%M:%S}", flush=True)

    return {
        "ok": True,
        "sap_user_used": used_user,
        "totals": {"t1": total_t1, "t2": total_t2, "t3": total_t3, "t4": total_t4},
        "inserted": {"t1": inserted_t1, "t2": inserted_t2, "t3": inserted_t3, "t4": inserted_t4},
        "pairs": summary, "started_at": start_ts.isoformat(), "finished_at": done_ts.isoformat(),
    }

# -------------------- LOGIKA SYNC BARU (Z_FM_YSDR048) --------------------

def do_sync_stock(timeout_sec: int = 3000):
    """Menjalankan Z_FM_YSDR048 4x dan menyimpan hasilnya ke tabel stock_assy, stock_ptg, stock_pkg, dan stock_ptg_m."""
    ensure_stock_tables()

    username, password = get_sap_credentials_from_env()
    if not username or not password:
        return {"ok": False, "error": "Missing SAP_USERNAME or SAP_PASSWORD."}

    start_ts = datetime.datetime.now()
    print(f"[INFO] Start do_sync_stock at {start_ts:%Y-%m-%d %H:%M:%S}", flush=True)
    print(f"[INFO] Using RFC: {RFC_NAME_STOCK}", flush=True)

    try:
        print("[INFO] Connecting to SAP (with fallback if needed)...", flush=True)
        sap, used_user = connect_sap_with_fallback(username, password)
        print(f"[INFO] Using SAP user '{used_user}' for RFC {RFC_NAME_STOCK}.", flush=True)
    except Exception as e:
        print(f"[ERROR] SAP connection failed: {e}", flush=True)
        return {"ok": False, "error": f"SAP connection failed: {e}", "summary": []}


    # 🌟 PARAMETER SYNC DIPERBARUI (IV_SIPM Ditambahkan)
    sync_params = {"IV_SIAD": "X", "IV_SIPD": "X", "IV_SIPP": "X", "IV_SIPM": "X"}

    buffers: Dict[str, List[tuple]] = {}; summary: List[Dict[str, Any]] = []

    try:
        for param_name, param_value in sync_params.items():
            now = datetime.datetime.now()
            table_name = STOCK_TABLE_MAP[param_name]
            buffers[table_name] = []

            params = {"IV_SIAD": "", "IV_SIPD": "", "IV_SIPP": "", "IV_SIPM": ""}
            params[param_name] = param_value

            try:
                print(f"[INFO] Calling RFC for {param_name}='{param_value}' -> {table_name}...", flush=True)
                r = call_rfc_with_timeout(sap, timeout_sec, RFC_NAME_STOCK, **params)
                t1_data = r.get("T_DATA1", []) or []
                buffers[table_name].extend(_stock_row_params(param_name, row, now) for row in t1_data)
                summary.append({"param": param_name, "table": table_name, "rows_fetched": len(t1_data)})
                print(f"[INFO] {param_name}='{param_value}' - fetched {len(t1_data)} rows to array", flush=True)

            except (FuturesTimeout, TimeoutError) as te:
                print(f"[ERROR] {param_name}='{param_value}' - TIMEOUT: {te}", flush=True)
                summary.append({"param": param_name, "error": f"timeout: {te}"})
            except Exception as e:
                print(f"[ERROR] {param_name}='{param_value}' - ERROR: {e}", flush=True)
                summary.append({"param": param_name, "error": f"RFC/parse error: {e}"})
    finally:
        try: sap.close()
        except Exception: pass

    print("Semua panggilan RFC telah tersimpan ke buffer.", flush=True)

    db  = connect_mysql(); cur = db.cursor()
    inserted_counts = {}
    try:
        print("Menghapus tabel stok lama (TRUNCATE)…", flush=True)
        cur.execute("SET FOREIGN_KEY_CHECKS=0")

        for table_name in buffers.keys():
            cur.execute(f"TRUNCATE TABLE {table_name}")

        cur.execute("SET FOREIGN_KEY_CHECKS=1")

        print("Memasukkan query SQL buffered ke database…", flush=True)

        for table_name, buffer in buffers.items():
            inserted_count = _bulk_insert_stock_plain(cur, table_name, buffer)
            inserted_counts[table_name] = inserted_count
            print(f"[INFO] Inserted {inserted_count} rows into {table_name}", flush=True)

        db.commit()
        print(f"[INFO] Inserted buffered rows: {inserted_counts}", flush=True)
    except Exception as e:
        db.rollback(); print(f"[ERROR] Gagal menulis buffered data: {e}", flush=True)
        return {"ok": False, "error": f"DB insert failed: {e}", "summary": summary}
    finally:
        try: cur.close(); db.close()
        except Exception: pass

    done_ts = datetime.datetime.now()
    print(f"[INFO] Done Stock sync. totals: {inserted_counts} @ {done_ts:%Y-%m-%d %H:%M:%S}", flush=True)

    return {
        "ok": True,
        "sap_user_used": used_user,
        "inserted": inserted_counts,
        "summary": summary,
        "started_at": start_ts.isoformat(),
        "finished_at": done_ts.isoformat(),
    }

# ---------- Endpoints (opsional) ----------
@app.route("/", methods=["GET"])
def home():
    return (
        "OK. POST /api/yppr079/sync (SO) atau /api/ysdr048/sync (Stock). "
        "Headers (opsional): X-SAP-Username / X-SAP-Password; ENV juga didukung.",
        200, {"Content-Type": "text/plain"}
    )

@app.route("/api/yppr079/sync", methods=["POST"])
def sync_yppr079_http():
    ensure_tables()
    u, p = get_sap_credentials_from_headers()
    filter_werks = request.args.get("werks"); filter_auart = request.args.get("auart")
    limit_param  = request.args.get("limit", type=int); timeout_sec  = request.args.get("timeout", default=9000, type=int)

    os.environ["SAP_USERNAME"] = u; os.environ["SAP_PASSWORD"] = p
    result = do_sync(filter_werks, filter_auart, limit_param, timeout_sec)
    return app.response_class(
        response=json.dumps(to_jsonable(result), ensure_ascii=False),
        status=(200 if result.get("ok") else 500), mimetype="application/json",
    )

@app.route("/api/ysdr048/sync", methods=["POST"])
def sync_ysdr048_http():
    """Endpoint HTTP untuk sinkronisasi Stock (Z_FM_YSDR048)."""
    ensure_stock_tables()
    u, p = get_sap_credentials_from_headers()
    timeout_sec  = request.args.get("timeout", default=9000, type=int)

    os.environ["SAP_USERNAME"] = u; os.environ["SAP_PASSWORD"] = p
    result = do_sync_stock(timeout_sec)
    return app.response_class(
        response=json.dumps(to_jsonable(result), ensure_ascii=False),
        status=(200 if result.get("ok") else 500), mimetype="application/json",
    )

# ---------- Main ----------
if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser(description="YPPR079/YSDR048 API/Sync")
    parser.add_argument("--serve", action="store_true", help="Jalankan server Flask")

    parser.add_argument("--sync", action="store_true", help="Jalankan sinkronisasi SO Z_FM_YPPR079_SO (tanpa HTTP)")
    parser.add_argument("--werks", type=str, help="Filter IV_WERKS (untuk --sync)")
    parser.add_argument("--auart", nargs="+", help="Filter IV_AUART (untuk --sync)")
    parser.add_argument("--limit", type=int, help="Batas jumlah pair (untuk --sync)")

    parser.add_argument("--sync_stock", action="store_true", help="Jalankan sinkronisasi Stock Z_FM_YSDR048 (tanpa HTTP)")

    parser.add_argument("--timeout", type=int, default=9000, help="Timeout per panggilan RFC (detik)")
    args = parser.parse_args()

    if args.sync:
        result = do_sync(
            filter_werks=args.werks,
            filter_auart=args.auart,
            limit=args.limit,
            timeout_sec=args.timeout
        )
        print(json.dumps(to_jsonable(result), ensure_ascii=False, indent=2))
    elif args.sync_stock:
        result = do_sync_stock(
            timeout_sec=args.timeout
        )
        print(json.dumps(to_jsonable(result), ensure_ascii=False, indent=2))
    elif args.serve:
        app.run(host="127.0.0.1", port=5000, debug=True)
    else:
        parser.print_help()
        sys.exit(1)
