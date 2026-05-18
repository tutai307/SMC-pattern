//+------------------------------------------------------------------+
//| FelixDataPusher.mq5                                             |
//| Push M15 klines + tick price lên Laravel Felix v4               |
//| Attach vào chart XAUUSD M15 và XAGUSDT M15 trên Exness         |
//+------------------------------------------------------------------+
#property copyright "Felix v4"
#property version   "1.0"

//--- Input parameters
input string WebhookURL    = "https://smc-pattern-production.up.railway.app/api/mt5";  // Địa chỉ server Laravel
input string WebhookSecret = "felix_mt5_a23c7eafc3a292cc";    // MT5_WEBHOOK_SECRET trong .env
input int    KlineCount    = 200;                               // Số nến gửi mỗi lần push
input int    TickInterval  = 10;                                // Giây push giá bid (timer)
input bool   EnableLogging = true;                              // In log vào Experts tab

//--- Trạng thái nội bộ
datetime g_lastBarTime = 0;

//+------------------------------------------------------------------+
int OnInit()
{
    // Timer push tick price mỗi TickInterval giây
    EventSetTimer(TickInterval);

    // Push ngay khi EA khởi động
    PushKlines();
    PushTick();

    if (EnableLogging)
        Print("FelixDataPusher khởi động — symbol=", _Symbol, " server=", WebhookURL);

    return INIT_SUCCEEDED;
}

//+------------------------------------------------------------------+
void OnDeinit(const int reason)
{
    EventKillTimer();
}

//+------------------------------------------------------------------+
// OnCalculate: gọi mỗi tick — detect bar mới để push klines
void OnCalculate(const int rates_total,
                 const int prev_calculated,
                 const datetime &time[],
                 const double &open[],
                 const double &high[],
                 const double &low[],
                 const double &close[],
                 const long &tick_volume[],
                 const long &volume[],
                 const int &spread[])
{
    // Chỉ push khi M15 bar mới đóng (không push mỗi tick)
    datetime currentBarTime = time[rates_total - 1];
    if (currentBarTime != g_lastBarTime) {
        g_lastBarTime = currentBarTime;
        PushKlines();
    }
}

//+------------------------------------------------------------------+
// OnTimer: push giá bid mỗi TickInterval giây
void OnTimer()
{
    PushTick();
}

//+------------------------------------------------------------------+
// Push toàn bộ klines lên /api/mt5/klines
void PushKlines()
{
    MqlRates rates[];
    int copied = CopyRates(_Symbol, PERIOD_M15, 0, KlineCount, rates);

    if (copied <= 0) {
        if (EnableLogging) Print("FelixDataPusher: CopyRates thất bại — ", GetLastError());
        return;
    }

    // Build JSON array klines
    string klinesJson = "[";
    for (int i = 0; i < copied; i++) {
        long   ts_ms  = (long) rates[i].time * 1000; // Unix ms
        double open   = rates[i].open;
        double high   = rates[i].high;
        double low    = rates[i].low;
        double close  = rates[i].close;
        double volume = (double) rates[i].tick_volume;

        klinesJson += "[" +
            IntegerToString(ts_ms) + "," +
            DoubleToString(open,  _Digits) + "," +
            DoubleToString(high,  _Digits) + "," +
            DoubleToString(low,   _Digits) + "," +
            DoubleToString(close, _Digits) + "," +
            DoubleToString(volume, 2)      +
        "]";

        if (i < copied - 1) klinesJson += ",";
    }
    klinesJson += "]";

    double bid = SymbolInfoDouble(_Symbol, SYMBOL_BID);

    string body = "{"
        + "\"secret\":\""    + WebhookSecret + "\","
        + "\"symbol\":\""    + _Symbol       + "\","
        + "\"timeframe\":\"M15\","
        + "\"bid\":"         + DoubleToString(bid, _Digits) + ","
        + "\"klines\":"      + klinesJson
        + "}";

    string result = PostJSON(WebhookURL + "/klines?secret=" + WebhookSecret, body);

    if (EnableLogging)
        Print("FelixDataPusher klines: ", copied, " bars — ", result);
}

//+------------------------------------------------------------------+
// Push giá bid hiện tại lên /api/mt5/tick
void PushTick()
{
    double bid = SymbolInfoDouble(_Symbol, SYMBOL_BID);
    double ask = SymbolInfoDouble(_Symbol, SYMBOL_ASK);

    string body = "{"
        + "\"secret\":\"" + WebhookSecret + "\","
        + "\"symbol\":\"" + _Symbol       + "\","
        + "\"bid\":"      + DoubleToString(bid, _Digits) + ","
        + "\"ask\":"      + DoubleToString(ask, _Digits)
        + "}";

    string result = PostJSON(WebhookURL + "/tick?secret=" + WebhookSecret, body);
    if (EnableLogging)
        Print("FelixDataPusher tick: bid=", DoubleToString(bid, _Digits), " — ", result);
}

//+------------------------------------------------------------------+
// HTTP POST JSON helper (dùng WinINet qua WebRequest)
string PostJSON(const string url, const string body)
{
    uchar  requestBody[];
    uchar  responseBody[];
    string responseHeaders;

    StringToCharArray(body, requestBody, 0, StringLen(body));

    // Xóa ký tự null cuối (MQL5 thêm vào)
    int bodyLen = ArraySize(requestBody) - 1;
    ArrayResize(requestBody, bodyLen);

    int statusCode = WebRequest(
        "POST",
        url,
        "Content-Type: application/json\r\n",
        5000,           // timeout ms
        requestBody,
        responseBody,
        responseHeaders
    );

    if (statusCode == -1) {
        int err = GetLastError();
        if (err == 4014) {
            // URL chưa được allow trong MT5 — cần thêm vào Tools > Options > Expert Advisors
            Print("FelixDataPusher: URL chưa được allow. Vào Tools > Options > Expert Advisors > Allow WebRequest, thêm: ", url);
        } else {
            Print("FelixDataPusher: WebRequest lỗi #", err, " url=", url);
        }
        return "ERROR:" + IntegerToString(err);
    }

    string response = CharArrayToString(responseBody);
    return IntegerToString(statusCode) + ":" + response;
}
