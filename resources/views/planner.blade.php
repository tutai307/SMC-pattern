<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tom AI Planner - Lập Kế Hoạch Giao Dịch</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', sans-serif; background-color: #0a0e17; color: #f8fafc; }
        .glass { background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.05); }
        .gradient-text { background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .step { display: none; }
        .step.active { display: block; animation: fadeIn 0.5s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">
    <div class="max-w-2xl w-full">
        <div class="glass p-10 rounded-[2rem] shadow-2xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-64 h-64 bg-blue-600/10 rounded-full -mr-32 -mt-32 blur-3xl"></div>
            
            <header class="mb-10 text-center">
                <h1 class="text-3xl font-bold tracking-tight">Kế Hoạch <span class="gradient-text">Tác Chiến</span></h1>
                <p class="text-slate-400 mt-2">Tom sẽ giúp bạn xây dựng lộ trình lợi nhuận cá nhân.</p>
            </header>

            <form id="plannerForm" class="space-y-8">
                <!-- Step 1: Capital -->
                <div id="step-1" class="step active space-y-6">
                    <label class="block text-xl font-semibold">1. Vốn đầu tư của bạn là bao nhiêu? ($)</label>
                    <input type="number" id="capital" placeholder="Ví dụ: 1000" class="w-full bg-white/5 border border-white/10 rounded-2xl px-6 py-4 text-2xl font-mono focus:outline-none focus:border-blue-500 transition-all">
                    <button type="button" onclick="nextStep(2)" class="w-full bg-blue-600 hover:bg-blue-500 py-4 rounded-2xl font-bold transition-all shadow-lg shadow-blue-600/20">Tiếp theo</button>
                </div>

                <!-- Step 2: Time -->
                <div id="step-2" class="step space-y-6">
                    <label class="block text-xl font-semibold">2. Bạn dành được bao nhiêu thời gian?</label>
                    <div class="grid grid-cols-1 gap-4">
                        <button type="button" onclick="setChoice('time', 'busy', 3)" class="text-left p-6 rounded-2xl border border-white/10 hover:bg-white/5 hover:border-blue-500 transition-all">
                            <h4 class="font-bold">Rất bận (Đi làm hành chính)</h4>
                            <p class="text-sm text-slate-400">Chỉ check máy 1-2 lần/ngày. Phù hợp Swing Trade.</p>
                        </button>
                        <button type="button" onclick="setChoice('time', 'free', 3)" class="text-left p-6 rounded-2xl border border-white/10 hover:bg-white/5 hover:border-blue-500 transition-all">
                            <h4 class="font-bold">Rảnh rỗi (Full-time Trader)</h4>
                            <p class="text-sm text-slate-400">Có thể soi máy liên tục. Phù hợp Scalp M15.</p>
                        </button>
                    </div>
                </div>

                <!-- Step 3: Risk -->
                <div id="step-3" class="step space-y-6">
                    <label class="block text-xl font-semibold">3. Khẩu vị rủi ro của bạn?</label>
                    <div class="grid grid-cols-2 gap-4">
                        <button type="button" onclick="setChoice('risk', 'safe', 4)" class="p-6 rounded-2xl border border-white/10 hover:bg-green-500/20 hover:border-green-500 transition-all">
                            <h4 class="font-bold text-green-400">An toàn</h4>
                            <p class="text-xs text-slate-400 mt-1">Lãi ít nhưng bền. Rủi ro 1%/lệnh.</p>
                        </button>
                        <button type="button" onclick="setChoice('risk', 'aggressive', 4)" class="p-6 rounded-2xl border border-white/10 hover:bg-red-500/20 hover:border-red-500 transition-all">
                            <h4 class="font-bold text-red-400">Mạo hiểm</h4>
                            <p class="text-xs text-slate-400 mt-1">Lãi nhanh. Rủi ro 3-5%/lệnh.</p>
                        </button>
                    </div>
                </div>

                <!-- Step 4: Coin -->
                <div id="step-4" class="step space-y-6">
                    <label class="block text-xl font-semibold">4. Đồng coin bạn yêu thích?</label>
                    <input type="text" id="coins" placeholder="Ví dụ: BTC, ETH, SOL..." class="w-full bg-white/5 border border-white/10 rounded-2xl px-6 py-4 text-lg focus:outline-none focus:border-blue-500 transition-all uppercase">
                    <button type="button" onclick="generatePlan()" class="w-full bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 py-4 rounded-2xl font-bold transition-all shadow-xl">Xây dựng Kế hoạch</button>
                </div>

                <!-- Step 5: Result -->
                <div id="result" class="step space-y-6">
                    <div id="planContent" class="space-y-6">
                        <!-- Content generated via JS -->
                    </div>
                    <div class="flex space-x-4">
                        <button type="button" onclick="location.reload()" class="flex-1 bg-white/5 hover:bg-white/10 py-4 rounded-2xl font-bold transition-all">Làm lại</button>
                        <a href="/" class="flex-1 bg-blue-600 hover:bg-blue-500 py-4 rounded-2xl font-bold text-center transition-all">Bắt đầu Trade</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        let userData = {
            capital: 0,
            time: '',
            risk: '',
            coins: ''
        };

        function nextStep(step) {
            if (step === 2) {
                userData.capital = document.getElementById('capital').value;
                if (!userData.capital || userData.capital <= 0) {
                    alert('Vui lòng nhập số vốn hợp lệ!');
                    return;
                }
            }
            document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
            document.getElementById('step-' + step).classList.add('active');
        }

        function setChoice(key, value, next) {
            userData[key] = value;
            nextStep(next);
        }

        function generatePlan() {
            userData.coins = document.getElementById('coins').value || 'BTC, ETH';
            
            document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
            document.getElementById('result').classList.add('active');

            const planContent = document.getElementById('planContent');
            
            // Logic tính toán
            const monthlyTargetPct = userData.risk === 'safe' ? 10 : 30;
            const dailyTargetPct = (monthlyTargetPct / 22).toFixed(2);
            const riskPerTrade = userData.risk === 'safe' ? 1 : 3;
            const style = userData.time === 'busy' ? 'SWING H4' : 'SCALP M15';
            
            const monthlyProfit = (userData.capital * monthlyTargetPct / 100).toLocaleString();
            const dailyProfit = (userData.capital * dailyTargetPct / 100).toLocaleString();

            planContent.innerHTML = `
                <div class="bg-blue-600/10 p-6 rounded-3xl border border-blue-500/20">
                    <h3 class="text-blue-400 font-bold mb-2">MỤC TIÊU THÁNG</h3>
                    <div class="text-4xl font-bold">$${monthlyProfit} <span class="text-sm font-normal text-slate-500">(~${monthlyTargetPct}%)</span></div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-white/5 p-4 rounded-2xl border border-white/5">
                        <div class="text-xs text-slate-500 uppercase mb-1">Mục tiêu ngày</div>
                        <div class="text-lg font-bold text-green-400">$${dailyProfit}</div>
                    </div>
                    <div class="bg-white/5 p-4 rounded-2xl border border-white/5">
                        <div class="text-xs text-slate-500 uppercase mb-1">Kiểu Trade</div>
                        <div class="text-lg font-bold text-purple-400">${style}</div>
                    </div>
                </div>

                <div class="glass p-6 rounded-2xl space-y-4">
                    <h4 class="font-bold border-b border-white/5 pb-2">CHẾ ĐỘ QUẢN TRỊ RỦI RO</h4>
                    <ul class="text-sm space-y-2 text-slate-400">
                        <li>• Rủi ro tối đa mỗi lệnh: <strong class="text-white">${riskPerTrade}% vốn</strong> ($${userData.capital * riskPerTrade / 100})</li>
                        <li>• Tỉ lệ thắng yêu cầu: <strong class="text-white">>= 50%</strong> (với R:R là 1:2)</li>
                        <li>• Ưu tiên cặp: <strong class="text-white">${userData.coins.toUpperCase()}</strong></li>
                    </ul>
                </div>

                <div class="p-4 bg-amber-500/10 border border-amber-500/20 rounded-2xl text-xs text-amber-500 leading-relaxed italic">
                    💡 Lời khuyên: Vì bạn ${userData.time === 'busy' ? 'rất bận' : 'khá rảnh'}, hãy dùng Tom AI ở khung ${style}. Tuyệt đối không nhồi lệnh khi thua.
                </div>
            `;
        }
    </script>
</body>
</html>
