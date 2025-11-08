#!/usr/bin/env python3
# nik_bulk_insert.py

import os, json, decimal, datetime, math, sys, re, time, signal
from typing import Any, Dict, List, Optional, Tuple

# Opsional, dibiarkan agar cocok dengan environment Anda
from flask import Flask, jsonify, request  # noqa: F401
import mysql.connector  # noqa: F401
from pyrfc import Connection, ABAPApplicationError, ABAPRuntimeError, CommunicationError, LogonError
from concurrent.futures import ThreadPoolExecutor, as_completed, TimeoutError as FuturesTimeout
from dotenv import load_dotenv

# --- Abaikan KeyboardInterrupt agar pool/IO tidak membatalkan proses di tengah bulk insert
try:
    signal.signal(signal.SIGINT, signal.SIG_IGN)
except Exception:
    pass

load_dotenv()  # otomatis baca .env dari working directory

# ---------- Konfigurasi koneksi SAP ----------
DEFAULT_SAP = {
    "ashost": os.environ.get("SAP_ASHOST", "192.168.254.154"),
    "sysnr":  os.environ.get("SAP_SYSNR",  "01"),
    "client": os.environ.get("SAP_CLIENT", "300"),
    "lang":   os.environ.get("SAP_LANG",   "EN"),
}

SAP_USERNAME = os.environ.get("SAP_USER", "auto_email")
SAP_PASSWORD = os.environ.get("SAP_PASS", "11223344")

RFC_NAME = "Z_RFC_INSERT_NIK_CONF"

# ---------- Util parsing ----------
def parse_pernr_from_txt(path: str) -> List[str]:
    """
    Baca file teks dan ekstrak semua token angka di dalam tanda kutip:
      ( '10000625' ) ... dst
    Mengembalikan list string PERNR ter-normalisasi (zfill 8).
    """
    with open(path, "r", encoding="utf-8") as f:
        content = f.read()

    # Ambil semua isi di dalam tanda kutip tunggal: '12345678'
    raw = re.findall(r"'(\d+)'", content)

    # Normalisasi ke panjang 8 digit (PERNR SAP umumnya 8 digit)
    pernr_list = [p.zfill(8) for p in raw]

    return pernr_list

# def parse_pernr_from_txt(path: str) -> List[str]:
#     """
#     Mendukung:
#       1) Format ber-kutip: ( '10000625' ) ...
#       2) Format polos:    10000008\n10000035\n...
#     """
#     import re
#     with open(path, "r", encoding="utf-8") as f:
#         content = f.read()

#     # Tangkap angka di dalam kutip ATAU angka polos yang berdiri sendiri
#     pairs = re.findall(r"'(\d+)'|(?<!\w)(\d{5,})(?!\w)", content)
#     raw = [(a or b) for (a, b) in pairs]

#     # Normalisasi ke 8 digit
#     return [p.zfill(8) for p in raw]

def dedupe_keep_order(items: List[str]) -> List[str]:
    seen = set()
    out: List[str] = []
    for x in items:
        if x not in seen:
            seen.add(x)
            out.append(x)
    return out

# ---------- Worker RFC ----------
def rfc_insert_one(conn: Connection, pernr: str, werks: str, delete_flag: str) -> Tuple[str, bool, str]:
    """
    Return (pernr, ok, message)
    """
    try:
        resp = conn.call(
            RFC_NAME,
            PERNR=pernr,
            WERKS=werks,
            DELETE_FLAG=delete_flag,
        )

        # Coba baca pola return umum bila ada
        msg = ""
        if isinstance(resp, dict):
            # Jika FM mengembalikan struktur/field pesan
            for key in ("EV_MESSAGE", "MESSAGE", "MSG", "RETURN"):
                if key in resp:
                    val = resp[key]
                    # RETURN kadang berupa table of BAPIRET2
                    if isinstance(val, list) and val:
                        # Ambil gabungan pesan singkat
                        msg = " | ".join(
                            [f"{row.get('TYPE','')}{row.get('NUMBER','')}-{row.get('MESSAGE','')}".strip("-")
                             if isinstance(row, dict) else str(row)
                             for row in val]
                        )
                    else:
                        msg = str(val)
                    break

        return (pernr, True, msg or "OK")
    except (ABAPApplicationError, ABAPRuntimeError, CommunicationError, LogonError) as e:
        return (pernr, False, f"SAP_ERROR: {e}")
    except Exception as e:
        return (pernr, False, f"PY_ERROR: {e}")

# ---------- Main ----------
def main():
    import argparse

    parser = argparse.ArgumentParser(
        description="Bulk insert PERNR ke RFC Z_RFC_INSERT_NIK_CONF dari file teks."
    )
    parser.add_argument("--file", default="NIK_2000.txt", help="Path file txt sumber (default: NIK_2000.txt)")
    parser.add_argument("--werks", default="2000", help="Kode WERKS (default: 2000)")
    parser.add_argument("--delete-flag", default="", help="Nilai DELETE_FLAG (default: kosong)")
    parser.add_argument("--workers", type=int, default=1, help="Jumlah parallel workers (default: 1)")
    parser.add_argument("--keep-duplicates", action="store_true", help="Jangan dedup PERNR yang duplikat")
    args = parser.parse_args()

    # Parsing sumber
    pernr_list = parse_pernr_from_txt(args.file)
    if not args.keep_duplicates:
        pernr_list = dedupe_keep_order(pernr_list)

    total = len(pernr_list)
    print(f"Loaded PERNR: {total} item(s) dari {args.file}")

    if total == 0:
        print("Tidak ada PERNR yang ditemukan. Periksa format file Anda.")
        sys.exit(1)

    # Koneksi SAP
    sap_params = {
        **DEFAULT_SAP,
        "user": SAP_USERNAME,
        "passwd": SAP_PASSWORD,
    }
    print(f"Menghubungkan ke SAP {sap_params['ashost']} client {sap_params['client']} ...")
    conn = Connection(**sap_params)
    print("Koneksi SAP OK.")

    ok, fail = 0, 0
    results: List[Tuple[str, bool, str]] = []

    if args.workers > 1:
        with ThreadPoolExecutor(max_workers=args.workers) as pool:
            futures = [pool.submit(rfc_insert_one, conn, pernr, args.werks, args.delete_flag) for pernr in pernr_list]
            for fut in as_completed(futures):
                pernr, status, message = fut.result()
                results.append((pernr, status, message))
    else:
        for pernr in pernr_list:
            pernr, status, message = rfc_insert_one(conn, pernr, args.werks, args.delete_flag)
            results.append((pernr, status, message))

    # Ringkasan
    for pernr, status, message in results:
        prefix = "OK " if status else "ERR"
        print(f"[{prefix}] PERNR={pernr}  WERKS={args.werks}  MSG={message}")
        if status:
            ok += 1
        else:
            fail += 1

    print("-" * 60)
    print(f"SELESAI: total={total}, sukses={ok}, gagal={fail}")

if __name__ == "__main__":
    main()
