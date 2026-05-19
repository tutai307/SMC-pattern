# FelixAutoTrader.mq5 — Setup Guide

## Yeu cau
- MT5 Exness demo account dang chay
- Felix app deploy tren Railway (hoac chay localhost)
- PHP artisan migrate da chay tren server

---

## Buoc 1: Deploy Laravel changes

```bash
# Tren Railway (tu dong) hoac server cua ban:
php artisan migrate

# Them vao .env:
AUTO_TRADE_TOKEN=your-secret-token-dai-kho-doan
```

Sinh token ngau nhien:
```bash
php artisan tinker --execute="echo bin2hex(random_bytes(32));"
```

---

## Buoc 2: Copy EA vao MT5

Copy `FelixAutoTrader.mq5` vao:
```
C:\Users\[username]\AppData\Roaming\MetaQuotes\Terminal\[terminal-id]\MQL5\Experts\
```

Hoac: MT5 → File → Open Data Folder → MQL5 → Experts → paste file vao

Sau do: MT5 → MetaEditor (F4) → Compile (F7)
Kiem tra: khong co loi do o tab "Errors"

---

## Buoc 3: Enable WebRequest trong MT5

MT5 → Tools → Options → Expert Advisors:
- [x] Allow automated trading
- [x] Allow WebRequest for listed URL

Them URL Railway cua ban, vi du:
```
https://felix-production.railway.app
```

Luu y: Phai them CHINH XAC URL (khong them /api/...)

---

## Buoc 4: Attach EA vao chart

1. Mo chart bat ky (vi du XAUUSD M15)
2. Navigator (Ctrl+N) → Expert Advisors → FelixAutoTrader
3. Double-click hoac drag vao chart
4. Dien inputs:

| Input | Gia tri vi du | Mo ta |
|---|---|---|
| FelixUrl | https://felix-xxx.railway.app | URL Railway (khong co / cuoi) |
| AuthToken | your-secret-token | Giong AUTO_TRADE_TOKEN trong .env |
| EnableTrading | true | FALSE = chi log, KHONG dat lenh |
| SymbolSuffix | m | Exness dung XAUUSDm (them "m") |
| LotSize | 0.01 | Lot co dinh khi UseFixedLot=true |
| UseFixedLot | true | true=co dinh, false=tinh 2% von |
| AccountCapital | 500 | Von USD (dung khi UseFixedLot=false) |
| TimerSeconds | 60 | Poll moi 60 giay |
| MagicNumber | 20250520 | Giu nguyen hoac doi theo y muon |

5. Bam OK → EA hien ten goc tren phai chart

---

## Buoc 5: Kiem tra Journal

Tab Journal (hoac Alt+J) phai hien:

```
Felix Auto Trader v1.0 initialized | Magic: 20250520 | Poll: 60s | Trading: ON
```

Sau 60 giay dau tien:
```
Felix: Fetched 2 signal(s)
Felix: Lenh dat thanh cong — XAUUSDm LONG STOP @3320.00 | Ticket=12345 | Lot=0.01
Felix: ReportExecuted OK — signal #42 ticket=12345
```

Neu khong co signal:
```
Felix: Khong co pending signals
```

---

## Symbol Suffix

| Symbol Exness | SymbolSuffix | Ket qua |
|---|---|---|
| XAUUSDm | m | XAUUSD + "m" = XAUUSDm |
| XAUUSD (khong suffix) | (de trong) | XAUUSD |
| SOLUSDTm | m | SOLUSDT + "m" = SOLUSDTm |
| BTCUSDm | m | BTCUSD + "m" = BTCUSDm |

Xem danh sach symbol chinh xac: MT5 → Market Watch (Ctrl+M)

---

## Safety Checklist

Truoc khi bat EnableTrading=true:
- [ ] Journal hien "initialized" khong co loi
- [ ] Sau 60s: "Fetched N signals" hoac "Khong co pending signals" (khong phai WebRequest failed)
- [ ] Kiem tra symbol: XAUUSDm co trong Market Watch
- [ ] Kiem tra AUTO_TRADE_TOKEN khop giua .env va EA input
- [ ] Demo account (khong phai real money)

---

## Troubleshooting

**"WebRequest failed"**
→ Chua them URL vao MT5 Options → Expert Advisors → Allow WebRequest

**"Symbol khong tim thay: XAUUSDm"**
→ Kiem tra SymbolSuffix input. Mo Market Watch, tim ten chinh xac symbol.

**HTTP 401**
→ AuthToken sai. Kiem tra lai AUTO_TRADE_TOKEN trong .env va input EA.

**HTTP 404**
→ FelixUrl sai. Kiem tra URL Railway + `php artisan route:list | grep auto-trade`

**"TRADING DISABLED"**
→ Binh thuong. Set EnableTrading=true khi san sang.

**Lenh khong dat du co signal**
→ Kiem tra: EnableTrading=true, symbol dung, lot >= min lot san.
