"""
USB rejimlari: PRINTER_MODE=windows (win32print — mock) va usb-linux (/dev/usb/lp0 o'rniga oddiy fayl).
Ishonchlilik qoidalari (yagona chop etuvchi, ack, tartib) network rejimidagidek ishlashi tekshiriladi.
"""
import sys
import threading
import time
import types

import pytest

import agent
from conftest import FakeResponse, make_job

STATUS_PAUSED = 0x1
STATUS_ERROR = 0x2
STATUS_PAPER_OUT = 0x10
STATUS_OFFLINE = 0x80
STATUS_BUSY = 0x200
STATUS_PRINTING = 0x400
STATUS_NOT_AVAILABLE = 0x1000
STATUS_DOOR_OPEN = 0x400000
ATTR_WORK_OFFLINE = 0x400


class FakeWinError(Exception):
    """pywintypes.error kabi — OSError EMAS."""


class FakeWin32Print(types.ModuleType):
    PRINTER_ENUM_LOCAL = 2
    PRINTER_ENUM_CONNECTIONS = 4

    def __init__(self, printers=("Microsoft Print to PDF", "XP-80T")):
        super().__init__("win32print")
        self.printers = list(printers)
        self.calls = []
        self.status = 0
        self.attributes = 0
        self.open_error = None
        self.start_error = None
        self.write_error = None
        self.get_printer_error = None
        self.short_write = False
        self.written = []  # har bir hujjat bayti

    # win32print API
    def EnumPrinters(self, flags, name, level):
        self.calls.append(("EnumPrinters", flags, level))
        return [(n, "", 0) for n in self.printers]  # level 4: (pPrinterName, pServerName, Attributes)

    def GetDefaultPrinter(self):
        return self.printers[0]

    def OpenPrinter(self, name):
        self.calls.append(("OpenPrinter", name))
        if self.open_error:
            raise self.open_error
        return "HANDLE"

    def GetPrinter(self, handle, level):
        self.calls.append(("GetPrinter", level))
        if self.get_printer_error:
            raise self.get_printer_error
        return {"Status": self.status, "Attributes": self.attributes}

    def StartDocPrinter(self, handle, level, doc):
        self.calls.append(("StartDocPrinter", level, doc))
        if self.start_error:
            raise self.start_error
        return 1

    def StartPagePrinter(self, handle):
        self.calls.append(("StartPagePrinter",))

    def WritePrinter(self, handle, data):
        self.calls.append(("WritePrinter", len(data)))
        if self.write_error:
            raise self.write_error
        self.written.append(bytes(data))
        return len(data) - 1 if self.short_write else len(data)

    def EndPagePrinter(self, handle):
        self.calls.append(("EndPagePrinter",))

    def EndDocPrinter(self, handle):
        self.calls.append(("EndDocPrinter",))

    def ClosePrinter(self, handle):
        self.calls.append(("ClosePrinter",))

    def names(self):
        return [c[0] for c in self.calls]


@pytest.fixture
def win(monkeypatch):
    fake = FakeWin32Print()
    monkeypatch.setitem(sys.modules, "win32print", fake)
    monkeypatch.setattr(agent, "PRINTER_MODE", "windows")
    monkeypatch.setattr(agent, "PRINTER_NAME", "XP-80T")
    monkeypatch.setattr(agent, "_is_windows", lambda: True)
    return fake


@pytest.fixture
def backend_ok(monkeypatch):
    acks = []

    def post(url, **kw):
        acks.append(url)
        return FakeResponse(200)

    monkeypatch.setattr(agent.requests, "post", post)
    return acks


# ---------------------------------------------------------------- windows: RAW yozish
def test_windows_sends_the_bytes_to_the_spooler_as_one_raw_document(win):
    data = b"\x1b@\x1bt\x10CHEK\n\x1dVB\x00"

    agent.print_receipt("A-7", data, "CHEK")

    assert win.names() == [
        "OpenPrinter", "GetPrinter", "StartDocPrinter", "StartPagePrinter", "WritePrinter",
        "EndPagePrinter", "EndDocPrinter", "ClosePrinter",
    ]
    assert ("OpenPrinter", "XP-80T") in win.calls
    assert ("StartDocPrinter", 1, ("Yetkaz A-7", None, "RAW")) in win.calls, "datatype RAW bo'lishi shart"
    assert win.written == [data], "baytlar o'zgarishsiz (qayta kodlanmasdan) yoziladi"


def test_windows_empty_printer_name_fails_and_lists_the_installed_printers(win, monkeypatch):
    monkeypatch.setattr(agent, "PRINTER_NAME", "")

    with pytest.raises(agent.PrinterConfigError) as e:
        agent.print_receipt("A-1", b"x", "t")

    message = str(e.value)
    assert "PRINTER_NAME" in message
    assert "XP-80T" in message and "Microsoft Print to PDF" in message
    assert "OpenPrinter" not in win.names()


def test_windows_printer_that_cannot_be_opened_is_unavailable_not_lost(win):
    win.open_error = FakeWinError(1801, "OpenPrinter", "Printer nomi noto'g'ri")

    with pytest.raises(agent.PrinterUnavailable) as e:
        agent.print_receipt("A-1", b"x", "t")

    assert isinstance(e.value, OSError), "process_queue shuni 'printer ulanmagan' deb biladi"
    assert "StartDocPrinter" not in win.names()


def test_windows_spooler_error_is_unavailable_and_the_handle_is_released(win):
    win.write_error = FakeWinError(31, "WritePrinter", "Qurilma ishlamayapti")

    with pytest.raises(agent.PrinterUnavailable):
        agent.print_receipt("A-1", b"x", "t")

    assert "EndDocPrinter" in win.names() and win.names()[-1] == "ClosePrinter"


def test_windows_start_doc_error_is_unavailable(win):
    win.start_error = FakeWinError(1722, "StartDocPrinter", "RPC yo'q")

    with pytest.raises(agent.PrinterUnavailable):
        agent.print_receipt("A-1", b"x", "t")

    assert win.names()[-1] == "ClosePrinter"


@pytest.mark.parametrize(
    "status,attributes",
    [
        (STATUS_OFFLINE, 0),
        (STATUS_PAPER_OUT, 0),
        (STATUS_DOOR_OPEN, 0),
        (STATUS_ERROR, 0),
        (STATUS_PAUSED, 0),
        (STATUS_NOT_AVAILABLE, 0),
        (0, ATTR_WORK_OFFLINE),
    ],
)
def test_windows_offline_or_faulty_printer_is_not_written_to(win, status, attributes):
    win.status, win.attributes = status, attributes

    with pytest.raises(agent.PrinterUnavailable):
        agent.print_receipt("A-1", b"x", "t")

    assert "WritePrinter" not in win.names(), "Windows spooler'ga ish to'plab qo'yilmaydi — navbat agentda"
    assert win.names()[-1] == "ClosePrinter"


@pytest.mark.parametrize("status", [0, STATUS_BUSY, STATUS_PRINTING, STATUS_BUSY | STATUS_PRINTING])
def test_windows_busy_or_printing_is_fine(win, status):
    win.status = status

    agent.print_receipt("A-1", b"x", "t")

    assert win.written == [b"x"]


def test_windows_unreadable_status_does_not_block_printing(win):
    win.get_printer_error = FakeWinError(5, "GetPrinter", "Ruxsat yo'q")

    agent.print_receipt("A-1", b"x", "t")

    assert win.written == [b"x"]


def test_windows_short_write_is_an_error(win):
    win.short_write = True

    with pytest.raises(agent.PrinterUnavailable) as e:
        agent.print_receipt("A-1", b"abcdef", "t")

    assert "5/6" in str(e.value)


def test_missing_pywin32_gives_an_actionable_message(monkeypatch):
    monkeypatch.setitem(sys.modules, "win32print", None)  # import -> ImportError
    monkeypatch.setattr(agent, "PRINTER_MODE", "windows")
    monkeypatch.setattr(agent, "PRINTER_NAME", "XP-80T")

    with pytest.raises(agent.PrinterConfigError) as e:
        agent.print_receipt("A-1", b"x", "t")

    assert "pip install" in str(e.value) and "pywin32" in str(e.value)


# ---------------------------------------------------------------- ishonchlilik: network rejimidagidek
def test_offline_windows_printer_keeps_orders_queued_in_order_and_retries(win, backend_ok):
    win.status = STATUS_OFFLINE
    for i in (1, 2, 3):
        agent.enqueue(make_job(i, escpos=f"CHEK{i}".encode()))
        time.sleep(0.002)

    agent.process_queue()

    assert win.names().count("OpenPrinter") == 1, "har bir buyurtma uchun qayta urinilmaydi (8 s kutish yo'q)"
    assert [r[0] for r in agent.pending()] == [1, 2, 3]
    assert backend_ok == [], "chop etilmagan buyurtma tasdiqlanmaydi"

    win.status = 0  # printer yoqildi / USB ulandi
    agent.process_queue()

    assert win.written == [b"CHEK1", b"CHEK2", b"CHEK3"], "tartib saqlangan, har biri bir marta"
    assert agent.pending() == []
    assert len(backend_ok) == 3


def test_windows_spooler_failure_mid_queue_does_not_lose_or_duplicate(win, backend_ok):
    agent.enqueue(make_job(1, escpos=b"ONE"))
    time.sleep(0.002)
    agent.enqueue(make_job(2, escpos=b"TWO"))
    win.write_error = FakeWinError(31, "WritePrinter", "xato")

    agent.process_queue()
    assert win.written == [] and [r[0] for r in agent.pending()] == [1, 2]

    win.write_error = None
    agent.process_queue()
    agent.process_queue()

    assert win.written == [b"ONE", b"TWO"]


def test_windows_concurrent_passes_print_a_job_once(win, backend_ok, monkeypatch):
    real = win.WritePrinter

    def slow_write(handle, data):
        time.sleep(0.2)
        return real(handle, data)

    monkeypatch.setattr(win, "WritePrinter", slow_write)
    agent.enqueue(make_job(1, escpos=b"ONE"))

    threads = [threading.Thread(target=agent.process_queue) for _ in range(4)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()

    assert win.written == [b"ONE"]


def test_windows_printed_but_unacked_is_acked_later_without_reprinting(win, monkeypatch):
    online = {"ok": False}
    acks = []

    def post(url, **kw):
        if not online["ok"]:
            raise agent.requests.ConnectionError("internet yo'q")
        acks.append(url)
        return FakeResponse(200)

    monkeypatch.setattr(agent.requests, "post", post)
    agent.enqueue(make_job(1, escpos=b"ONE"))

    agent.process_queue()
    online["ok"] = True
    agent.process_queue()

    assert win.written == [b"ONE"] and len(acks) == 1


def test_config_error_does_not_mark_the_job_printed(win, monkeypatch, backend_ok):
    monkeypatch.setattr(agent, "PRINTER_NAME", "")
    agent.enqueue(make_job(1))

    agent.process_queue()

    assert [r[0] for r in agent.pending()] == [1] and backend_ok == []


# ---------------------------------------------------------------- sozlamani tekshirish va --list-printers
def test_validate_accepts_a_correct_windows_setup(win):
    assert agent.validate_printer_config() is None


def test_validate_requires_printer_name_and_shows_choices(win, monkeypatch):
    monkeypatch.setattr(agent, "PRINTER_NAME", "")

    message = agent.validate_printer_config()

    assert "PRINTER_NAME" in message and "XP-80T" in message


def test_validate_rejects_an_unknown_printer_name(win, monkeypatch):
    monkeypatch.setattr(agent, "PRINTER_NAME", "XP80")  # xato yozilgan

    message = agent.validate_printer_config()

    assert "«XP80»" in message and "XP-80T" in message


def test_validate_windows_mode_on_another_os(win, monkeypatch):
    monkeypatch.setattr(agent, "_is_windows", lambda: False)

    assert "faqat Windows" in agent.validate_printer_config()


def test_validate_unknown_mode_and_network_without_host(monkeypatch):
    monkeypatch.setattr(agent, "PRINTER_MODE", "lpt")
    assert "usb-linux" in agent.validate_printer_config()

    monkeypatch.setattr(agent, "PRINTER_MODE", "network")
    monkeypatch.setattr(agent, "PRINTER_HOST", "")
    assert "PRINTER_HOST" in agent.validate_printer_config()

    monkeypatch.setattr(agent, "PRINTER_MODE", "simulate")
    assert agent.validate_printer_config() is None


def test_list_printers_flag_prints_names_without_needing_a_token(win, monkeypatch, capsys):
    monkeypatch.setattr(agent, "AGENT_TOKEN", "")
    monkeypatch.setattr(agent, "RESTAURANT_ID", 0)

    code = agent.main(["--list-printers"])

    out = capsys.readouterr().out
    assert code == 0
    assert "  - XP-80T" in out and "  - Microsoft Print to PDF" in out
    assert "PRINTER_NAME=" in out
    assert "standart printeri: Microsoft Print to PDF" in out


def test_list_printers_with_no_printers_explains_what_to_do(win, capsys):
    win.printers = []

    assert agent.main(["--list-printers"]) == 0

    assert "drayverini" in capsys.readouterr().out


def test_list_printers_outside_windows_says_so(monkeypatch, capsys):
    monkeypatch.setattr(agent, "_is_windows", lambda: False)

    assert agent.main(["--list-printers"]) == 1
    assert "faqat Windows" in capsys.readouterr().out


def test_list_printers_reports_missing_pywin32(monkeypatch, capsys):
    monkeypatch.setitem(sys.modules, "win32print", None)
    monkeypatch.setattr(agent, "_is_windows", lambda: True)

    assert agent.main(["--list-printers"]) == 1
    assert "pip install" in capsys.readouterr().out


def test_list_printers_accepts_dict_rows(win, monkeypatch):
    monkeypatch.setattr(win, "EnumPrinters", lambda flags, name, level: [{"pPrinterName": "A"}, {"pPrinterName": "B"}])

    assert agent.list_printers() == ["A", "B"]


# ---------------------------------------------------------------- usb-linux
def test_usb_linux_writes_the_bytes_to_the_device_file(monkeypatch, tmp_path):
    device = tmp_path / "lp0"
    device.write_bytes(b"")
    monkeypatch.setattr(agent, "PRINTER_MODE", "usb-linux")
    monkeypatch.setattr(agent, "PRINTER_DEVICE", str(device))

    agent.print_receipt("A-1", b"\x1b@CHEK\n", "CHEK")

    assert device.read_bytes() == b"\x1b@CHEK\n"


def test_usb_linux_missing_device_keeps_the_order_queued_then_prints(monkeypatch, tmp_path, backend_ok):
    device = tmp_path / "lp0"
    monkeypatch.setattr(agent, "PRINTER_MODE", "usb-linux")
    monkeypatch.setattr(agent, "PRINTER_DEVICE", str(tmp_path / "yoq" / "lp0"))  # papka yo'q -> FileNotFoundError
    agent.enqueue(make_job(1, escpos=b"ONE"))
    agent.enqueue(make_job(2, escpos=b"TWO"))

    agent.process_queue()
    assert [r[0] for r in agent.pending()] == [1, 2]

    monkeypatch.setattr(agent, "PRINTER_DEVICE", str(device))  # USB ulandi
    agent.process_queue()

    assert agent.pending() == []
    assert device.read_bytes() == b"TWO", "oddiy fayl har safar qayta yoziladi; muhimi — ikkalasi ham chop etildi"
    assert len(backend_ok) == 2


def test_usb_linux_default_device_path():
    assert agent.PRINTER_DEVICE == "/dev/usb/lp0"
