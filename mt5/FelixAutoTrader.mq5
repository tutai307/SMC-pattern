//+------------------------------------------------------------------+
//| FelixAutoTrader.mq5                                              |
//| Felix Price Action Terminal — MT5 Auto Execution EA             |
//| Version: 1.0 | 2025-05-20                                       |
//|                                                                  |
//| Poll Railway API moi 60s, dat lenh STOP/LIMIT tu dong,          |
//| bao cao ket qua ve server khi lenh dong.                        |
//+------------------------------------------------------------------+
#property copyright "Felix Price Action Terminal"
#property link      ""
#property version   "1.00"
#property strict

//+------------------------------------------------------------------+
//| Input Parameters                                                 |
//+------------------------------------------------------------------+
input string FelixUrl       = "https://smc-pattern-production.up.railway.app"; // Railway URL
input string AuthToken      = "f982222e711be6afc6412753a8b86d633b7ad7048bfba9c8"; // AUTO_TRADE_TOKEN
input double AccountCapital = 500.0;        // Von demo ($) — dung khi UseFixedLot=false
input double LotSize        = 0.01;         // Lot co dinh (dung khi UseFixedLot=true)
input bool   UseFixedLot    = true;         // true=lot co dinh, false=tinh tu von 2% risk
input int    TimerSeconds   = 60;           // Poll interval (giay)
input int    MagicNumber    = 20250520;     // Magic number Felix
input string SymbolSuffix   = "";           // Suffix Exness: "m" -> XAUUSDm, "" -> XAUUSD
input int    MaxSlippage    = 30;           // Max slippage (points)
input bool   EnableTrading  = false;        // FALSE = chi log, khong dat lenh (safety switch)

//+------------------------------------------------------------------+
//| Signal Struct                                                    |
//+------------------------------------------------------------------+
struct FelixSignal
{
    int    id;
    string symbol;
    string type;        // "LONG" / "SHORT"
    string order_type;  // "STOP" / "LIMIT"
    double entry;
    double tp;
    double sl;
    double lot;
};

//+------------------------------------------------------------------+
//| Global State                                                     |
//+------------------------------------------------------------------+
int     g_trackedIds[];
ulong   g_trackedTickets[];
int     g_trackedCount = 0;

//+------------------------------------------------------------------+
//| OnInit                                                           |
//+------------------------------------------------------------------+
int OnInit()
{
    if (!EnableTrading)
        Print("Felix AutoTrader: TRADING DISABLED — set EnableTrading=true de bat lenh that");

    if (StringLen(AuthToken) == 0)
    {
        Print("Felix AutoTrader: CANH BAO — AuthToken trong. Dien token vao input.");
        return INIT_PARAMETERS_INCORRECT;
    }

    EventSetTimer(TimerSeconds);

    Print("Felix Auto Trader v1.0 initialized | Magic: ", MagicNumber,
          " | Poll: ", TimerSeconds, "s | Trading: ", EnableTrading ? "ON" : "OFF");

    return INIT_SUCCEEDED;
}

//+------------------------------------------------------------------+
//| OnDeinit                                                         |
//+------------------------------------------------------------------+
void OnDeinit(const int reason)
{
    EventKillTimer();
    Print("Felix AutoTrader: EA gỡ khỏi chart. Reason: ", reason);
}

//+------------------------------------------------------------------+
//| OnTimer — goi moi TimerSeconds giay                             |
//+------------------------------------------------------------------+
void OnTimer()
{
    FetchAndTrade();
    CheckClosedPositions();
}

//+------------------------------------------------------------------+
//| FetchAndTrade — lay signals moi, dat lenh                       |
//+------------------------------------------------------------------+
void FetchAndTrade()
{
    string url     = FelixUrl + "/api/auto-trade/signals";
    string headers = "X-Auto-Trade-Token: " + AuthToken + "\r\nAccept: application/json\r\n";

    char   reqBody[];
    char   resBody[];
    string resHeaders;

    int httpCode = WebRequest("GET", url, headers, 5000, reqBody, resBody, resHeaders);

    if (httpCode == -1)
    {
        Print("Felix: WebRequest failed. Kiem tra URL + MT5 Options > Expert Advisors > Allow WebRequest");
        return;
    }

    if (httpCode != 200)
    {
        Print("Felix: API tra ve HTTP ", httpCode);
        return;
    }

    string json = CharArrayToString(resBody, 0, ArraySize(resBody));
    StringTrimLeft(json);
    StringTrimRight(json);

    if (json == "" || json == "[]")
    {
        Print("Felix: Khong co pending signals");
        return;
    }

    FelixSignal signals[];
    int count = ParseSignalsJson(json, signals);

    Print("Felix: Fetched ", count, " signal(s)");

    for (int i = 0; i < count; i++)
    {
        if (AlreadyTracked(signals[i].id))
        {
            Print("Felix: Signal #", signals[i].id, " da duoc track, bo qua");
            continue;
        }

        if (!EnableTrading)
        {
            Print("Felix [DEMO-LOG]: Would trade signal #", signals[i].id,
                  " | ", signals[i].symbol, " ", signals[i].type,
                  " ", signals[i].order_type,
                  " @", signals[i].entry,
                  " TP:", signals[i].tp, " SL:", signals[i].sl,
                  " Lot:", signals[i].lot);
            continue;
        }

        ulong ticket = PlaceOrder(signals[i]);

        if (ticket > 0)
        {
            AddTracked(signals[i].id, ticket);
            ReportExecuted(signals[i].id, ticket);
        }
    }
}

//+------------------------------------------------------------------+
//| PlaceOrder — dat lenh pending tren MT5                          |
//+------------------------------------------------------------------+
ulong PlaceOrder(FelixSignal &signal)
{
    string sym = signal.symbol + SymbolSuffix;

    if (!SymbolSelect(sym, true))
    {
        Print("Felix: Symbol khong tim thay: ", sym, " (thu SymbolSuffix khac)");
        return 0;
    }

    // --- Lot ---
    double lot;
    if (UseFixedLot)
    {
        lot = LotSize;
    }
    else
    {
        lot = (signal.lot > 0) ? signal.lot : 0.01;
    }

    double minLot  = SymbolInfoDouble(sym, SYMBOL_VOLUME_MIN);
    double stepLot = SymbolInfoDouble(sym, SYMBOL_VOLUME_STEP);

    lot = NormalizeDouble(lot, 2);
    if (lot < minLot)
        lot = minLot;

    // Lam tron theo step
    if (stepLot > 0)
        lot = MathFloor(lot / stepLot) * stepLot;

    // --- Map type + order_type -> MT5 enum ---
    ENUM_ORDER_TYPE orderType;
    bool isLong  = (signal.type == "LONG");
    bool isStop  = (signal.order_type == "STOP");

    if (isLong && isStop)
        orderType = ORDER_TYPE_BUY_STOP;
    else if (isLong && !isStop)
        orderType = ORDER_TYPE_BUY_LIMIT;
    else if (!isLong && isStop)
        orderType = ORDER_TYPE_SELL_STOP;
    else
        orderType = ORDER_TYPE_SELL_LIMIT;

    int digits = (int) SymbolInfoInteger(sym, SYMBOL_DIGITS);

    MqlTradeRequest req = {};
    req.action         = TRADE_ACTION_PENDING;
    req.symbol         = sym;
    req.volume         = lot;
    req.price          = NormalizeDouble(signal.entry, digits);
    req.sl             = NormalizeDouble(signal.sl, digits);
    req.tp             = NormalizeDouble(signal.tp, digits);
    req.type           = orderType;
    req.magic          = MagicNumber;
    req.comment        = "Felix#" + IntegerToString(signal.id);
    req.type_filling   = ORDER_FILLING_RETURN;
    req.deviation      = MaxSlippage;

    MqlTradeResult res = {};

    if (!OrderSend(req, res))
    {
        Print("Felix: OrderSend() failed. Retcode=", res.retcode, " | ", res.comment);
        return 0;
    }

    if (res.retcode == TRADE_RETCODE_DONE || res.retcode == TRADE_RETCODE_PLACED)
    {
        Print("Felix: Lenh dat thanh cong — ", sym, " ", signal.type, " ", signal.order_type,
              " @", req.price, " | Ticket=", res.order, " | Lot=", lot);
        return res.order;
    }

    Print("Felix: OrderSend tra ve retcode=", res.retcode, " (", res.comment, ") — lenh KHONG dat");
    return 0;
}

//+------------------------------------------------------------------+
//| CheckClosedPositions — phat hien lenh da dong, bao ve server   |
//+------------------------------------------------------------------+
void CheckClosedPositions()
{
    if (g_trackedCount == 0)
        return;

    datetime fromTime = TimeCurrent() - 7 * 24 * 3600; // Look back 7 ngay
    HistorySelect(fromTime, TimeCurrent());

    for (int i = g_trackedCount - 1; i >= 0; i--)
    {
        ulong ticket = g_trackedTickets[i];
        int   id     = g_trackedIds[i];

        // Con la pending order? → bo qua
        if (OrderSelect(ticket))
            continue;

        // Con la vi the dang mo? → bo qua
        if (PositionSelectByTicket(ticket))
            continue;

        // Lenh da dong — tim trong history
        double closePrice = 0.0;
        double profit     = 0.0;
        bool   found      = false;

        // Quet deals trong history de tim deal dong lenh
        int dealCount = HistoryDealsTotal();
        for (int d = 0; d < dealCount; d++)
        {
            ulong dealTicket = HistoryDealGetTicket(d);
            if (dealTicket == 0)
                continue;

            // Deal phai thuoc order/position nay
            ulong dealOrder    = (ulong) HistoryDealGetInteger(dealTicket, DEAL_ORDER);
            ulong dealPosition = (ulong) HistoryDealGetInteger(dealTicket, DEAL_POSITION_ID);

            if (dealOrder != ticket && dealPosition != ticket)
                continue;

            ENUM_DEAL_ENTRY entry = (ENUM_DEAL_ENTRY) HistoryDealGetInteger(dealTicket, DEAL_ENTRY);
            if (entry == DEAL_ENTRY_OUT || entry == DEAL_ENTRY_OUT_BY)
            {
                closePrice = HistoryDealGetDouble(dealTicket, DEAL_PRICE);
                profit    += HistoryDealGetDouble(dealTicket, DEAL_PROFIT);
                found      = true;
            }
        }

        if (!found)
        {
            // Kiem tra lich su order — co the la lenh pending bi huy
            if (HistoryOrderSelect(ticket))
            {
                ENUM_ORDER_STATE state = (ENUM_ORDER_STATE) HistoryOrderGetInteger(ticket, ORDER_STATE);
                if (state == ORDER_STATE_CANCELED || state == ORDER_STATE_EXPIRED)
                {
                    Print("Felix: Lenh #", ticket, " (signal #", id, ") da bi huy/het han — xoa khoi track");
                    RemoveTracked(i);
                    continue;
                }
            }
            continue; // chua tim thay ket qua ro rang
        }

        string status = (profit >= 0) ? "WIN" : "LOSS";
        Print("Felix: Lenh #", ticket, " (signal #", id, ") dong — ",
              status, " | ClosePrice=", closePrice, " | Profit=", profit);

        ReportClosed(id, status, closePrice);
        RemoveTracked(i);
    }
}

//+------------------------------------------------------------------+
//| ParseSignalsJson — parse JSON array tu server                   |
//+------------------------------------------------------------------+
int ParseSignalsJson(string json, FelixSignal &signals[])
{
    ArrayResize(signals, 0);
    int count = 0;

    int pos = 0;
    int jsonLen = StringLen(json);

    while (pos < jsonLen)
    {
        // Tim dau '{' cua object tiep theo
        int start = StringFind(json, "{", pos);
        if (start == -1)
            break;

        // Tim dau '}' dong object
        int depth = 0;
        int end   = -1;
        for (int k = start; k < jsonLen; k++)
        {
            ushort ch = StringGetCharacter(json, k);
            if (ch == '{') depth++;
            if (ch == '}') depth--;
            if (depth == 0) { end = k; break; }
        }

        if (end == -1)
            break;

        string obj = StringSubstr(json, start, end - start + 1);

        FelixSignal sig;
        sig.id         = ExtractInt(obj, "\"id\"");
        sig.symbol     = ExtractStr(obj, "\"symbol\"");
        sig.type       = ExtractStr(obj, "\"type\"");
        sig.order_type = ExtractStr(obj, "\"order_type\"");
        sig.entry      = ExtractDbl(obj, "\"entry_price\"");
        sig.tp         = ExtractDbl(obj, "\"tp_price\"");
        sig.sl         = ExtractDbl(obj, "\"sl_price\"");
        sig.lot        = ExtractDbl(obj, "\"lot_size\"");

        // Default fallbacks
        if (sig.order_type == "") sig.order_type = "STOP";
        if (sig.lot <= 0)         sig.lot        = 0.01;

        if (sig.id > 0 && sig.symbol != "" && sig.entry > 0)
        {
            ArrayResize(signals, count + 1);
            signals[count] = sig;
            count++;
        }

        pos = end + 1;
    }

    return count;
}

//+------------------------------------------------------------------+
//| ExtractStr — lay gia tri string tu JSON key                     |
//+------------------------------------------------------------------+
string ExtractStr(string json, string key)
{
    int keyPos = StringFind(json, key);
    if (keyPos == -1)
        return "";

    int colon = StringFind(json, ":", keyPos);
    if (colon == -1)
        return "";

    // Tìm ký tự đầu tiên không phải khoảng trắng sau dấu ':'
    int valStart = colon + 1;
    int jsonLen  = StringLen(json);

    while (valStart < jsonLen && StringGetCharacter(json, valStart) == ' ')
        valStart++;

    if (valStart >= jsonLen)
        return "";

    ushort firstChar = StringGetCharacter(json, valStart);

    if (firstChar == '"')
    {
        // Chuỗi có dấu nháy
        int strStart = valStart + 1;
        int strEnd   = StringFind(json, "\"", strStart);
        if (strEnd == -1)
            return "";
        return StringSubstr(json, strStart, strEnd - strStart);
    }
    else if (firstChar == 'n')
    {
        // null
        return "";
    }

    return "";
}

//+------------------------------------------------------------------+
//| ExtractDbl — lay gia tri number (double) tu JSON key            |
//+------------------------------------------------------------------+
double ExtractDbl(string json, string key)
{
    int keyPos = StringFind(json, key);
    if (keyPos == -1)
        return 0.0;

    int colon = StringFind(json, ":", keyPos);
    if (colon == -1)
        return 0.0;

    int valStart = colon + 1;
    int jsonLen  = StringLen(json);

    while (valStart < jsonLen && StringGetCharacter(json, valStart) == ' ')
        valStart++;

    // Doc den ky tu phan cach
    string numStr = "";
    for (int k = valStart; k < jsonLen; k++)
    {
        ushort ch = StringGetCharacter(json, k);
        if (ch == ',' || ch == '}' || ch == ']' || ch == ' ' || ch == '\n' || ch == '\r')
            break;
        numStr += ShortToString(ch);
    }

    if (numStr == "null" || numStr == "")
        return 0.0;

    return StringToDouble(numStr);
}

//+------------------------------------------------------------------+
//| ExtractInt — lay gia tri integer tu JSON key                    |
//+------------------------------------------------------------------+
int ExtractInt(string json, string key)
{
    return (int) ExtractDbl(json, key);
}

//+------------------------------------------------------------------+
//| ReportExecuted — POST /api/auto-trade/signals/{id}/executed     |
//+------------------------------------------------------------------+
void ReportExecuted(int signalId, ulong ticket)
{
    string url     = FelixUrl + "/api/auto-trade/signals/" + IntegerToString(signalId) + "/executed";
    string bodyStr = "{\"mt5_ticket\":" + IntegerToString((long)ticket) + "}";
    string headers = "Content-Type: application/json\r\nX-Auto-Trade-Token: " + AuthToken + "\r\n";

    char   bodyArr[];
    char   resBody[];
    string resHeaders;

    StringToCharArray(bodyStr, bodyArr, 0, StringLen(bodyStr));

    int httpCode = WebRequest("POST", url, headers, 5000, bodyArr, resBody, resHeaders);

    if (httpCode == 200 || httpCode == 201)
        Print("Felix: ReportExecuted OK — signal #", signalId, " ticket=", ticket);
    else
        Print("Felix: ReportExecuted FAIL — signal #", signalId, " HTTP=", httpCode);
}

//+------------------------------------------------------------------+
//| ReportClosed — POST /api/auto-trade/signals/{id}/closed         |
//+------------------------------------------------------------------+
void ReportClosed(int signalId, string status, double closePrice)
{
    string url     = FelixUrl + "/api/auto-trade/signals/" + IntegerToString(signalId) + "/closed";
    string bodyStr = "{\"status\":\"" + status + "\",\"close_price\":" + DoubleToString(closePrice, 5) + "}";
    string headers = "Content-Type: application/json\r\nX-Auto-Trade-Token: " + AuthToken + "\r\n";

    char   bodyArr[];
    char   resBody[];
    string resHeaders;

    StringToCharArray(bodyStr, bodyArr, 0, StringLen(bodyStr));

    int httpCode = WebRequest("POST", url, headers, 5000, bodyArr, resBody, resHeaders);

    if (httpCode == 200 || httpCode == 201)
        Print("Felix: ReportClosed OK — signal #", signalId, " ", status, " @ ", closePrice);
    else
        Print("Felix: ReportClosed FAIL — signal #", signalId, " HTTP=", httpCode);
}

//+------------------------------------------------------------------+
//| AlreadyTracked — kiem tra signal da duoc theo doi chua          |
//+------------------------------------------------------------------+
bool AlreadyTracked(int signalId)
{
    for (int i = 0; i < g_trackedCount; i++)
        if (g_trackedIds[i] == signalId)
            return true;
    return false;
}

//+------------------------------------------------------------------+
//| AddTracked — them signal + ticket vao mang theo doi             |
//+------------------------------------------------------------------+
void AddTracked(int signalId, ulong ticket)
{
    ArrayResize(g_trackedIds,     g_trackedCount + 1);
    ArrayResize(g_trackedTickets, g_trackedCount + 1);
    g_trackedIds[g_trackedCount]     = signalId;
    g_trackedTickets[g_trackedCount] = ticket;
    g_trackedCount++;
    Print("Felix: Tracking signal #", signalId, " ticket=", ticket, " | Total tracked: ", g_trackedCount);
}

//+------------------------------------------------------------------+
//| RemoveTracked — xoa phan tu theo index, dich trai               |
//+------------------------------------------------------------------+
void RemoveTracked(int idx)
{
    if (idx < 0 || idx >= g_trackedCount)
        return;

    for (int i = idx; i < g_trackedCount - 1; i++)
    {
        g_trackedIds[i]     = g_trackedIds[i + 1];
        g_trackedTickets[i] = g_trackedTickets[i + 1];
    }

    g_trackedCount--;
    ArrayResize(g_trackedIds,     g_trackedCount);
    ArrayResize(g_trackedTickets, g_trackedCount);
}
//+------------------------------------------------------------------+
