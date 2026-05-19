//+------------------------------------------------------------------+
//| FelixBulkExporter.mq5                                            |
//| Script (one-shot): export M15 + H4 klines cho khoảng ngày cụ thể|
//| Drag vào chart XAUUSD bất kỳ TF → tự gửi lên server rồi thoát  |
//+------------------------------------------------------------------+
#property script_show_inputs
#property copyright "Felix v5.3"
#property version   "1.0"

input string WebhookURL    = "https://smc-pattern-production.up.railway.app/api/mt5";
input string WebhookSecret = "felix_mt5_a23c7eafc3a292cc";
input string StartDate     = "2026.05.01";  // YYYY.MM.DD — ngày bắt đầu
input string EndDate       = "2026.05.19";  // YYYY.MM.DD — ngày kết thúc (inclusive)
input int    ChunkSize     = 500;           // Số bar mỗi lần gửi (tránh body quá lớn)
input bool   EnableLogging = true;

//+------------------------------------------------------------------+
void OnStart()
{
    datetime startDt = StringToTime(StartDate);
    datetime endDt   = StringToTime(EndDate) + 86399;  // 23:59:59 ngày kết thúc

    if (startDt == 0 || endDt == 0) {
        Print("FelixBulkExporter: Ngày không hợp lệ — kiểm tra StartDate/EndDate");
        return;
    }

    Print("FelixBulkExporter: Export ", _Symbol, " từ ", StartDate, " đến ", EndDate);

    ExportTimeframe(PERIOD_M15, "M15", startDt, endDt);
    ExportTimeframe(PERIOD_H4,  "H4",  startDt, endDt);

    Print("FelixBulkExporter: Hoàn thành!");
}

//+------------------------------------------------------------------+
void ExportTimeframe(ENUM_TIMEFRAMES tf, string tfName,
                     datetime startDt, datetime endDt)
{
    MqlRates rates[];
    int copied = CopyRates(_Symbol, tf, startDt, endDt, rates);

    if (copied <= 0) {
        Print("FelixBulkExporter [", tfName, "]: Không có data — lỗi ", GetLastError(),
              " (symbol=", _Symbol, " tf=", tfName, ")");
        return;
    }

    if (EnableLogging)
        Print("FelixBulkExporter [", tfName, "]: ", copied, " bars — gửi lên server...");

    int brokerOffsetSec = (int)(TimeGMT() - TimeCurrent());
    int chunks = (int)MathCeil((double)copied / ChunkSize);

    for (int c = 0; c < chunks; c++) {
        int fromIdx = c * ChunkSize;
        int toIdx   = MathMin(fromIdx + ChunkSize, copied);

        string klinesJson = "[";
        for (int i = fromIdx; i < toIdx; i++) {
            long ts_ms = ((long)rates[i].time + brokerOffsetSec) * 1000;
            if (i > fromIdx) klinesJson += ",";
            klinesJson += "[" + IntegerToString(ts_ms)
                       + "," + DoubleToString(rates[i].open,  2)
                       + "," + DoubleToString(rates[i].high,  2)
                       + "," + DoubleToString(rates[i].low,   2)
                       + "," + DoubleToString(rates[i].close, 2)
                       + "," + DoubleToString((double)rates[i].tick_volume, 1)
                       + "]";
        }
        klinesJson += "]";

        string body = "{"
            + "\"secret\":\""    + WebhookSecret + "\","
            + "\"symbol\":\""    + _Symbol       + "\","
            + "\"timeframe\":\"" + tfName        + "\","
            + "\"klines\":"      + klinesJson
            + "}";

        string result = PostJSON(
            WebhookURL + "/bulk-klines?secret=" + WebhookSecret, body
        );

        if (EnableLogging)
            Print("FelixBulkExporter [", tfName, "] chunk ", c + 1, "/", chunks,
                  " (bars ", fromIdx, "-", toIdx - 1, "): ", result);

        Sleep(300);  // nhường bandwidth, tránh rate-limit
    }
}

//+------------------------------------------------------------------+
string PostJSON(const string url, const string body)
{
    uchar  requestBody[];
    uchar  responseBody[];
    string responseHeaders;

    StringToCharArray(body, requestBody);
    int bodyLen = ArraySize(requestBody) - 1;
    ArrayResize(requestBody, bodyLen);

    string headers = "Content-Type: application/json\r\n"
                   + "Content-Length: " + IntegerToString(bodyLen) + "\r\n";

    int statusCode = WebRequest("POST", url, headers, 10000,
                                requestBody, responseBody, responseHeaders);

    if (statusCode == -1) {
        int err = GetLastError();
        if (err == 4014)
            Print("FelixBulkExporter: Thêm URL vào Tools > Options > Expert Advisors: ", url);
        else
            Print("FelixBulkExporter: WebRequest lỗi #", err);
        return "ERROR:" + IntegerToString(err);
    }

    return IntegerToString(statusCode) + ":" + CharArrayToString(responseBody);
}
