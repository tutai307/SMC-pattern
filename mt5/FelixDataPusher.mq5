//+------------------------------------------------------------------+
//| FelixDataPusher.mq5                                             |
//| Push M15 klines + tick price lên Laravel Felix v4               |
//| Attach vào chart XAUUSD M15 trên Exness                         |
//+------------------------------------------------------------------+
#property copyright "Felix v4"
#property version   "1.1"

//--- Input parameters
input string WebhookURL    = "https://smc-pattern-production.up.railway.app/api/mt5";
input string WebhookSecret = "felix_mt5_a23c7eafc3a292cc";
input int    KlineCount    = 90;   // Tổng số nến (chia 3 chunk × 30)
input int    TickInterval  = 10;   // Giây push tick
input bool   EnableLogging = true;

//--- Trạng thái nội bộ
datetime g_lastBarTime = 0;

//+------------------------------------------------------------------+
int OnInit()
{
    EventSetTimer(TickInterval);
    PushKlines();
    PushTick();
    if (EnableLogging)
        Print("FelixDataPusher v1.1 — symbol=", _Symbol, " server=", WebhookURL);
    return INIT_SUCCEEDED;
}

void OnDeinit(const int reason) { EventKillTimer(); }

void OnCalculate(const int rates_total,
                 const int prev_calculated,
                 const datetime &time[],
                 const double &open[], const double &high[],
                 const double &low[], const double &close[],
                 const long &tick_volume[], const long &volume[],
                 const int &spread[])
{
    datetime currentBarTime = time[rates_total - 1];
    if (currentBarTime != g_lastBarTime) {
        g_lastBarTime = currentBarTime;
        PushKlines();
    }
}

void OnTimer() { PushTick(); }

//+------------------------------------------------------------------+
// Push 1 chunk klines (30 nến) — URL ~780 chars, dưới limit MT5
void PushKlinesChunk(const MqlRates &rates[], int start, int count,
                     int batchNum, int totalBatches, double bid)
{
    string k = "";
    for (int i = start; i < start + count; i++) {
        if (i > start) k += "-";
        k += IntegerToString((int)MathRound(rates[i].open))  + ","
           + IntegerToString((int)MathRound(rates[i].high))  + ","
           + IntegerToString((int)MathRound(rates[i].low))   + ","
           + IntegerToString((int)MathRound(rates[i].close));
    }

    string url = WebhookURL + "/klines"
               + "?secret="   + WebhookSecret
               + "&symbol="   + _Symbol
               + "&tf=M15"
               + "&bid="      + DoubleToString(bid, _Digits)
               + "&ts="       + IntegerToString((long)rates[start].time)
               + "&b="        + IntegerToString(batchNum)
               + "&t="        + IntegerToString(totalBatches)
               + "&k="        + k;

    uchar emptyBody[], responseBody[];
    string responseHeaders;
    ArrayResize(emptyBody, 0);

    int sc = WebRequest("POST", url, "", 5000, emptyBody, responseBody, responseHeaders);
    if (sc == -1) {
        Print("FelixDataPusher chunk ", batchNum, " ERROR #", GetLastError(),
              " url_len=", StringLen(url));
    } else if (EnableLogging) {
        Print("FelixDataPusher chunk ", batchNum, "/", totalBatches,
              " url_len=", StringLen(url), " — ", sc, ":", CharArrayToString(responseBody));
    }
}

//+------------------------------------------------------------------+
void PushKlines()
{
    MqlRates rates[];
    int copied = CopyRates(_Symbol, PERIOD_M15, 0, KlineCount, rates);
    if (copied <= 0) {
        if (EnableLogging) Print("FelixDataPusher: CopyRates thất bại — ", GetLastError());
        return;
    }

    double bid = SymbolInfoDouble(_Symbol, SYMBOL_BID);

    int chunkSize   = 30;
    int totalChunks = (int)MathCeil((double)copied / chunkSize);

    for (int b = 0; b < totalChunks; b++) {
        int start = b * chunkSize;
        int count = MathMin(chunkSize, copied - start);
        PushKlinesChunk(rates, start, count, b + 1, totalChunks, bid);
    }

    if (EnableLogging)
        Print("FelixDataPusher klines: ", copied, " bars in ", totalChunks, " chunks");
}

//+------------------------------------------------------------------+
void PushTick()
{
    double bid = SymbolInfoDouble(_Symbol, SYMBOL_BID);
    double ask = SymbolInfoDouble(_Symbol, SYMBOL_ASK);

    string url = WebhookURL + "/tick"
               + "?secret=" + WebhookSecret
               + "&symbol=" + _Symbol
               + "&bid="    + DoubleToString(bid, _Digits)
               + "&ask="    + DoubleToString(ask, _Digits);

    uchar emptyBody[], responseBody[];
    string responseHeaders;
    ArrayResize(emptyBody, 0);

    int sc = WebRequest("POST", url, "", 5000, emptyBody, responseBody, responseHeaders);
    if (EnableLogging)
        Print("FelixDataPusher tick: bid=", DoubleToString(bid, _Digits),
              " — ", IntegerToString(sc), ":", CharArrayToString(responseBody));
}
