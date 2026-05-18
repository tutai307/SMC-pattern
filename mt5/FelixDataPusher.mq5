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
input int    KlineCount    = 60;                                // Số nến gửi mỗi lần push
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

    // Compact format: ts_sec,o,h,l,c,v~ts_sec,o,h,l,c,v~...
    // Dùng ~ làm row separator (URL-safe), không cần body
    string compact = "";
    for (int i = 0; i < copied; i++) {
        if (i > 0) compact += "~";
        compact += IntegerToString((long)rates[i].time) + ","
                 + DoubleToString(rates[i].open,  2) + ","
                 + DoubleToString(rates[i].high,  2) + ","
                 + DoubleToString(rates[i].low,   2) + ","
                 + DoubleToString(rates[i].close, 2) + ","
                 + IntegerToString((int)rates[i].tick_volume);
    }

    double bid = SymbolInfoDouble(_Symbol, SYMBOL_BID);

    string url = WebhookURL + "/klines"
               + "?secret="    + WebhookSecret
               + "&symbol="    + _Symbol
               + "&timeframe=M15"
               + "&bid="       + DoubleToString(bid, _Digits)
               + "&k="         + compact;

    uchar  emptyBody[];
    uchar  responseBody[];
    string responseHeaders;
    ArrayResize(emptyBody, 0);

    int statusCode = WebRequest("POST", url, "", 5000, emptyBody, responseBody, responseHeaders);
    if (statusCode == -1) {
        int err = GetLastError();
        Print("FelixDataPusher klines ERROR #", err, " (4014=URL chưa allow, 5203=timeout) url_len=", StringLen(url));
        return;
    }
    string result = IntegerToString(statusCode) + ":" + CharArrayToString(responseBody);

    if (EnableLogging)
        Print("FelixDataPusher klines: ", copied, " bars — ", result);
}

//+------------------------------------------------------------------+
// Push giá bid hiện tại lên /api/mt5/tick
void PushTick()
{
    double bid = SymbolInfoDouble(_Symbol, SYMBOL_BID);
    double ask = SymbolInfoDouble(_Symbol, SYMBOL_ASK);

    // Gửi toàn bộ qua query params — không cần body, tránh JSON parse issue
    string url = WebhookURL + "/tick"
               + "?secret=" + WebhookSecret
               + "&symbol=" + _Symbol
               + "&bid="    + DoubleToString(bid, _Digits)
               + "&ask="    + DoubleToString(ask, _Digits);

    uchar  emptyBody[];
    uchar  responseBody[];
    string responseHeaders;
    ArrayResize(emptyBody, 0);

    int statusCode = WebRequest("POST", url, "", 5000, emptyBody, responseBody, responseHeaders);
    string result  = IntegerToString(statusCode) + ":" + CharArrayToString(responseBody);
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
