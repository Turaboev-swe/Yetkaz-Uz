"""
Agent testlari. Modul import qilinishidan OLDIN env o'rnatiladi (queue.db, log, output — vaqtinchalik
papkada), shuning uchun haqiqiy .env / queue.db ga tegmaydi.
"""
import base64
import os
import sys
import tempfile
from pathlib import Path

import pytest

TMP = Path(tempfile.mkdtemp(prefix="yetkaz-agent-test-"))

os.environ.update({
    "RESTAURANT_ID": "7",
    "PRINT_AGENT_TOKEN": "test-token",
    "BACKEND_URL": "http://backend.test",
    "PRINTER_MODE": "simulate",
    "QUEUE_DB": str(TMP / "queue.db"),
    "OUTPUT_DIR": str(TMP / "output"),
    "LOG_FILE": str(TMP / "agent.log"),
})
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import agent  # noqa: E402

agent.setup_logging(TMP / "agent.log")


@pytest.fixture(autouse=True)
def clean_state():
    agent.CONN.execute("DELETE FROM jobs")
    agent.CONN.commit()
    agent._wake.clear()
    agent._state.update({"socket_id": None, "subscribed": False, "sub_errors": 0})
    yield


class FakeResponse:
    def __init__(self, status=200, payload=None, text=""):
        self.status_code = status
        self._payload = payload if payload is not None else {}
        self.text = text

    @property
    def ok(self):
        return self.status_code < 400

    def raise_for_status(self):
        if not self.ok:
            raise agent.requests.HTTPError(f"{self.status_code}")

    def json(self):
        return self._payload


class FakeWs:
    def __init__(self):
        self.sent = []
        self.closed = False

    def send(self, data):
        self.sent.append(data)

    def close(self):
        self.closed = True


def make_job(order_id=1, number=None, escpos=b"\x1b@TEST", text="CHEK"):
    return {
        "order_id": order_id,
        "order_number": number or f"A-{order_id}",
        "escpos": base64.b64encode(escpos).decode(),
        "text": text,
    }
