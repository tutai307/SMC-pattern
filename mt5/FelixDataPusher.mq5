//+------------------------------------------------------------------+
//| FelixDataPusher.mq5                                             |
//| Push M15 klines + tick price lên Laravel Felix v4               |
//| Attach vào chart XAUUSD M15 trên Exness                         |
//+------------------------------------------------------------------+
#property copyright "Felix v4"
#property version   "1.2"

//--- Input parameters
input string WebhookURL    = "https://smc-pattern-production.up.railway.app/api/mt5";
input string WebhookSecret = "felix_mt5_a23c7eafc3a292cc";
input int    KlineCount    = 150;  // Số nến gửi mỗi lần push
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
        Print("FelixDataPusher v1.2 — symbol=", _Symbol, " server=", WebhookURL);
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
// Push klines qua JSON body với Content-Length explicit
void PushKlines()
{
    MqlRates rates[];
    int copied = CopyRates(_Symbol, PERIOD_M15, 0, KlineCount, rates);
    if (copied <= 0) {
        if (EnableLogging) Print("FelixDataPusher: CopyRates thất bại — ", GetLastError());
        return;
    }

    // Build JSON klines array
    string klinesJson = "[";
    for (int i = 0; i < copied; i++) {
        long   ts_ms  = (long)rates[i].time * 1000;
        if (i > 0) klinesJson += ",";
        klinesJson += "[" + IntegerToString(ts_ms)
                   + "," + DoubleToString(rates[i].open,  2)
                   + "," + DoubleToString(rates[i].high,  2)
                   + "," + DoubleToString(rates[i].low,   2)
                   + "," + DoubleToString(rates[i].close, 2)
                   + "," + DoubleToString((double)rates[i].tick_volume, 1)
                   + "]";
    }
    klinesJson += "]";

    double bid = SymbolInfoDouble(_Symbol, SYMBOL_BID);

    string body = "{"
        + "\"secret\":\""    + WebhookSecret  + "\","
        + "\"symbol\":\""    + _Symbol        + "\","
        + "\"timeframe\":\"M15\","
        + "\"bid\":"         + DoubleToString(bid, _Digits) + ","
        + "\"klines\":"      + klinesJson
        + "}";

    string result = PostJSON(WebhookURL + "/klines", body);
    if (EnableLogging)
        Print("FelixDataPusher klines: ", copied, " bars — ", result);
}

//+------------------------------------------------------------------+
// Push tick qua query params (body rỗng — tick nhỏ, không cần body)
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

//+------------------------------------------------------------------+
// HTTP POST JSON với Content-Length explicit để server đọc được body
string PostJSON(const string url, const string body)
{
    uchar  requestBody[];
    uchar  responseBody[];
    string responseHeaders;

    StringToCharArray(body, requestBody, 0, StringLen(body));
    int bodyLen = ArraySize(requestBody) - 1; // bỏ null terminator
    ArrayResize(requestBody, bodyLen);

    // Content-Length bắt buộc — không có sẽ bị server bỏ qua body
    string headers = "Content-Type: application/json\r\n"
                   + "Content-Length: " + IntegerToString(bodyLen) + "\r\n";

    int statusCode = WebRequest("POST", url, headers, 5000,
                                requestBody, responseBody, responseHeaders);

    if (statusCode == -1) {
        int err = GetLastError();
        if (err == 4014)
            Print("FelixDataPusher: URL chưa allow — thêm vào Tools > Options > Expert Advisors: ", url);
        else
            Print("FelixDataPusher: WebRequest lỗi #", err, " url=", url);
        return "ERROR:" + IntegerToString(err);
    }

    return IntegerToString(statusCode) + ":" + CharArrayToString(responseBody);
}
