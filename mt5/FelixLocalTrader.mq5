//+------------------------------------------------------------------+
//| FelixLocalTrader.mq5                                             |
//| Felix v8.0 — Macro Sniper (Q-Invest Thầy Quyết 19/5)           |
//| Rule 1: Macro Swing (Str=12, Look=100) — chỉ bắt Kênh Giá lớn  |
//| Rule 2: Smart Trend & Wedge Filter                               |
//| Rule 3: Fixed Lot only                                           |
//| Rule 4: Max Trades/Day (0 = vô hạn)                             |
//| Pha 1: Lock BE tại 1.5 giá → SL=entry+0.3                       |
//| Pha 2: ATR Trail bám sát — sóng yếu tự chốt 3-5 giá            |
//| Pha 3: Hard TP=10 — sóng mạnh chốt ngay, không trailing         |
//+------------------------------------------------------------------+
#property copyright "Felix v8.0 — Macro Sniper"
#property version   "8.00"

//══════════════════════════════════════════════════════════════════
//  INPUTS
//══════════════════════════════════════════════════════════════════

// ── Rule 1: Swing Entry ──────────────────────────────────────────
input group           "RULE 1 — SWING ENTRY"
input int             SwingStrength     = 12;    // Số nến mỗi bên xác nhận Macro Swing (≥12 = chỉ bắt Kênh Giá lớn)
input int             SwingLookback     = 100;   // Số nến M15 tối đa quét tìm Macro Swing
input double          EntryBuffer       = 1.5;   // Đệm trên SwingHigh / dưới SwingLow
input double          FixedSL_Distance  = 5.0;   // SL cố định = entry ± N giá
input double          Fixed_TP_Points   = 0.0;   // Pha 3: Hard TP (0 = trailing thuần, >0 = chốt cứng)

// ── Rule 2: Smart Trend & Wedge Filter ──────────────────────────
input group           "RULE 2 — SMART TREND & WEDGE FILTER"
input bool            EnableWedgeFilter = true;  // false = cho phép cả 2 chiều
// DOWNTREND: Falling Wedge (dLows<dHighs×ratio) → BUY | plain → SELL
// UPTREND:   Rising Wedge  (dHighs<dLows×ratio) → SELL| plain → BUY
// SIDEWAY:   → cả BUY và SELL
input double          WedgeRatio        = 0.80;

// ── Rule 3: Lot Size ─────────────────────────────────────────────
input group           "RULE 3 — LOT SIZE"
input double          FixedLot          = 0.05;  // Volume cố định mỗi lệnh

// ── Rule 4: Max Trades/Day ───────────────────────────────────────
input group           "RULE 4 — KỶ LUẬT NGÀY"
input int             MaxTradesPerDay   = 0;     // Ngủ sau N lệnh filled trong ngày (0 = vô hạn)

// ── Break-Even + Dynamic ATR Trailing ───────────────────────────
input group           "BREAK-EVEN + TRAILING (GỒNG LÃI)"
input double          BE_Trigger        = 1.5;  // Lãi N giá → Early Lock (SL → entry+Lock_Profit)
input double          Lock_Profit       = 0.3;  // Khóa tại Entry + N (Pha B)
input double          Trail_Activation  = 4.0;  // Lãi N giá → force SL ≥ entry+BE_Trigger (Pha C)
input int             ATR_Period        = 14;   // ATR period để tính Dynamic Trail
input double          ATR_Multiplier    = 0.30; // Trail distance = ATR(nến vừa đóng) × hệ số này

// ── Order Management ─────────────────────────────────────────────
input group           "QUẢN LÝ LỆNH"
input int             OrderExpiryHrs    = 4;
input int             MagicNumber       = 700;
input bool            EnableTrading     = false;

//══════════════════════════════════════════════════════════════════
//  STRUCT + GLOBALS
//══════════════════════════════════════════════════════════════════

struct Trade
{
    int             id;
    ENUM_ORDER_TYPE type;
    double          entry;
    double          sl;
    double          tp;
    ulong           ticket;
    bool            filled;
    bool            active;
    bool            countedToday;     // đã tính vào quota hôm nay chưa
    bool            beActivated;     // Pha B Early Lock đã kích hoạt chưa
    bool            trailActivated;  // Pha C Trail Activation đã kích hoạt chưa
};

Trade    g_trades[];
int      g_count      = 0;
int      g_nextId     = 1;
datetime g_lastBar    = 0;
bool     g_ready      = false;
int      g_todayCount = 0;
datetime g_todayDate  = 0;
int      g_atrHandle  = INVALID_HANDLE;

//══════════════════════════════════════════════════════════════════
//  INIT / DEINIT
//══════════════════════════════════════════════════════════════════

int OnInit()
{
    g_atrHandle = iATR(_Symbol, PERIOD_M15, ATR_Period);
    if (g_atrHandle == INVALID_HANDLE) {
        Print("Felix v8.0: FAILED to create ATR handle — retcode=", GetLastError());
        return INIT_FAILED;
    }

    Print(StringFormat(
        "Felix v8.0 MACRO SNIPER | Magic=%d | MacroSwing=%d×%d | Wedge=%s(%.0f%%) | Lot=%.2f | SL=±%.1f HardTP=+%.1f | Ph1@%.1f→+%.2f | Ph3@%.1f→+%.2f | ATR(%d)×%.2f | MaxDay=%d | Trading=%s",
        MagicNumber, SwingStrength, SwingLookback,
        EnableWedgeFilter ? "ON" : "OFF", WedgeRatio * 100,
        FixedLot, FixedSL_Distance, Fixed_TP_Points,
        BE_Trigger, Lock_Profit, Trail_Activation, BE_Trigger,
        ATR_Period, ATR_Multiplier, MaxTradesPerDay,
        EnableTrading ? "ON" : "OFF(LOG)"));
    return INIT_SUCCEEDED;
}

void OnDeinit(const int reason)
{
    if (g_atrHandle != INVALID_HANDLE) {
        IndicatorRelease(g_atrHandle);
        g_atrHandle = INVALID_HANDLE;
    }
    Print("Felix v8.0: Deinit reason=", reason);
}

//══════════════════════════════════════════════════════════════════
//  ONTICK
//══════════════════════════════════════════════════════════════════

void OnTick()
{
    if (!g_ready) {
        if (iBars(_Symbol, PERIOD_M15) < SwingLookback + SwingStrength * 2 + 5) return;
        if (g_atrHandle == INVALID_HANDLE) return;
        double _atrCheck[1];
        if (CopyBuffer(g_atrHandle, 0, 1, 1, _atrCheck) < 1) return; // ATR chưa có đủ data
        g_ready   = true;
        g_lastBar = iTime(_Symbol, PERIOD_M15, 0);
        Print("Felix v8.0: Data sẵn sàng — bắt đầu scan");
        ResetDayCounterIfNeeded();
        SyncState();
        ScanSetup();
        return;
    }

    // ── Chạy MỌI TICK — quản trị lệnh real-time ──────────────────
    ManageTrailingStop();

    // ── Chỉ chạy khi có nến M15 mới đóng ─────────────────────────
    datetime barNow = iTime(_Symbol, PERIOD_M15, 0);
    if (barNow == g_lastBar) return;
    g_lastBar = barNow;

    ResetDayCounterIfNeeded();
    SyncState();
    ScanSetup();
}

//══════════════════════════════════════════════════════════════════
//  RULE 4 — ResetDayCounterIfNeeded()
//══════════════════════════════════════════════════════════════════

void ResetDayCounterIfNeeded()
{
    MqlDateTime dt;
    TimeToStruct(TimeCurrent(), dt);
    dt.hour = 0; dt.min = 0; dt.sec = 0;
    datetime today = StructToTime(dt);
    if (today != g_todayDate) {
        if (g_todayDate != 0)
            Print(StringFormat("Felix: Ngày mới %s — reset quota 0/%d",
                  TimeToString(today, TIME_DATE), MaxTradesPerDay));
        g_todayDate  = today;
        g_todayCount = 0;
    }
}

//══════════════════════════════════════════════════════════════════
//  RULE 1 — FindM15Swings()
//  Tìm 2 SwingHigh + 2 SwingLow gần nhất trên M15 (fractal)
//  highs[0]/lows[0] = gần nhất, [1] = cũ hơn
//══════════════════════════════════════════════════════════════════

void FindM15Swings(double &highs[], double &lows[])
{
    ArrayResize(highs, 0);
    ArrayResize(lows,  0);

    int sw    = SwingStrength;
    int total = iBars(_Symbol, PERIOD_M15);
    int limit = SwingLookback + sw + 1;

    for (int i = sw + 1; i <= limit && i + sw < total; i++)
    {
        if (ArraySize(highs) >= 2 && ArraySize(lows) >= 2) break;

        double h = iHigh(_Symbol, PERIOD_M15, i);
        double l = iLow (_Symbol, PERIOD_M15, i);

        bool isHigh = (ArraySize(highs) < 2);
        bool isLow  = (ArraySize(lows)  < 2);

        for (int j = 1; j <= sw && (isHigh || isLow); j++) {
            if (isHigh) {
                if (iHigh(_Symbol, PERIOD_M15, i - j) >= h) isHigh = false;
                if (iHigh(_Symbol, PERIOD_M15, i + j) >= h) isHigh = false;
            }
            if (isLow) {
                if (iLow(_Symbol, PERIOD_M15, i - j) <= l) isLow = false;
                if (iLow(_Symbol, PERIOD_M15, i + j) <= l) isLow = false;
            }
        }

        if (isHigh) {
            int n = ArraySize(highs); ArrayResize(highs, n + 1);
            highs[n] = h;
        }
        if (isLow) {
            int n = ArraySize(lows); ArrayResize(lows, n + 1);
            lows[n] = l;
        }
    }
}

//══════════════════════════════════════════════════════════════════
//  RULE 2 — ClassifyDirections() — Smart Trend & Wedge Filter
//  sh1/sl1 = newest swing, sh2/sl2 = older swing
//
//  DOWNTREND (sh1<sh2 AND sl1<sl2):
//    Falling Wedge: dLows < dHighs×ratio → lực giảm cạn → BUY ONLY
//    Plain downtrend                      → SELL ONLY
//
//  UPTREND (sh1>sh2 AND sl1>sl2):
//    Rising Wedge: dHighs < dLows×ratio  → lực tăng cạn → SELL ONLY
//    Plain uptrend                        → BUY ONLY
//
//  SIDEWAY (đỉnh/đáy lộn xộn) → BUY & SELL
//══════════════════════════════════════════════════════════════════

void ClassifyDirections(double sh1, double sh2, double sl1, double sl2,
                        bool &allowBuy, bool &allowSell, string &reason)
{
    if (!EnableWedgeFilter) {
        allowBuy = true; allowSell = true;
        reason = "Filter OFF";
        return;
    }

    bool downtrend = (sh1 < sh2 && sl1 < sl2);
    bool uptrend   = (sh1 > sh2 && sl1 > sl2);

    if (downtrend) {
        double dHighs = sh2 - sh1;  // > 0
        double dLows  = sl2 - sl1;  // > 0
        if (dLows < dHighs * WedgeRatio) {
            allowBuy = true; allowSell = false;
            reason = StringFormat("DOWN+FALLING_WEDGE dL=%.2f<dH=%.2f×%.2f → BUY ONLY", dLows, dHighs, WedgeRatio);
        } else {
            allowBuy = false; allowSell = true;
            reason = StringFormat("DOWNTREND dH=%.2f dL=%.2f → SELL ONLY", dHighs, dLows);
        }
    } else if (uptrend) {
        double dHighs = sh1 - sh2;  // > 0
        double dLows  = sl1 - sl2;  // > 0
        if (dHighs < dLows * WedgeRatio) {
            allowBuy = false; allowSell = true;
            reason = StringFormat("UP+RISING_WEDGE dH=%.2f<dL=%.2f×%.2f → SELL ONLY", dHighs, dLows, WedgeRatio);
        } else {
            allowBuy = true; allowSell = false;
            reason = StringFormat("UPTREND dH=%.2f dL=%.2f → BUY ONLY", dHighs, dLows);
        }
    } else {
        allowBuy = true; allowSell = true;
        reason = StringFormat("SIDEWAY SH=%.2f/%.2f SL=%.2f/%.2f → BOTH", sh1, sh2, sl1, sl2);
    }
}

//══════════════════════════════════════════════════════════════════
//  SCAN SETUP — Gộp 4 Rules
//══════════════════════════════════════════════════════════════════

void ScanSetup()
{
    // ── Rule 4: Kiểm tra quota ngày ──────────────────────────────
    if (MaxTradesPerDay > 0 && g_todayCount >= MaxTradesPerDay) {
        static int qLog = 0;
        if (qLog++ % 4 == 0)
            Print(StringFormat("Felix [QUOTA] %d/%d lệnh hôm nay — ngủ đến 00:00",
                               g_todayCount, MaxTradesPerDay));
        return;
    }

    // ── Rule 1: Tìm swings M15 ────────────────────────────────────
    double highs[], lows[];
    FindM15Swings(highs, lows);

    if (ArraySize(highs) < 1 || ArraySize(lows) < 1) {
        Print("Felix [SKIP] Không tìm được Swing M15 đủ dùng");
        return;
    }

    double sh1 = highs[0]; // SwingHigh gần nhất
    double sl1 = lows[0];  // SwingLow gần nhất

    // ── Rule 2: Smart Trend & Wedge Filter ───────────────────────
    bool   allowBuy = true, allowSell = true;
    string trendReason = "1 swing only → BOTH";
    if (ArraySize(highs) >= 2 && ArraySize(lows) >= 2) {
        ClassifyDirections(sh1, highs[1], sl1, lows[1], allowBuy, allowSell, trendReason);
    }
    Print(StringFormat("Felix TREND | SH=%.2f/%.2f SL=%.2f/%.2f | %s",
                       sh1, ArraySize(highs)>=2?highs[1]:0,
                       sl1, ArraySize(lows)>=2?lows[1]:0,
                       trendReason));

    double ask = SymbolInfoDouble(_Symbol, SYMBOL_ASK);
    double bid = SymbolInfoDouble(_Symbol, SYMBOL_BID);

    // ── Broker-level guard: check thực tế trên broker (chống double-trade sau restart) ──
    bool brokerHasBuy  = false;
    bool brokerHasSell = false;
    for (int _p = PositionsTotal() - 1; _p >= 0; _p--) {
        ulong _tk = PositionGetTicket(_p);
        if (!PositionSelectByTicket(_tk)) continue;
        if (PositionGetInteger(POSITION_MAGIC)  != MagicNumber) continue;
        if (PositionGetString(POSITION_SYMBOL) != _Symbol)      continue;
        ENUM_POSITION_TYPE _pt = (ENUM_POSITION_TYPE)PositionGetInteger(POSITION_TYPE);
        if (_pt == POSITION_TYPE_BUY)  brokerHasBuy  = true;
        if (_pt == POSITION_TYPE_SELL) brokerHasSell = true;
    }
    for (int _o = OrdersTotal() - 1; _o >= 0; _o--) {
        ulong _tk = OrderGetTicket(_o);
        if (!OrderSelect(_tk)) continue;
        if (OrderGetInteger(ORDER_MAGIC)  != MagicNumber) continue;
        if (OrderGetString(ORDER_SYMBOL) != _Symbol)      continue;
        ENUM_ORDER_TYPE _ot = (ENUM_ORDER_TYPE)OrderGetInteger(ORDER_TYPE);
        if (_ot == ORDER_TYPE_BUY_STOP)  brokerHasBuy  = true;
        if (_ot == ORDER_TYPE_SELL_STOP) brokerHasSell = true;
    }
    bool hasBuy  = brokerHasBuy  || HasActive(ORDER_TYPE_BUY_STOP);
    bool hasSell = brokerHasSell || HasActive(ORDER_TYPE_SELL_STOP);

    // ── BUY STOP ──────────────────────────────────────────────────
    if (allowBuy && !hasBuy)
    {
        double entry = sh1 + EntryBuffer;
        double sl    = entry - FixedSL_Distance;

        if (entry <= ask) {
            Print(StringFormat("Felix [BUY SKIP] entry=%.2f <= ask=%.2f (breakout đã qua)", entry, ask));
        } else {
            double tp = Fixed_TP_Points > 0 ? entry + Fixed_TP_Points : 0.0;
            Print(StringFormat(
                "Felix [BUY] SH=%.2f | entry=%.2f sl=%.2f tp=%s | Lock@%.1f Act@%.1f | %s",
                sh1, entry, sl,
                tp > 0 ? StringFormat("%.2f(+%.1f)", tp, Fixed_TP_Points) : "TRAIL",
                BE_Trigger, Trail_Activation, trendReason));
            PlaceOrder(ORDER_TYPE_BUY_STOP, entry, sl, tp);
        }
    }

    // ── SELL STOP ─────────────────────────────────────────────────
    if (allowSell && !hasSell)
    {
        double entry = sl1 - EntryBuffer;
        double sl    = entry + FixedSL_Distance;

        if (entry >= bid) {
            Print(StringFormat("Felix [SELL SKIP] entry=%.2f >= bid=%.2f (breakout đã qua)", entry, bid));
        } else {
            double tp = Fixed_TP_Points > 0 ? entry - Fixed_TP_Points : 0.0;
            Print(StringFormat(
                "Felix [SELL] SL1=%.2f | entry=%.2f sl=%.2f tp=%s | Lock@%.1f Act@%.1f | %s",
                sl1, entry, sl,
                tp > 0 ? StringFormat("%.2f(-%.1f)", tp, Fixed_TP_Points) : "TRAIL",
                BE_Trigger, Trail_Activation, trendReason));
            PlaceOrder(ORDER_TYPE_SELL_STOP, entry, sl, tp);
        }
    }
}

//══════════════════════════════════════════════════════════════════
//  HELPERS
//══════════════════════════════════════════════════════════════════

void PlaceOrder(ENUM_ORDER_TYPE type, double entry, double sl, double tp)
{
    int    dg  = (int)SymbolInfoInteger(_Symbol, SYMBOL_DIGITS);
    double lot = FixedLot;
    if (lot <= 0) { Print("Felix: FixedLot = 0 — bỏ qua"); return; }

    Trade t;
    t.id             = g_nextId++;
    t.type           = type;
    t.entry          = NormalizeDouble(entry, dg);
    t.sl             = NormalizeDouble(sl,    dg);
    t.tp             = NormalizeDouble(tp,    dg);
    t.ticket         = 0;
    t.filled         = false;
    t.active         = true;
    t.countedToday   = false;
    t.beActivated    = false;
    t.trailActivated = false;

    if (EnableTrading) {
        MqlTradeRequest req = {}; MqlTradeResult res = {};
        req.action       = TRADE_ACTION_PENDING;
        req.symbol       = _Symbol;
        req.type         = type;
        req.volume       = lot;
        req.price        = t.entry;
        req.sl           = t.sl;
        req.tp           = t.tp;
        req.magic        = MagicNumber;
        req.comment      = StringFormat("Felix#%d", t.id);
        req.type_filling = ORDER_FILLING_RETURN;
        if (OrderExpiryHrs > 0) {
            req.type_time  = ORDER_TIME_SPECIFIED;
            req.expiration = TimeCurrent() + OrderExpiryHrs * 3600;
        }
        if (!OrderSend(req, res))
            Print(StringFormat("Felix OrderSend FAIL retcode=%d %s", res.retcode, res.comment));
        else if (res.retcode == TRADE_RETCODE_DONE || res.retcode == TRADE_RETCODE_PLACED)
            t.ticket = res.order;
        else { Print(StringFormat("Felix OrderSend retcode=%d — deactivate", res.retcode)); t.active = false; }
    }

    ArrayResize(g_trades, g_count + 1);
    g_trades[g_count] = t;
    g_count++;
}


void SyncState()
{
    for (int i = 0; i < g_count; i++) {
        if (!g_trades[i].active || g_trades[i].ticket == 0) continue;

        bool isPend = OrderSelect(g_trades[i].ticket);
        bool isPos  = PositionSelectByTicket(g_trades[i].ticket);

        if (!g_trades[i].filled && !isPend && isPos) {
            g_trades[i].filled = true;
            Print(StringFormat("Felix Sync [#%d]: FILLED ticket=%I64u", g_trades[i].id, g_trades[i].ticket));
        }

        // Rule 4: đếm lệnh filled vào quota ngày
        if (g_trades[i].filled && !g_trades[i].countedToday) {
            g_trades[i].countedToday = true;
            g_todayCount++;
            Print(StringFormat("Felix Quota [#%d]: %d/%d lệnh hôm nay",
                               g_trades[i].id, g_todayCount, MaxTradesPerDay));
        }

        if (!isPend && !isPos) {
            Print(StringFormat("Felix Sync [#%d]: Đóng (SL/TP/manual)", g_trades[i].id));
            g_trades[i].active = false;
        }
    }
}

bool HasActive(ENUM_ORDER_TYPE t)
{
    for (int i = 0; i < g_count; i++)
        if (g_trades[i].active && g_trades[i].type == t) return true;
    return false;
}

bool ClosePos(ulong ticket)
{
    if (!PositionSelectByTicket(ticket)) return false;
    string sym   = PositionGetString(POSITION_SYMBOL);
    double vol   = PositionGetDouble(POSITION_VOLUME);
    int    ptype = (int)PositionGetInteger(POSITION_TYPE);
    ENUM_ORDER_TYPE ct = (ptype == POSITION_TYPE_BUY) ? ORDER_TYPE_SELL : ORDER_TYPE_BUY;
    double px = (ct == ORDER_TYPE_SELL)
        ? SymbolInfoDouble(sym, SYMBOL_BID)
        : SymbolInfoDouble(sym, SYMBOL_ASK);
    MqlTradeRequest req = {}; MqlTradeResult res = {};
    req.action   = TRADE_ACTION_DEAL;
    req.symbol   = sym;
    req.volume   = vol;
    req.type     = ct;
    req.price    = NormalizeDouble(px, (int)SymbolInfoInteger(sym, SYMBOL_DIGITS));
    req.position = ticket;
    req.magic    = MagicNumber;
    req.comment  = "Felix#Close";
    if (!OrderSend(req, res)) return false;
    return (res.retcode == TRADE_RETCODE_DONE || res.retcode == TRADE_RETCODE_PLACED);
}

//══════════════════════════════════════════════════════════════════
//  MODIFY SL — dùng bởi ManageTrailingStop()
//══════════════════════════════════════════════════════════════════

bool ModifySL(ulong ticket, double newSL)
{
    if (!PositionSelectByTicket(ticket)) return false;
    int    dg = (int)SymbolInfoInteger(_Symbol, SYMBOL_DIGITS);
    double tp = PositionGetDouble(POSITION_TP); // giữ nguyên TP (= 0)

    MqlTradeRequest req = {}; MqlTradeResult res = {};
    req.action   = TRADE_ACTION_SLTP;
    req.symbol   = _Symbol;
    req.position = ticket;
    req.sl       = NormalizeDouble(newSL, dg);
    req.tp       = tp;
    req.magic    = MagicNumber;

    if (!OrderSend(req, res)) return false;
    return (res.retcode == TRADE_RETCODE_DONE);
}

//══════════════════════════════════════════════════════════════════
//  MANAGE TRAILING STOP — gọi MỌI TICK (real-time)
//
//  Pha A — Instant ATR Trail (NGAY TỪ TICK 1 SAU FILL, không chờ):
//    dynamicTrail = ATR(nến vừa đóng) × ATR_Multiplier
//    BUY:  newSL = bid − dynamicTrail  (chỉ tiến lên, không lùi)
//    SELL: newSL = ask + dynamicTrail  (chỉ tiến xuống, không lùi)
//
//  Pha B — Early Lock (một lần, khi lãi >= BE_Trigger):
//    BUY:  newSL = max(newSL, entry + Lock_Profit)
//    SELL: newSL = min(newSL, entry − Lock_Profit)
//    → Đảm bảo SL không bao giờ dưới entry + Lock_Profit sau khi kích hoạt
//══════════════════════════════════════════════════════════════════

void ManageTrailingStop()
{
    // ── Lấy ATR nến vừa đóng (index 1) — 1 lần cho toàn bộ tick ──
    double atrBuf[1];
    if (CopyBuffer(g_atrHandle, 0, 1, 1, atrBuf) < 1) return;
    double dynamicTrail = atrBuf[0] * ATR_Multiplier;
    if (dynamicTrail <= 0) return;

    int    dg  = (int)SymbolInfoInteger(_Symbol, SYMBOL_DIGITS);
    double bid = SymbolInfoDouble(_Symbol, SYMBOL_BID);
    double ask = SymbolInfoDouble(_Symbol, SYMBOL_ASK);

    for (int i = 0; i < g_count; i++)
    {
        if (!g_trades[i].active) continue;

        // Inline fill detection — không chờ SyncState (chạy mỗi 15 phút)
        if (!g_trades[i].filled && g_trades[i].ticket != 0) {
            if (!OrderSelect(g_trades[i].ticket) && PositionSelectByTicket(g_trades[i].ticket))
                g_trades[i].filled = true;
        }
        if (!g_trades[i].filled) continue;
        if (g_trades[i].ticket == 0 && EnableTrading) continue;

        bool   isBuy  = (g_trades[i].type == ORDER_TYPE_BUY_STOP);
        double entry  = g_trades[i].entry;
        double currSL = g_trades[i].sl;
        double newSL  = currSL;

        if (isBuy) {
            double profit = bid - entry;

            // ── Pha A: Instant ATR Trail (luôn chạy từ tick 1) ───
            double trailSL = NormalizeDouble(bid - dynamicTrail, dg);
            if (trailSL > newSL) {
                Print(StringFormat("Felix TRAIL [#%d]: SL %.2f → %.2f (bid=%.2f ATR=%.2f×%.2f=%.2f)",
                                   g_trades[i].id, newSL, trailSL, bid,
                                   atrBuf[0], ATR_Multiplier, dynamicTrail));
                newSL = trailSL;
            }

            // ── Pha B: Early Lock (một lần, lãi >= BE_Trigger=1.5) ─
            if (!g_trades[i].beActivated && profit >= BE_Trigger) {
                double lockSL = NormalizeDouble(entry + Lock_Profit, dg);
                if (newSL < lockSL) {
                    Print(StringFormat("Felix LOCK [#%d]: SL %.2f → entry+%.2f=%.2f (profit=+%.2f)",
                                       g_trades[i].id, newSL, Lock_Profit, lockSL, profit));
                    newSL = lockSL;
                }
                g_trades[i].beActivated = true;
            }

            // ── Pha C: Trail Activation (một lần, lãi >= Trail_Activation=4.0) ─
            if (!g_trades[i].trailActivated && profit >= Trail_Activation) {
                double activeSL = NormalizeDouble(entry + BE_Trigger, dg);
                if (newSL < activeSL) {
                    Print(StringFormat("Felix ACTIVE [#%d]: SL %.2f → entry+%.2f=%.2f (profit=+%.2f)",
                                       g_trades[i].id, newSL, BE_Trigger, activeSL, profit));
                    newSL = activeSL;
                }
                g_trades[i].trailActivated = true;
            }

        } else {
            // SELL
            double profit = entry - ask;

            // ── Pha A: Instant ATR Trail (luôn chạy từ tick 1) ───
            double trailSL = NormalizeDouble(ask + dynamicTrail, dg);
            if (trailSL < newSL) {
                Print(StringFormat("Felix TRAIL [#%d]: SL %.2f → %.2f (ask=%.2f ATR=%.2f×%.2f=%.2f)",
                                   g_trades[i].id, newSL, trailSL, ask,
                                   atrBuf[0], ATR_Multiplier, dynamicTrail));
                newSL = trailSL;
            }

            // ── Pha B: Early Lock (một lần, lãi >= BE_Trigger=1.5) ─
            if (!g_trades[i].beActivated && profit >= BE_Trigger) {
                double lockSL = NormalizeDouble(entry - Lock_Profit, dg);
                if (newSL > lockSL) {
                    Print(StringFormat("Felix LOCK [#%d]: SL %.2f → entry-%.2f=%.2f (profit=+%.2f)",
                                       g_trades[i].id, newSL, Lock_Profit, lockSL, profit));
                    newSL = lockSL;
                }
                g_trades[i].beActivated = true;
            }

            // ── Pha C: Trail Activation (một lần, lãi >= Trail_Activation=4.0) ─
            if (!g_trades[i].trailActivated && profit >= Trail_Activation) {
                double activeSL = NormalizeDouble(entry - BE_Trigger, dg);
                if (newSL > activeSL) {
                    Print(StringFormat("Felix ACTIVE [#%d]: SL %.2f → entry-%.2f=%.2f (profit=+%.2f)",
                                       g_trades[i].id, newSL, BE_Trigger, activeSL, profit));
                    newSL = activeSL;
                }
                g_trades[i].trailActivated = true;
            }
        }

        // ── Thực thi Modify SL nếu có thay đổi ───────────────────
        if (NormalizeDouble(newSL, dg) == NormalizeDouble(currSL, dg)) continue;

        if (EnableTrading) {
            if (ModifySL(g_trades[i].ticket, newSL))
                g_trades[i].sl = newSL;
            else
                Print(StringFormat("Felix ModifySL FAIL [#%d]: retcode check Journal", g_trades[i].id));
        } else {
            g_trades[i].sl = newSL;
            Print(StringFormat("Felix [LOG] SL [#%d]: %.2f → %.2f",
                               g_trades[i].id, currSL, newSL));
        }
    }
}
