#!/usr/bin/env python3
"""
Yetkaz print agent — oshxona kompyuterida ishlaydi.

- Reverb'ga (WebSocket) ulanib, faqat o'z restoran `print` kanalini tinglaydi
- `print.requested` kelganda chekni chop etadi (simulate = fayl, network = printer)
- LOKAL NAVBAT: SQLite. Printer o'chgan/xato bo'lsa buyurtma yo'qolmaydi —
  qayta ulanganda avtomat chop etiladi
- Chop etilgach backend'ga tasdiq yuboradi (orders.printed_at); tasdiq yuborilmasa
  (internet yo'q) keyingi urinishlarda qayta yuboriladi

Ishonchlilik qoidalari:
- KAMIDA BIR MARTA: chek yo'qolgandan ko'ra kamdan-kam takror chiqqani yaxshi.
  Chop etilgach agent kutilmaganda o'chsa, qayta ishga tushganda chek yana chiqishi mumkin.
- Chop etish YAGONA bajaruvchida (`_process_lock`): ikki oqim bir qatorni birga chop etmaydi.
- Har 30 s da backend'ga heartbeat ("tirikman") yuboriladi — jim bo'lsa panelda ogohlantirish chiqadi.
- WS ishlamay qolsa ham har ~60 s da backend'dan `pending` so'raladi (zaxira yo'l).
- Kanalga obuna xatosida backoff bilan qayta uriniladi, bo'lmasa WS uzilib qayta ulanadi
  (jim to'xtash yo'q).
"""
import base64
import json
import logging
import os
import socket
import sqlite3
import sys
import threading
import time
from datetime import datetime, timedelta, timezone
from logging.handlers import RotatingFileHandler
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

# Vaqtlar (soniya). Env orqali o'zgartirish mumkin (asosan testlar uchun).
QUEUE_RETRY_SECONDS = int(os.environ.get("QUEUE_RETRY_SECONDS", "15"))      # navbatni qayta urinish
PENDING_POLL_SECONDS = int(os.environ.get("PENDING_POLL_SECONDS", "60"))    # backend'dan pending (zaxira)
SUBSCRIBE_MAX_ATTEMPTS = int(os.environ.get("SUBSCRIBE_MAX_ATTEMPTS", "5"))  # obuna urinishlari, so'ng WS qayta ulanadi
SUBSCRIBE_WATCHDOG_SECONDS = int(os.environ.get("SUBSCRIBE_WATCHDOG_SECONDS", "90"))
HEARTBEAT_SECONDS = int(os.environ.get("HEARTBEAT_SECONDS", "30"))          # "tirikman" signali
RECONNECT_BASE_SECONDS = 5
RECONNECT_MAX_SECONDS = 60
ACKED_KEEP_DAYS = int(os.environ.get("ACKED_KEEP_DAYS", "7"))

CHANNEL = f"private-restaurant.{RESTAURANT_ID}.print"
WS_SCHEME = "wss" if REVERB_SCHEME == "https" else "ws"
WS_URL = (
    f"{WS_SCHEME}://{REVERB_HOST}:{REVERB_PORT}{REVERB_PATH}"
    f"/app/{REVERB_APP_KEY}?protocol=7&client=yetkaz-agent&version=1.0"
)
OUTPUT_DIR = Path(os.environ.get("OUTPUT_DIR", BASE / "output"))
DB_PATH = Path(os.environ.get("QUEUE_DB", BASE / "queue.db"))
LOG_FILE = Path(os.environ.get("LOG_FILE", BASE / "agent.log"))
LOG_MAX_BYTES = int(os.environ.get("LOG_MAX_BYTES", str(1_000_000)))
LOG_BACKUPS = int(os.environ.get("LOG_BACKUPS", "5"))

OUTPUT_DIR.mkdir(exist_ok=True)
_db_lock = threading.Lock()
# Chop etish yagona bajaruvchida: WS oqimi va retry oqimi bir qatorni birga chop etmasin.
_process_lock = threading.Lock()
# Navbatni darhol ishlatish signali (WS oqimini printerni kutib bloklamaslik uchun).
_wake = threading.Event()

# Ack natijalari
ACK_OK = "ok"            # backend tasdiqladi (yoki buyurtma yo'q — qayta urinish befoyda)
ACK_RETRY = "retry"      # backend xato qaytardi — keyinroq qayta urinamiz
ACK_OFFLINE = "offline"  # backend'ga ulanib bo'lmadi — shu o'tishda qolganlarini urinmaymiz

# Joriy WS ulanish holati (bitta ulanish; qayta ulanganda yangilanadi).
_state = {"socket_id": None, "subscribed": False, "sub_errors": 0}


# ---------------------------------------------------------------- log (aylanuvchi fayl)
_logger = logging.getLogger("yetkaz-agent")


def setup_logging(path: Path = None) -> logging.Logger:
    """Konsolga va aylanuvchi faylga (LOG_MAX_BYTES x LOG_BACKUPS) yozadi. Qayta chaqirish xavfsiz."""
    path = Path(path or LOG_FILE)
    for h in list(_logger.handlers):
        _logger.removeHandler(h)
        h.close()
    _logger.setLevel(logging.INFO)
    _logger.propagate = False
    fmt = logging.Formatter("%(asctime)s %(message)s", "%Y-%m-%d %H:%M:%S")

    file_handler = RotatingFileHandler(path, maxBytes=LOG_MAX_BYTES, backupCount=LOG_BACKUPS, encoding="utf-8")
    file_handler.setFormatter(fmt)
    console = logging.StreamHandler(sys.stdout)
    console.setFormatter(fmt)
    _logger.addHandler(file_handler)
    _logger.addHandler(console)

    return _logger


def log(*a):
    _logger.info(" ".join(str(x) for x in a))


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
            created_at    TEXT NOT NULL,
            acked_at      TEXT
        )
    """)
    # Eski queue.db (acked_at yo'q) — ustunni qo'shamiz. Eski chop etilgan qatorlar bir marta qayta
    # tasdiqlanadi (backend tomonida idempotent), tasdig'i yetib bormaganlari ham shunda tuzaladi.
    columns = {row[1] for row in conn.execute("PRAGMA table_info(jobs)")}
    if "acked_at" not in columns:
        conn.execute("ALTER TABLE jobs ADD COLUMN acked_at TEXT")
    conn.commit()
    return conn


CONN = db()


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def enqueue(data: dict) -> bool:
    """Yangi qator qo'shadi; bu order_id avval bo'lsa (chop etilgan bo'lsa ham) e'tibor bermaydi."""
    with _db_lock:
        cur = CONN.execute(
            "INSERT OR IGNORE INTO jobs (order_id, order_number, escpos, text, created_at) VALUES (?,?,?,?,?)",
            (
                data["order_id"],
                data["order_number"],
                base64.b64decode(data["escpos"]),
                data["text"],
                _now(),
            ),
        )
        CONN.commit()
        inserted = cur.rowcount > 0
    if inserted:
        log(f"navbatga qo'shildi: {data['order_number']}")
    return inserted


def pending():
    with _db_lock:
        return CONN.execute(
            "SELECT order_id, order_number, escpos, text, attempts FROM jobs WHERE printed_at IS NULL ORDER BY created_at, order_id"
        ).fetchall()


def unacked():
    """Chop etilgan, lekin backend tasdig'i hali yetib bormagan buyurtmalar."""
    with _db_lock:
        return CONN.execute(
            "SELECT order_id FROM jobs WHERE printed_at IS NOT NULL AND acked_at IS NULL ORDER BY created_at, order_id"
        ).fetchall()


def mark_printed(order_id: int):
    with _db_lock:
        CONN.execute("UPDATE jobs SET printed_at = ? WHERE order_id = ?", (_now(), order_id))
        CONN.commit()


def mark_acked(order_id: int):
    with _db_lock:
        CONN.execute("UPDATE jobs SET acked_at = ? WHERE order_id = ?", (_now(), order_id))
        CONN.commit()


def bump_attempt(order_id: int):
    with _db_lock:
        CONN.execute("UPDATE jobs SET attempts = attempts + 1 WHERE order_id = ?", (order_id,))
        CONN.commit()


def purge_old(days: int = ACKED_KEEP_DAYS) -> int:
    """Tasdiqlangan eski qatorlarni o'chiradi (fayl o'sib ketmasin). Backend `pending` faqat 3 soatni qaytaradi."""
    cutoff = (datetime.now(timezone.utc) - timedelta(days=days)).isoformat()
    with _db_lock:
        cur = CONN.execute("DELETE FROM jobs WHERE acked_at IS NOT NULL AND acked_at < ?", (cutoff,))
        CONN.commit()
        return cur.rowcount


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


def auth_headers() -> dict:
    return {"Authorization": f"Bearer {AGENT_TOKEN}", "Accept": "application/json"}


def confirm(order_id: int) -> str:
    try:
        r = requests.post(f"{BACKEND_URL}/api/agent/orders/{order_id}/printed", headers=auth_headers(), timeout=8)
    except requests.RequestException as e:
        log(f"tasdiq yuborilmadi (keyinroq qayta uriniladi): {e}")
        return ACK_OFFLINE

    if r.ok:
        log(f"backend tasdiqladi: order {order_id}")
        return ACK_OK
    if r.status_code == 404:
        # Buyurtma backend'da yo'q (masalan sinov cheki) — qayta urinishning foydasi yo'q.
        log(f"tasdiq: order {order_id} backend'da topilmadi — qayta urinilmaydi")
        return ACK_OK

    log(f"tasdiq xato {r.status_code}: {r.text[:120]} (keyinroq qayta uriniladi)")
    return ACK_RETRY


def resend_acks():
    """Chop etilgan, lekin tasdig'i yuborilmagan buyurtmalar uchun tasdiqni (qayta) yuboradi."""
    for (order_id,) in unacked():
        result = confirm(order_id)
        if result == ACK_OK:
            mark_acked(order_id)
        elif result == ACK_OFFLINE:
            break  # backend yo'q — qolganlarini keyingi o'tishda


def process_queue():
    """
    Navbatni bir marta o'tadi. Yagona bajaruvchi: boshqa oqim ishlayotgan bo'lsa shu tugashini
    kutadi va keyin yangilangan navbatni o'qiydi (chop etilganlar qayta chiqmaydi).
    """
    with _process_lock:
        for order_id, order_number, escpos, text, attempts in pending():
            try:
                print_receipt(order_number, escpos, text)
            except OSError as e:
                # Printer ulanmagan/o'chiq: qolganlarini ham urinish befoyda; tartib saqlanadi.
                bump_attempt(order_id)
                log(f"printerga ulanib bo'lmadi ({order_number}, urinish {attempts + 1}): {e}")
                break
            except Exception as e:  # noqa: BLE001
                bump_attempt(order_id)
                log(f"chop etishda xato ({order_number}, urinish {attempts + 1}): {e}")
                continue

            # Chop etildi. Shundan keyin, mark_printed'dan oldin o'chib qolsa — qayta chiqadi (kamida bir marta).
            mark_printed(order_id)

        resend_acks()


def trigger():
    """Navbat ishchisini darhol uyg'otadi (chop etishni WS oqimida kutmaymiz)."""
    _wake.set()


def queue_worker():
    """Chop etuvchi yagona oqim: signal kelganda yoki har QUEUE_RETRY_SECONDS da navbatni o'tadi."""
    while True:
        _wake.wait(timeout=QUEUE_RETRY_SECONDS)
        _wake.clear()
        try:
            process_queue()
        except Exception as e:  # noqa: BLE001
            log(f"queue_worker xato: {e}")


# ---------------------------------------------------------------- backend'dan pending (zaxira)
def fetch_pending() -> int:
    """Chop etilmagan buyurtmalarni backend'dan oladi (offline paytida yo'qolgan bo'lishi mumkin). Yangi qatorlar soni."""
    try:
        r = requests.get(f"{BACKEND_URL}/api/agent/orders/pending", headers=auth_headers(), timeout=8)
        r.raise_for_status()
        rows = r.json().get("data", [])
    except requests.RequestException as e:
        log(f"pending olinmadi: {e}")
        return 0

    new = sum(1 for row in rows if enqueue(row))
    if new:
        log(f"backend'dan {new} ta chop etilmagan buyurtma olindi")
    return new


def sync_once():
    """Zaxira o'tish: WS ishlamay qolgan bo'lsa ham yangi buyurtmalar kelsin."""
    if fetch_pending():
        trigger()
    purge_old()


def sync_worker():
    while True:
        time.sleep(PENDING_POLL_SECONDS)
        try:
            sync_once()
        except Exception as e:  # noqa: BLE001
            log(f"sync_worker xato: {e}")


# ---------------------------------------------------------------- heartbeat
_heartbeat_ok = {"value": None}


def send_heartbeat() -> bool:
    """
    Backend'ga "tirikman" signali (restaurants.print_agent_last_seen_at). Jim bo'lib qolsa oshxona paneli va
    admin «Printer agenti o'chiq» ogohlantirishini chiqaradi. Log faqat holat o'zgarganda (har 30 s da emas).
    """
    ok, reason = False, ""
    try:
        r = requests.post(f"{BACKEND_URL}/api/agent/heartbeat", headers=auth_headers(), timeout=8)
        ok = r.ok
        reason = "" if ok else f"{r.status_code}"
    except requests.RequestException as e:
        reason = str(e)

    if ok != _heartbeat_ok["value"]:
        log("heartbeat: backend bilan aloqa tiklandi" if ok else f"heartbeat yuborilmadi: {reason}")
        _heartbeat_ok["value"] = ok

    return ok


def heartbeat_worker():
    while True:
        try:
            send_heartbeat()
        except Exception as e:  # noqa: BLE001
            log(f"heartbeat_worker xato: {e}")
        time.sleep(HEARTBEAT_SECONDS)


# ---------------------------------------------------------------- websocket
def get_channel_auth(socket_id: str) -> str:
    r = requests.post(
        f"{BACKEND_URL}/api/agent/broadcasting/auth",
        headers=auth_headers(),
        json={"socket_id": socket_id, "channel_name": CHANNEL},
        timeout=8,
    )
    r.raise_for_status()
    return r.json()["auth"]


def backoff_delay(attempt: int, base: int = 2, cap: int = 60) -> int:
    """Obuna qayta urinish kutishi: 2, 4, 8, 16, 32, 60, 60 ... soniya."""
    return min(cap, base * (2 ** attempt))


def reconnect_delay(failures: int) -> int:
    """WS qayta ulanish kutishi: 5, 10, 20, 40, 60, 60 ... soniya (muvaffaqiyatli obunadan keyin qayta boshlanadi)."""
    return min(RECONNECT_MAX_SECONDS, RECONNECT_BASE_SECONDS * (2 ** max(0, failures)))


def _schedule(delay, fn, *args):
    t = threading.Timer(delay, fn, args)
    t.daemon = True
    t.start()


def try_subscribe(ws, socket_id: str, attempt: int = 0, schedule=_schedule) -> bool:
    """
    Kanalga obuna bo'lish. Auth xatosida backoff bilan qayta uriniladi; SUBSCRIBE_MAX_ATTEMPTS dan keyin
    WS yopiladi va asosiy sikl yangi ulanish ochadi — ulanib turib obunasiz qolish (jim to'xtash) yo'q.
    """
    if _state["socket_id"] != socket_id:
        return False  # ulanish allaqachon almashgan/yopilgan — eski urinish bekor

    try:
        auth = get_channel_auth(socket_id)
        ws.send(json.dumps({"event": "pusher:subscribe", "data": {"channel": CHANNEL, "auth": auth}}))
        return True
    except Exception as e:  # noqa: BLE001
        if attempt + 1 >= SUBSCRIBE_MAX_ATTEMPTS:
            log(f"obuna {attempt + 1} marta amalga oshmadi ({e}) — WS qayta ulanadi")
            ws.close()
            return False
        delay = backoff_delay(attempt)
        log(f"obuna xatosi ({e}) — {delay} s dan keyin qayta uriniladi ({attempt + 1}/{SUBSCRIBE_MAX_ATTEMPTS})")
        schedule(delay, try_subscribe, ws, socket_id, attempt + 1, schedule)
        return False


def subscription_watchdog(ws, socket_id: str):
    """Ulangan, lekin SUBSCRIBE_WATCHDOG_SECONDS ichida obuna tasdiqlanmasa — WS ni yopib qayta ulaymiz."""
    if _state["socket_id"] == socket_id and not _state["subscribed"]:
        log("obuna tasdiqlanmadi — WS qayta ulanadi")
        ws.close()


def on_message(ws, raw):
    msg = json.loads(raw)
    event = msg.get("event", "")

    if event == "pusher:connection_established":
        socket_id = json.loads(msg["data"])["socket_id"]
        _state["socket_id"] = socket_id
        _state["subscribed"] = False
        _state["sub_errors"] = 0
        log(f"ulandi (socket {socket_id}), kanalga obuna: {CHANNEL}")
        try_subscribe(ws, socket_id)
        _schedule(SUBSCRIBE_WATCHDOG_SECONDS, subscription_watchdog, ws, socket_id)

    elif event == "pusher_internal:subscription_succeeded":
        _state["subscribed"] = True
        log("obuna tasdiqlandi — chek so'rovlarini kutmoqda")
        fetch_pending()   # offline paytida yo'qolgan bo'lishi mumkin
        trigger()

    elif event == "pusher_internal:subscription_error" or event == "pusher:error":
        log(f"OBUNA XATOSI: {msg.get('data')}")
        if _state["socket_id"] and not _state["subscribed"]:
            _state["sub_errors"] += 1
            if _state["sub_errors"] >= SUBSCRIBE_MAX_ATTEMPTS:
                log("obuna rad etilaveradi — WS qayta ulanadi")
                ws.close()
            else:
                # Server obunani rad etdi (imzo/kanal): tez-tez urinmaymiz, backoff bilan.
                _schedule(backoff_delay(_state["sub_errors"] - 1), try_subscribe, ws, _state["socket_id"])

    elif event == "pusher:ping":
        ws.send(json.dumps({"event": "pusher:pong", "data": {}}))

    elif event == "print.requested":
        enqueue(json.loads(msg["data"]))
        trigger()


def on_error(ws, err):
    log(f"WS xato: {err}")


def on_close(ws, code, reason):
    _state["socket_id"] = None
    log(f"WS uzildi ({code}) — qayta ulanamiz…")


def main():
    setup_logging()

    if not AGENT_TOKEN or not RESTAURANT_ID:
        raise SystemExit("AGENT_TOKEN va RESTAURANT_ID .env da to'ldirilishi shart")

    log(f"Yetkaz print agent — restoran {RESTAURANT_ID}, rejim: {PRINTER_MODE}")
    log(f"backend: {BACKEND_URL}")
    log(f"reverb:  {WS_SCHEME}://{REVERB_HOST}:{REVERB_PORT}{REVERB_PATH}/app/*  kanal: {CHANNEL}")
    log(f"log fayli: {LOG_FILE}")
    fetch_pending()  # oxirgi ishlaganidan beri kelgan buyurtmalar
    trigger()        # lokal navbatda qolgan

    threading.Thread(target=queue_worker, daemon=True).start()
    threading.Thread(target=sync_worker, daemon=True).start()
    threading.Thread(target=heartbeat_worker, daemon=True).start()

    failures = 0
    while True:
        _state["subscribed"] = False
        try:
            ws = websocket.WebSocketApp(
                WS_URL, on_message=on_message, on_error=on_error, on_close=on_close
            )
            ws.run_forever(ping_interval=25, ping_timeout=10)
        except Exception as e:  # noqa: BLE001
            log(f"run_forever xato: {e}")

        # Obuna bo'lib ishlagan ulanishdan keyin kutish qayta boshlanadi; aks holda backoff o'sadi.
        failures = 0 if _state["subscribed"] else failures + 1
        time.sleep(reconnect_delay(failures))


if __name__ == "__main__":
    main()
