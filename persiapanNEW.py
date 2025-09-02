# -*- coding: utf-8 -*-
"""
API/CLI untuk memanggil Z_FM_YPPR079_SO dan menyimpan T_DATA1/2/3 ke MySQL.

Mode CLI (tanpa HTTP):
  python api.py --sync --werks 2000 --auart ZOR3 --timeout 3000
  python api.py --sync --werks 2000
  python api.py --sync --limit 5
  python api.py --sync                # semua pair di tabel `maping`

Mode server (opsional):
  python api.py --serve
"""

import os, json, decimal, datetime
from typing import Any, Dict, List, Tuple

from flask import Flask, request
import mysql.connector
from pyrfc import Connection
from concurrent.futures import ThreadPoolExecutor, TimeoutError as FuturesTimeout

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
    "database": os.environ.get("DB_NAME", "oso_yppr"),
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
    return u, p

def get_sap_credentials_from_env():
    return (
        os.getenv("SAP_USERNAME", "auto_email"),
        os.getenv("SAP_PASSWORD", "11223344"),
    )

def to_jsonable(obj: Any) -> Any:
    """Konversi aman supaya semua tipe bisa di-JSON-kan."""
    if obj is None: return None
    if isinstance(obj, (str, int, float, bool)): return obj
    if isinstance(obj, (bytes, bytearray)):
        try:
            return obj.decode("utf-8", errors="replace")
        except Exception:
            return str(obj)
    if isinstance(obj, decimal.Decimal): return float(obj)
    if isinstance(obj, (datetime.date, datetime.datetime)): return obj.isoformat()
    if isinstance(obj, dict): return {k: to_jsonable(v) for k, v in obj.items()}
    if isinstance(obj, (list, tuple, set)): return [to_jsonable(x) for x in obj]
    try:
        return str(obj)
    except Exception:
        return repr(obj)

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
      KALAB DECIMAL(18,3), QTY_DELIVERY DECIMAL(18,3),
      QTY_GI DECIMAL(18,3), QTY_BALANCE DECIMAL(18,3), QTY_BALANCE2 DECIMAL(18,3),
      MENGX1 DECIMAL(18,3), MENGX2 DECIMAL(18,3), MENGE DECIMAL(18,3),
      ASSYM DECIMAL(18,3), PAINT DECIMAL(18,3), PACKG DECIMAL(18,3),
      QTYS DECIMAL(18,3), MACHI DECIMAL(18,3), EBDIN DECIMAL(18,3),
      MACHP DECIMAL(18,3), EBDIP DECIMAL(18,3),
      TYPE1 VARCHAR(25), TYPE2 VARCHAR(25), TYPE VARCHAR(25), DAYX INT,
      fetched_at DATETIME NOT NULL,
      UNIQUE KEY uq_t1 (IV_WERKS_PARAM, IV_AUART_PARAM, VBELN, POSNR)
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
      VBELN VARCHAR(20) NULL,
      POSNR VARCHAR(10) NULL,
      payload_json JSON NOT NULL,
      fetched_at DATETIME NOT NULL,
      KEY k_t3_pair (IV_WERKS_PARAM, IV_AUART_PARAM),
      KEY k_t3_vbeln (VBELN, POSNR)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """
    db = connect_mysql()
    cur = db.cursor()
    try:
        cur.execute(ddl1)
        cur.execute(ddl2)
        cur.execute(ddl2_fix)
        cur.execute(ddl3)
        db.commit()
    finally:
        cur.close(); db.close()

# ---------- UPSERT ----------
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
        "KALAB", "QTY_DELIVERY", "QTY_GI", "QTY_BALANCE", "QTY_BALANCE2",
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

    def param_tuple(r: Dict[str, Any]):
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
            fnum(r.get("KALAB")), fnum(r.get("QTY_DELIVERY")), fnum(r.get("QTY_GI")),
            fnum(r.get("QTY_BALANCE")), fnum(r.get("QTY_BALANCE2")),
            fnum(r.get("MENGX1")), fnum(r.get("MENGX2")), fnum(r.get("MENGE")),
            fnum(r.get("ASSYM")), fnum(r.get("PAINT")), fnum(r.get("PACKG")),
            fnum(r.get("QTYS")), fnum(r.get("MACHI")), fnum(r.get("EBDIN")),
            fnum(r.get("MACHP")), fnum(r.get("EBDIP")),
            r.get("TYPE1"), r.get("TYPE2"), r.get("TYPE"),
            int(r.get("DAYX") or 0),
            now,
        )

    batch = [param_tuple(dict(r)) for r in (rows or [])]
    if batch:
        cur.executemany(sql, batch)

def upsert_t3(cur, werks, auart, rows):
    """Simpan payload JSON T3 (sudah dijamin JSON-serializable)."""
    sql = """
    INSERT INTO so_yppr079_t3(IV_WERKS_PARAM, IV_AUART_PARAM, VBELN, POSNR, payload_json, fetched_at)
    VALUES (%s,%s,%s,%s,%s,%s)
    """
    now = datetime.datetime.now()
    batch = []
    for r in (rows or []):
        if not isinstance(r, dict):
            try:
                r = dict(r)
            except Exception:
                r = {"value": to_jsonable(r)}
        vbeln = r.get("VBELN")
        posnr = str(r.get("POSNR")) if r.get("POSNR") is not None else None
        payload = json.dumps(to_jsonable(r), ensure_ascii=False)
        batch.append((werks, auart, vbeln, posnr, payload, now))
    if batch:
        cur.executemany(sql, batch)

# ---------- Enrichment T3 ----------
EXPECTED_KEYS = [
    "MANDT","KUNNR","NAME1","AUART","VBELN","POSNR","NETPR","NETWR",
    "TOTPR","TOTPR2","WAERK","EDATU","WERKS","BSTNK","KWMENG","BMENG",
    "VRKME","MEINS","MATNR","MAKTX","KALAB","QTY_DELIVERY","QTY_GI",
    "QTY_BALANCE","QTY_BALANCE2","MENGX1","MENGX2","MENGE","ASSYM",
    "PAINT","PACKG","QTYS","MACHI","EBDIN","MACHP","EBDIP","TYPE1",
    "TYPE2","TYPE","DAYX"
]

def key_of(row: Dict[str, Any]) -> Tuple[str, str]:
    return (str(row.get("VBELN") or ""), str(row.get("POSNR") or ""))

def build_index(rows: List[Dict[str, Any]]) -> Dict[Tuple[str, str], Dict[str, Any]]:
    idx = {}
    for r in rows or []:
        try:
            idx[key_of(r)] = dict(r)
        except Exception:
            pass
    return idx

def merge_rows(base: Dict[str, Any], override: Dict[str, Any]) -> Dict[str, Any]:
    """Gabungkan base (mis. T1) dengan override (mis. T3). Override menang."""
    merged = dict(base or {})
    merged.update(override or {})
    # Normalisasi numeric & tanggal agar konsisten seperti contoh
    def norm(d: Dict[str, Any]):
        for k in ["NETPR","NETWR","TOTPR","TOTPR2","KWMENG","BMENG","KALAB",
                  "QTY_DELIVERY","QTY_GI","QTY_BALANCE","QTY_BALANCE2",
                  "MENGX1","MENGX2","MENGE","ASSYM","PAINT","PACKG",
                  "QTYS","MACHI","EBDIN","MACHP","EBDIP"]:
            if k in d:
                d[k] = fnum(d[k])
        if "EDATU" in d and d["EDATU"]:
            # simpan string YYYYMMDD seperti sample; kalau base date, ubah
            if isinstance(d["EDATU"], (datetime.date, datetime.datetime)):
                d["EDATU"] = d["EDATU"].strftime("%Y%m%d")
            else:
                s = str(d["EDATU"])
                if len(s) == 10 and "-" in s:  # 'YYYY-MM-DD'
                    try:
                        d["EDATU"] = datetime.datetime.strptime(s, "%Y-%m-%d").strftime("%Y%m%d")
                    except: pass
        # pastikan POSNR string 6 digit seperti SAP
        if "POSNR" in d and d["POSNR"] is not None:
            try:
                d["POSNR"] = str(d["POSNR"]).zfill(6)
            except:
                d["POSNR"] = str(d["POSNR"])
        return d
    merged = norm(merged)
    # Tambahkan key kosong agar "lengkap"
    for k in EXPECTED_KEYS:
        merged.setdefault(k, "" if k in ("MANDT","TYPE1","TYPE2","TYPE") else None)
    return merged

def enrich_t3(t1_rows: List[Dict[str, Any]],
              t2_rows: List[Dict[str, Any]],
              t3_rows: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """
    - Jika T3 ada: merge setiap baris T3 dengan T1 (jika key sama) untuk melengkapi field.
    - Jika T3 kosong: fallback dari T1 (kalau T1 kosong, pakai T2).
    """
    idx_t1 = build_index(t1_rows)
    enriched: List[Dict[str, Any]] = []

    if t3_rows:
        for r3 in t3_rows:
            base = idx_t1.get(key_of(r3), {})
            enriched.append(merge_rows(base, dict(r3)))
        return enriched

    # Fallback: bentuk dari T1 dulu; jika T1 kosong, dari T2
    source = t1_rows if t1_rows else t2_rows
    for src in source or []:
        enriched.append(merge_rows(dict(src), {}))
    return enriched

# ---------- LOGIKA SYNC (bisa dipakai HTTP/CLI) ----------
def do_sync(filter_werks=None, filter_auart=None, limit=None, timeout_sec=300):
    ensure_tables()

    username, password = get_sap_credentials_from_env()  # CLI pakai ENV / default
    pairs = fetch_pairs_from_maping(filter_werks, filter_auart, limit)
    if not pairs:
        return {"ok": False, "error": "No mapping pairs found for given filter.", "pairs": []}

    try:
        sap = connect_sap(username, password)
    except Exception as e:
        return {"ok": False, "error": f"SAP connection failed: {e}", "pairs": []}

    db = connect_mysql()
    cur = db.cursor()

    summary = []
    total_t1 = total_t2 = total_t3 = 0

    try:
        for p in pairs:
            werks = str(p["IV_WERKS"])
            auart = str(p["IV_AUART"])
            try:
                r = call_rfc_with_timeout(
                    sap, timeout_sec, RFC_NAME,
                    IV_WERKS=werks, IV_AUART=auart
                )

                # Baca semua variasi nama untuk jaga2
                t1 = (r.get("T_DATA1") or [])
                t2 = (r.get("T_DATA2") or [])
                t3_raw = (r.get("T_DATA3") or r.get("T_DATA_3") or r.get("TDATA3") or [])

                # Simpan T1 & T2 apa adanya
                upsert_generic(cur, "so_yppr079_t1", werks, auart, t1)
                upsert_generic(cur, "so_yppr079_t2", werks, auart, t2)

                # Enrichment + upsert T3
                t3_enriched = enrich_t3(t1, t2, t3_raw)
                upsert_t3(cur, werks, auart, t3_enriched)
                db.commit()

                total_t1 += len(t1); total_t2 += len(t2); total_t3 += len(t3_enriched)
                summary.append({
                    "werks": werks, "auart": auart,
                    "t1_saved": len(t1), "t2_saved": len(t2), "t3_saved": len(t3_enriched)
                })
            except TimeoutError as te:
                summary.append({"werks": werks, "auart": auart, "error": str(te)})
            except Exception as e:
                summary.append({"werks": werks, "auart": auart, "error": f"RFC/DB error: {e}"})
    finally:
        try: cur.close()
        except: pass
        try: db.close()
        except: pass
        try: sap.close()
        except: pass

    return {"ok": True, "saved": {"t1": total_t1, "t2": total_t2, "t3": total_t3}, "pairs": summary}

# ---------- Endpoints (opsional) ----------
@app.route("/", methods=["GET"])
def home():
    return (
        "OK. POST /api/yppr079/sync?werks=2000&auart=ZOR3&timeout=300 "
        "Headers (opsional): X-SAP-Username / X-SAP-Password; ENV juga didukung.",
        200, {"Content-Type": "text/plain"}
    )

@app.route("/api/yppr079/sync", methods=["POST"])
def sync_yppr079_http():
    ensure_tables()
    u, p = get_sap_credentials_from_headers()

    # dukung multi-auart: ?auart=ZOR1&auart=ZOR3 atau ?auart[]=ZOR1&auart[]=ZOR3
    filter_werks = request.args.get("werks")
    au_list = request.args.getlist("auart")
    filter_auart = au_list if au_list else request.args.get("auart")

    limit_param  = request.args.get("limit", type=int)
    timeout_sec  = request.args.get("timeout", default=300, type=int)

    # override ENV sementara untuk do_sync()
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
    parser.add_argument("--timeout", type=int, default=300, help="Timeout per panggilan RFC (detik)")
    args = parser.parse_args()

    if args.sync:
        auarts = args.auart if args.auart else [None]
        for au in auarts:
            result = do_sync(
                filter_werks=args.werks,
                filter_auart=au,      # pakai AUART per iterasi
                limit=args.limit,
                timeout_sec=args.timeout
            )
            print(json.dumps(to_jsonable(result), ensure_ascii=False, indent=2))
    else:
        app.run(host="127.0.0.1", port=5000, debug=True)
