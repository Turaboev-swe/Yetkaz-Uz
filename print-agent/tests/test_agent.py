import json
import sqlite3
import threading
import time
from datetime import datetime, timedelta, timezone
from logging.handlers import RotatingFileHandler

import pytest

import agent
from conftest import TMP, FakeResponse, FakeWs, make_job


def row(order_id):
    return agent.CONN.execute(
        "SELECT printed_at, acked_at, attempts FROM jobs WHERE order_id = ?", (order_id,)
    ).fetchone()


@pytest.fixture
def printed(monkeypatch):
    """print_receipt'ni yozib boruvchi soxta bilan almashtiradi."""
    calls = []
    monkeypatch.setattr(agent, "print_receipt", lambda number, escpos, text: calls.append(number))
    return calls


@pytest.fixture
def backend_ok(monkeypatch):
    """Backend tasdiqlarini yozib boradi (har doim 200)."""
    acks = []

    def post(url, **kw):
        acks.append(url)
        return FakeResponse(200)

    monkeypatch.setattr(agent.requests, "post", post)
    return acks


# ---------------------------------------------------------------- navbat va yagona bajaruvchi
def test_enqueue_ignores_duplicates_even_after_printing(printed, backend_ok):
    assert agent.enqueue(make_job(1)) is True
    agent.process_queue()
    assert agent.enqueue(make_job(1)) is False  # chop etilgan qator ham dedupe qiladi

    agent.process_queue()

    assert printed == ["A-1"]


def test_concurrent_process_queue_prints_a_job_once(monkeypatch, backend_ok):
    """WS oqimi va retry oqimi bir vaqtda chaqirsa ham bir qator ikki marta chiqmaydi."""
    printed = []

    def slow_print(number, escpos, text):
        time.sleep(0.2)
        printed.append(number)

    monkeypatch.setattr(agent, "print_receipt", slow_print)
    agent.enqueue(make_job(1))

    threads = [threading.Thread(target=agent.process_queue) for _ in range(4)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()

    assert printed == ["A-1"]


def test_failed_print_stays_queued_and_prints_on_the_next_pass(monkeypatch, backend_ok):
    outcomes = [RuntimeError("qog'oz tugadi"), None]
    printed = []

    def flaky(number, escpos, text):
        err = outcomes.pop(0)
        if err:
            raise err
        printed.append(number)

    monkeypatch.setattr(agent, "print_receipt", flaky)
    agent.enqueue(make_job(1))

    agent.process_queue()
    assert row(1)[0] is None and row(1)[2] == 1  # chop etilmadi, urinish hisoblandi

    agent.process_queue()
    assert printed == ["A-1"] and row(1)[0] is not None


def test_unreachable_printer_stops_the_pass_but_keeps_the_order(monkeypatch, backend_ok):
    attempted = []

    def down(number, escpos, text):
        attempted.append(number)
        raise ConnectionRefusedError("printer o'chiq")

    monkeypatch.setattr(agent, "print_receipt", down)
    for i in (1, 2, 3):
        agent.enqueue(make_job(i))

    agent.process_queue()

    assert attempted == ["A-1"]  # har biri uchun 8 s timeout kutmaymiz
    assert [r[0] for r in agent.pending()] == [1, 2, 3]  # hammasi navbatda, tartib saqlangan


def test_other_errors_do_not_block_following_jobs(monkeypatch, backend_ok):
    printed = []

    def selective(number, escpos, text):
        if number == "A-1":
            raise ValueError("buzilgan chek")
        printed.append(number)

    monkeypatch.setattr(agent, "print_receipt", selective)
    agent.enqueue(make_job(1))
    agent.enqueue(make_job(2))

    agent.process_queue()

    assert printed == ["A-2"]
    assert [r[0] for r in agent.pending()] == [1]


def test_at_least_once_a_crash_between_print_and_mark_reprints(monkeypatch, printed, backend_ok):
    """Chop etilgach belgilashdan oldin o'chib qolsa — chek yo'qolmaydi, qayta chiqadi (kamida bir marta)."""
    real_mark = agent.mark_printed

    def crash(order_id):
        raise RuntimeError("o'chdi")

    monkeypatch.setattr(agent, "mark_printed", crash)
    agent.enqueue(make_job(1))

    with pytest.raises(RuntimeError):
        agent.process_queue()

    monkeypatch.setattr(agent, "mark_printed", real_mark)
    agent.process_queue()

    assert printed == ["A-1", "A-1"]
    assert row(1)[0] is not None


def test_jobs_print_in_arrival_order(printed, backend_ok):
    for i in (3, 1, 2):
        agent.enqueue(make_job(i))
        time.sleep(0.002)

    agent.process_queue()

    assert printed == ["A-3", "A-1", "A-2"]


# ---------------------------------------------------------------- tasdiq (ack)
def test_unconfirmed_print_is_acked_later_without_reprinting(monkeypatch, printed):
    mode = {"online": False}
    acks = []

    def post(url, **kw):
        if not mode["online"]:
            raise agent.requests.ConnectionError("internet yo'q")
        acks.append(url)
        return FakeResponse(200)

    monkeypatch.setattr(agent.requests, "post", post)
    agent.enqueue(make_job(1))

    agent.process_queue()
    assert printed == ["A-1"]
    assert row(1)[0] is not None and row(1)[1] is None  # chop etildi, ack yo'q

    mode["online"] = True
    agent.process_queue()

    assert printed == ["A-1"], "ack qayta yuborilganda chek takror chiqmaydi"
    assert acks == ["http://backend.test/api/agent/orders/1/printed"]
    assert row(1)[1] is not None


def test_ack_is_retried_after_backend_errors(monkeypatch, printed):
    statuses = [500, 500, 200]
    monkeypatch.setattr(agent.requests, "post", lambda url, **kw: FakeResponse(statuses.pop(0)))
    agent.enqueue(make_job(1))

    agent.process_queue()
    agent.process_queue()
    assert row(1)[1] is None
    agent.process_queue()

    assert row(1)[1] is not None
    assert printed == ["A-1"]


def test_confirm_treats_404_as_done_and_connection_errors_as_offline(monkeypatch):
    monkeypatch.setattr(agent.requests, "post", lambda url, **kw: FakeResponse(404))
    assert agent.confirm(5) == agent.ACK_OK

    monkeypatch.setattr(agent.requests, "post", lambda url, **kw: FakeResponse(403))
    assert agent.confirm(5) == agent.ACK_RETRY

    def boom(url, **kw):
        raise agent.requests.ConnectionError("yo'q")

    monkeypatch.setattr(agent.requests, "post", boom)
    assert agent.confirm(5) == agent.ACK_OFFLINE


def test_resend_acks_stops_at_the_first_offline_failure(monkeypatch, printed):
    calls = []

    def boom(url, **kw):
        calls.append(url)
        raise agent.requests.ConnectionError("yo'q")

    monkeypatch.setattr(agent.requests, "post", boom)
    for i in (1, 2, 3):
        agent.enqueue(make_job(i))
        agent.mark_printed(i)

    agent.resend_acks()

    assert len(calls) == 1  # offline paytida har biri uchun timeout kutmaymiz


def test_legacy_printed_rows_without_ack_are_acked_once(backend_ok):
    agent.enqueue(make_job(1))
    agent.mark_printed(1)  # eski agent: chop etilgan, ack ustuni yo'q edi

    agent.resend_acks()

    assert backend_ok == ["http://backend.test/api/agent/orders/1/printed"]
    assert row(1)[1] is not None


# ---------------------------------------------------------------- backend'dan pending (zaxira)
def test_fetch_pending_enqueues_only_new_orders(monkeypatch):
    rows = [make_job(1), make_job(2)]
    monkeypatch.setattr(agent.requests, "get", lambda url, **kw: FakeResponse(200, {"data": rows}))

    assert agent.fetch_pending() == 2
    assert agent.fetch_pending() == 0  # ikkinchi marta yangi qator yo'q

    assert [r[0] for r in agent.pending()] == [1, 2]


def test_fetch_pending_survives_backend_errors(monkeypatch):
    def boom(url, **kw):
        raise agent.requests.ConnectionError("yo'q")

    monkeypatch.setattr(agent.requests, "get", boom)

    assert agent.fetch_pending() == 0


def test_sync_once_polls_the_backend_and_wakes_the_printer_thread(monkeypatch):
    monkeypatch.setattr(agent.requests, "get", lambda url, **kw: FakeResponse(200, {"data": [make_job(9)]}))
    assert not agent._wake.is_set()

    agent.sync_once()

    assert agent._wake.is_set()
    assert [r[0] for r in agent.pending()] == [9]


def test_sync_once_does_not_wake_when_nothing_is_new(monkeypatch):
    monkeypatch.setattr(agent.requests, "get", lambda url, **kw: FakeResponse(200, {"data": []}))

    agent.sync_once()

    assert not agent._wake.is_set()


# ---------------------------------------------------------------- obuna (jim to'xtamaslik)
def immediate(delays):
    def schedule(delay, fn, *args):
        delays.append(delay)
        fn(*args)

    return schedule


def test_subscribe_retries_with_backoff_then_succeeds(monkeypatch):
    attempts = {"n": 0}

    def flaky_auth(socket_id):
        attempts["n"] += 1
        if attempts["n"] < 3:
            raise agent.requests.ConnectionError("backend yo'q")
        return "key:sig"

    monkeypatch.setattr(agent, "get_channel_auth", flaky_auth)
    agent._state["socket_id"] = "s1"
    ws, delays = FakeWs(), []

    agent.try_subscribe(ws, "s1", schedule=immediate(delays))

    assert delays == [2, 4]
    assert len(ws.sent) == 1 and json.loads(ws.sent[0])["data"]["auth"] == "key:sig"
    assert ws.closed is False


def test_subscribe_gives_up_and_closes_the_socket_so_it_reconnects(monkeypatch):
    def always_fails(socket_id):
        raise agent.requests.ConnectionError("backend yo'q")

    monkeypatch.setattr(agent, "get_channel_auth", always_fails)
    agent._state["socket_id"] = "s1"
    ws, delays = FakeWs(), []

    agent.try_subscribe(ws, "s1", schedule=immediate(delays))

    assert ws.closed is True, "obunasiz ulanib turmasligi kerak"
    assert ws.sent == []
    assert delays == [2, 4, 8, 16]  # SUBSCRIBE_MAX_ATTEMPTS = 5 urinish


def test_subscribe_attempt_for_a_replaced_connection_is_dropped(monkeypatch):
    monkeypatch.setattr(agent, "get_channel_auth", lambda s: pytest.fail("eski ulanish uchun auth so'ralmasligi kerak"))
    agent._state["socket_id"] = "new-socket"
    ws = FakeWs()

    assert agent.try_subscribe(ws, "old-socket") is False
    assert ws.sent == [] and ws.closed is False


def test_watchdog_closes_a_connection_that_never_subscribed():
    agent._state.update({"socket_id": "s1", "subscribed": False})
    ws = FakeWs()
    agent.subscription_watchdog(ws, "s1")
    assert ws.closed is True

    agent._state["subscribed"] = True
    ws2 = FakeWs()
    agent.subscription_watchdog(ws2, "s1")
    assert ws2.closed is False

    agent._state.update({"socket_id": "other", "subscribed": False})
    ws3 = FakeWs()
    agent.subscription_watchdog(ws3, "s1")
    assert ws3.closed is False


def test_message_flow_connect_subscribe_print(monkeypatch):
    scheduled = []
    monkeypatch.setattr(agent, "_schedule", lambda delay, fn, *a: scheduled.append((delay, fn)))
    monkeypatch.setattr(agent, "get_channel_auth", lambda s: "key:sig")
    monkeypatch.setattr(agent.requests, "get", lambda url, **kw: FakeResponse(200, {"data": []}))
    ws = FakeWs()

    agent.on_message(ws, json.dumps({"event": "pusher:connection_established", "data": json.dumps({"socket_id": "s9"})}))
    assert agent._state["socket_id"] == "s9" and len(ws.sent) == 1
    assert any(fn is agent.subscription_watchdog for _, fn in scheduled)

    agent.on_message(ws, json.dumps({"event": "pusher_internal:subscription_succeeded", "data": "{}"}))
    assert agent._state["subscribed"] is True and agent._wake.is_set()

    agent._wake.clear()
    agent.on_message(ws, json.dumps({"event": "print.requested", "data": json.dumps(make_job(5))}))
    assert [r[0] for r in agent.pending()] == [5] and agent._wake.is_set()

    agent.on_message(ws, json.dumps({"event": "pusher:ping", "data": "{}"}))
    assert json.loads(ws.sent[-1])["event"] == "pusher:pong"


def test_repeated_subscription_errors_back_off_and_finally_reconnect(monkeypatch):
    scheduled = []
    monkeypatch.setattr(agent, "_schedule", lambda delay, fn, *a: scheduled.append(delay))
    agent._state.update({"socket_id": "s1", "subscribed": False, "sub_errors": 0})
    ws = FakeWs()
    msg = json.dumps({"event": "pusher_internal:subscription_error", "data": "403"})

    for _ in range(agent.SUBSCRIBE_MAX_ATTEMPTS):
        agent.on_message(ws, msg)

    assert scheduled == [2, 4, 8, 16]
    assert ws.closed is True


def test_backoff_helpers():
    assert [agent.backoff_delay(i) for i in range(7)] == [2, 4, 8, 16, 32, 60, 60]
    assert [agent.reconnect_delay(i) for i in range(6)] == [5, 10, 20, 40, 60, 60]


# ---------------------------------------------------------------- saqlash va log
def test_old_queue_db_without_acked_at_is_migrated(monkeypatch, tmp_path):
    path = tmp_path / "old.db"
    old = sqlite3.connect(path)
    old.execute(
        "CREATE TABLE jobs (order_id INTEGER PRIMARY KEY, order_number TEXT NOT NULL, escpos BLOB NOT NULL,"
        " text TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, printed_at TEXT, created_at TEXT NOT NULL)"
    )
    old.execute("INSERT INTO jobs VALUES (1, 'A-1', x'00', 't', 0, NULL, '2026-01-01')")
    old.commit()
    old.close()
    monkeypatch.setattr(agent, "DB_PATH", path)

    conn = agent.db()

    assert "acked_at" in {r[1] for r in conn.execute("PRAGMA table_info(jobs)")}
    assert conn.execute("SELECT order_number FROM jobs").fetchall() == [("A-1",)]


def test_purge_old_removes_only_old_acked_rows():
    for i in (1, 2, 3):
        agent.enqueue(make_job(i))
    old = (datetime.now(timezone.utc) - timedelta(days=10)).isoformat()
    recent = datetime.now(timezone.utc).isoformat()
    agent.CONN.execute("UPDATE jobs SET printed_at = ?, acked_at = ? WHERE order_id = 1", (old, old))
    agent.CONN.execute("UPDATE jobs SET printed_at = ?, acked_at = ? WHERE order_id = 2", (recent, recent))
    agent.CONN.commit()  # 3 — chop etilmagan

    assert agent.purge_old(days=7) == 1

    ids = [r[0] for r in agent.CONN.execute("SELECT order_id FROM jobs ORDER BY order_id")]
    assert ids == [2, 3]


def test_log_goes_to_a_rotating_file(monkeypatch, tmp_path):
    monkeypatch.setattr(agent, "LOG_MAX_BYTES", 300)
    monkeypatch.setattr(agent, "LOG_BACKUPS", 2)
    path = tmp_path / "rot.log"

    try:
        logger = agent.setup_logging(path)
        handlers = [h for h in logger.handlers if isinstance(h, RotatingFileHandler)]
        assert len(handlers) == 1 and handlers[0].maxBytes == 300 and handlers[0].backupCount == 2

        for i in range(40):
            agent.log(f"qator {i} " + "x" * 30)

        assert path.exists() and (tmp_path / "rot.log.1").exists()
        assert "qator 39" in path.read_text(encoding="utf-8")
        assert not (tmp_path / "rot.log.3").exists(), "faqat LOG_BACKUPS ta zaxira fayl"
    finally:
        agent.setup_logging(TMP / "agent.log")


# ---------------------------------------------------------------- heartbeat
def test_heartbeat_posts_to_the_backend_with_the_token(monkeypatch):
    calls = []

    def post(url, **kw):
        calls.append((url, kw["headers"]["Authorization"]))
        return FakeResponse(200, {"ok": True})

    monkeypatch.setattr(agent.requests, "post", post)
    agent._heartbeat_ok["value"] = None

    assert agent.send_heartbeat() is True
    assert calls == [("http://backend.test/api/agent/heartbeat", "Bearer test-token")]


def test_heartbeat_failures_are_quiet_and_logged_only_on_state_change(monkeypatch, caplog):
    logged = []
    monkeypatch.setattr(agent, "log", lambda *a: logged.append(" ".join(str(x) for x in a)))
    outcomes = [agent.requests.ConnectionError("yo'q"), agent.requests.ConnectionError("yo'q"), FakeResponse(403), FakeResponse(200), FakeResponse(200)]

    def post(url, **kw):
        o = outcomes.pop(0)
        if isinstance(o, Exception):
            raise o
        return o

    monkeypatch.setattr(agent.requests, "post", post)
    agent._heartbeat_ok["value"] = True

    results = [agent.send_heartbeat() for _ in range(5)]

    assert results == [False, False, False, True, True]
    assert len(logged) == 2  # uzildi (1 marta) va tiklandi (1 marta)
    assert "heartbeat yuborilmadi" in logged[0] and "tiklandi" in logged[1]
