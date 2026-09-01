# Yetkaz print agent

Oshxonadagi kompyuterda ishlaydi. Backend'ga WebSocket (Reverb) bilan ulanib,
yangi buyurtma chekini chop etadi.

## Ishga tushirish (oshxona kompyuteri)

```bash
cd print-agent
python3 -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env       # RESTAURANT_ID, AGENT_TOKEN, PRINTER_* ni to'ldiring
python agent.py
```

`AGENT_TOKEN` — admin panelда: **Restoranlar → <restoran> → POS → Print agent tokeni**.

## Rejimlar

| `PRINTER_MODE` | Nima qiladi |
|---|---|
| `simulate` | Chekni `print-agent/output/<raqam>.txt` ga yozadi va konsolga chiqaradi. Haqiqiy printer shart emas. |
| `network` | ESC/POS baytlarini `PRINTER_HOST:PRINTER_PORT` (odatда `:9100`) ga yuboradi. |

## Lokal navbat

Har chek `queue.db` (SQLite) ga yoziladi. Printer o'chgan yoki internet uzilgan
bo'lsa — chek yo'qolmaydi, agent qayta ulanганда yoki har 15 soniyада avtomat
qayta urinadi. Chop etilgach backend'ga tasdiq yuboradi (`orders.printed_at`).

## Docker bilan sinov (faqat lokal)

```bash
docker compose --profile agent up print-agent
```

> Haqiqiy oshxonada Docker shart emas — kompyuterда to'g'ridан-to'g'ri `python agent.py`.
