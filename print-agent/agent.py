#!/usr/bin/env python3
"""
Yetkaz print agent — oshxona kompyuterida ishlaydi.

- Reverb'ga (WebSocket) ulanib, faqat o'z restoran `print` kanalini tinglaydi
- `print.requested` kelganда chekni chop etadi (simulate = fayl, network = printer)
- LOKAL NAVBAT: SQLite. Printer o'chgan/xato bo'lsa buyurtma yo'qolmaydi —
  qayta ulanganда avtomat chop etiladi
- Chop etilgach backend'ga tasdiq yuboradi (orders.printed_at)
"""
import base64
import json
import os
import socket
import sqlite3
import threading
import time
from datetime import datetime, timezone
from pathlib import Path

import requests
import websocket
from dotenv import load_dotenv

BASE = Path(__file__).parent
load_dotenv(BASE / ".env")

BACKEND_URL = os.environ.get("BACKEND_URL", "http://localhost:8010").rstrip("/")
RESTAURANT_ID = int(os.environ.get("RESTAURANT_ID", "0"))
# Server .env dagi kalit nomi PRINT_AGENT_TOKEN — eski AGENT_TOKEN ham qo'llanadi.
AGENT_TOKEN = os.environ.get("PRINT_AGENT_TOKEN") or os.environ.get("AGENT_TOKEN", "")
REVERB_HOST = os.environ.get("REVERB_HOST", "localhost")
REVERB_PORT = int(os.environ.get("REVERB_PORT", "8080"))
REVERB_SCHEME = os.environ.get("REVERB_SCHEME", "http")
REVERB_APP_KEY = os.environ.get("REVERB_APP_KEY", "local-key")
# Production'da Reverb domen portida ochilmaydi — nginx `/reverb/` ni WebSocket'ga
# proxy qiladi. REVERB_PATH=/reverb shu holat uchun. Lokalda bo'sh (to'g'ridan-to'g'ri :8080).
REVERB_PATH = os.environ.get("REVERB_PATH", "").rstrip("/")
PRINTER_MODE = os.environ.get("PRINTER_MODE", "simulate")
PRINTER_HOST = os.environ.get("PRINTER_HOST", "")
PRINTER_PORT = int(os.environ.get("PRINTER_PORT", "9100"))

CHANNEL = f"private-restaurant.{RESTAURANT_ID}.print"
WS_SCHEME = "wss" if REVERB_SCHEME == "https" else "ws"
WS_URL = (
    f"{WS_SCHEME}://{REVERB_HOST}:{REVERB_PORT}{REVERB_PATH}"
    f"/app/{REVERB_APP_KEY}?protocol=7&client=yetkaz-agent&version=1.0"
)
OUTPUT_DIR = BASE / "output"
DB_PATH = BASE / "queue.db"

OUTPUT_DIR.mkdir(exist_ok=True)
_db_lock = threading.Lock()


def log(*a):
    print(f"[{datetime.now().strftime('%H:%M:%S')}]", *a, flush=True)


# ---------------------------------------------------------------- lokal navbat
def db():
    conn = sqlite3.connect(DB_PATH, check_same_thread=False)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS jobs (
            order_id      INTEGER PRIMARY KEY,
            order_number  TEXT NOT NULL,
            escpos        BLOB NOT NULL,
            text          TEXT NOT NULL,
            attempts      INTEGER NOT NULL DEFAULT 0,
            printed_at    TEXT,
            created_at    TEXT NOT NULL
        )
    """)
    conn.commit()
    return conn


CONN = db()


def enqueue(data: dict):
    with _db_lock:
        CONN.execute(
            "INSERT OR IGNORE INTO jobs (order_id, order_number, escpos, text, created_at) VALUES (?,?,?,?,?)",
            (
                data["order_id"],
                data["order_number"],
                base64.b64decode(data["escpos"]),
                data["text"],
                datetime.now(timezone.utc).isoformat(),
            ),
        )
        CONN.commit()
    log(f"navbatga qo'shildi: {data['order_number']}")


def pending():
    with _db_lock:
        return CONN.execute(
            "SELECT order_id, order_number, escpos, text, attempts FROM jobs WHERE printed_at IS NULL ORDER BY created_at"
        ).fetchall()


def mark_printed(order_id: int):
    with _db_lock:
        CONN.execute(
            "UPDATE jobs SET printed_at = ? WHERE order_id = ?",
            (datetime.now(timezone.utc).isoformat(), order_id),
        )
        CONN.commit()


def bump_attempt(order_id: int):
    with _db_lock:
        CONN.execute("UPDATE jobs SET attempts = attempts + 1 WHERE order_id = ?", (order_id,))
        CONN.commit()


# ---------------------------------------------------------------- chop etish
def print_receipt(order_number: str, escpos: bytes, text: str):
    if PRINTER_MODE == "network":
        with socket.create_connection((PRINTER_HOST, PRINTER_PORT), timeout=8) as s:
            s.sendall(escpos)
        log(f"printerga yuborildi: {order_number} -> {PRINTER_HOST}:{PRINTER_PORT}")
    else:
        ts = datetime.now().strftime("%Y%m%d-%H%M%S")
        path = OUTPUT_DIR / f"{order_number}-{ts}.txt"
        path.write_text(text, encoding="utf-8")
        log(f"SIMULATE — chek yozildi: {path.name}")
        print("\n" + "─" * 44 + f"\n{text}\n" + "─" * 44 + "\n", flush=True)


def confirm(order_id: int):
    try:
        r = requests.post(
            f"{BACKEND_URL}/api/agent/orders/{order_id}/printed",
            headers={"Authorization": f"Bearer {AGENT_TOKEN}", "Accept": "application/json"},
            timeout=8,
        )
        if r.ok:
            log(f"backend tasdiqladi: order {order_id}")
        else:
            log(f"tasdiq xato {r.status_code}: {r.text[:120]}")
    except requests.RequestException as e:
        log(f"tasdiq yuborilmadi (keyinroq): {e}")


def process_queue():
    for order_id, order_number, escpos, text, attempts in pending():
        try:
            print_receipt(order_number, escpos, text)
            mark_printed(order_id)
            confirm(order_id)
        except Exception as e:  # noqa: BLE001
            bump_attempt(order_id)
            log(f"chop etishда xato ({order_number}, urinish {attempts + 1}): {e}")


def retry_worker():
    while True:
        time.sleep(15)
        try:
            process_queue()
        except Exception as e:  # noqa: BLE001
            log(f"retry_worker xato: {e}")


# ---------------------------------------------------------------- websocket
def fetch_pending():
    """Agent offline paytida yo'qolgan bo'lishi mumkin bo'lgan buyurtmalarni backend'dan oladi."""
    try:
        r = requests.get(
            f"{BACKEND_URL}/api/agent/orders/pending",
            headers={"Authorization": f"Bearer {AGENT_TOKEN}", "Accept": "application/json"},
            timeout=8,
        )
        r.raise_for_status()
        rows = r.json().get("data", [])
        for row in rows:
            enqueue(row)
        if rows:
            log(f"backend'dan {len(rows)} ta chop etilmagan buyurtma olindi")
    except requests.RequestException as e:
        log(f"pending olinmadi: {e}")


def get_channel_auth(socket_id: str) -> str:
    r = requests.post(
        f"{BACKEND_URL}/api/agent/broadcasting/auth",
        headers={"Authorization": f"Bearer {AGENT_TOKEN}", "Accept": "application/json"},
        json={"socket_id": socket_id, "channel_name": CHANNEL},
        timeout=8,
    )
    r.raise_for_status()
    return r.json()["auth"]


def on_message(ws, raw):
    msg = json.loads(raw)
    event = msg.get("event", "")

    if event == "pusher:connection_established":
        socket_id = json.loads(msg["data"])["socket_id"]
        log(f"ulandi (socket {socket_id}), kanalga obuna: {CHANNEL}")
        auth = get_channel_auth(socket_id)
        ws.send(json.dumps({"event": "pusher:subscribe", "data": {"channel": CHANNEL, "auth": auth}}))

    elif event == "pusher_internal:subscription_succeeded":
        log("obuna tasdiqlandi — chek so'rovlarini kutmoqda")
        fetch_pending()   # offline paytida yo'qolgan bo'lishi mumkin
        process_queue()

    elif event == "pusher_internal:subscription_error" or event == "pusher:error":
        log(f"OBUNA XATOSI: {msg.get('data')}")

    elif event == "pusher:ping":
        ws.send(json.dumps({"event": "pusher:pong", "data": {}}))

    elif event == "print.requested":
        enqueue(json.loads(msg["data"]))
        process_queue()


def on_error(ws, err):
    log(f"WS xato: {err}")


def on_close(ws, code, reason):
    log(f"WS uzildi ({code}) — qayta ulanamiz…")


def main():
    if not AGENT_TOKEN or not RESTAURANT_ID:
        raise SystemExit("AGENT_TOKEN va RESTAURANT_ID .env da to'ldirilishi shart")

    log(f"Yetkaz print agent — restoran {RESTAURANT_ID}, rejim: {PRINTER_MODE}")
    log(f"backend: {BACKEND_URL}")
    log(f"reverb:  {WS_SCHEME}://{REVERB_HOST}:{REVERB_PORT}{REVERB_PATH}/app/*  kanal: {CHANNEL}")
    fetch_pending()  # oxirgi ishlaganдан beri kelgan buyurtmalar
    process_queue()  # lokal navbatда qolgan

    threading.Thread(target=retry_worker, daemon=True).start()

    while True:
        try:
            ws = websocket.WebSocketApp(
                WS_URL, on_message=on_message, on_error=on_error, on_close=on_close
            )
            ws.run_forever(ping_interval=25, ping_timeout=10)
        except Exception as e:  # noqa: BLE001
            log(f"run_forever xato: {e}")
        time.sleep(5)


if __name__ == "__main__":
    main()
