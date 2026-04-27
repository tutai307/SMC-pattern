<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tom AI Academy - Học Trading Chuyên Nghiệp</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', sans-serif; background-color: #0f172a; color: #f8fafc; }
        .glass { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.05); }
        .gradient-text { background: linear-gradient(135deg, #60a5fa 0%, #c084fc 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body class="min-h-screen">
    <!-- Navbar -->
    <nav class="glass sticky top-0 z-50 px-6 py-4 flex justify-between items-center border-b border-white/5">
        <div class="flex items-center space-x-2">
            <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center font-bold">T</div>
            <span class="text-xl font-bold tracking-tight">TOM <span class="gradient-text">ACADEMY</span></span>
        </div>
        <div class="flex space-x-6 text-sm font-medium">
            <a href="/" class="text-slate-400 hover:text-white transition-colors">Trở về Terminal</a>
            <a href="#" onclick="showTab('smc')" class="text-slate-400 hover:text-white transition-colors">SMC Master</a>
            <a href="#" onclick="showTab('elliot')" class="text-slate-400 hover:text-white transition-colors">Elliot Wave</a>
            <a href="#" onclick="showTab('quiz')" class="px-4 py-1 bg-blue-600 rounded-full text-white hover:bg-blue-500 transition-all">Luyện tập</a>
        </div>
    </nav>

    <main class="max-w-5xl mx-auto px-6 py-12">
        <!-- SMC Tab -->
        <div id="smc" class="tab-content active space-y-12">
            <header class="text-center space-y-4">
                <h1 class="text-5xl font-bold">Smart Money <span class="gradient-text">Concepts</span></h1>
                <p class="text-slate-400 text-lg max-w-2xl mx-auto">Học cách đọc hiểu dòng tiền của các tổ chức tài chính lớn để không còn là "thanh khoản" cho họ.</p>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="glass p-8 rounded-3xl space-y-4 border-l-4 border-blue-500">
                    <div class="text-4xl">🏦</div>
                    <h3 class="text-xl font-bold">Order Block (OB)</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">Vùng giá mà các tổ chức lớn đặt lệnh. Đặc điểm: Một cây nến ngược chiều trước khi có lực đẩy mạnh (Displacement) tạo phá vỡ cấu trúc.</p>
                </div>
                <div class="glass p-8 rounded-3xl space-y-4 border-l-4 border-purple-500">
                    <div class="text-4xl">🕳️</div>
                    <h3 class="text-xl font-bold">Fair Value Gap (FVG)</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">Khoảng trống giá do sự mất cân bằng. Giá có xu hướng quay lại lấp đầy FVG trước khi tiếp tục xu hướng chính.</p>
                </div>
                <div class="glass p-8 rounded-3xl space-y-4 border-l-4 border-green-500">
                    <div class="text-4xl">📈</div>
                    <h3 class="text-xl font-bold">BOS & CHoCH</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">BOS xác nhận xu hướng tiếp diễn. CHoCH xác nhận sự thay đổi tính chất thị trường, báo hiệu đảo chiều.</p>
                </div>
            </div>

            <div class="glass p-10 rounded-3xl space-y-6">
                <h2 class="text-2xl font-bold">Chiến lược giao dịch SMC của Tom</h2>
                <ul class="space-y-4 text-slate-300">
                    <li class="flex items-start space-x-3">
                        <span class="w-6 h-6 bg-blue-600/20 text-blue-400 rounded-full flex items-center justify-center text-xs mt-1">1</span>
                        <span>Xác định xu hướng khung lớn (HTF) như H4 hoặc D1.</span>
                    </li>
                    <li class="flex items-start space-x-3">
                        <span class="w-6 h-6 bg-blue-600/20 text-blue-400 rounded-full flex items-center justify-center text-xs mt-1">2</span>
                        <span>Tìm vùng OB hoặc FVG trên khung nhỏ (M15) nằm trong vùng HTF.</span>
                    </li>
                    <li class="flex items-start space-x-3">
                        <span class="w-6 h-6 bg-blue-600/20 text-blue-400 rounded-full flex items-center justify-center text-xs mt-1">3</span>
                        <span>Đợi nến xác nhận đảo chiều (Confirmation) tại vùng đó mới vào lệnh.</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Elliot Tab -->
        <div id="elliot" class="tab-content space-y-12">
            <header class="text-center space-y-4">
                <h1 class="text-5xl font-bold">Lý thuyết <span class="gradient-text">Sóng Elliot</span></h1>
                <p class="text-slate-400 text-lg max-w-2xl mx-auto">Thị trường di chuyển theo những nhịp điệu lặp đi lặp lại. Làm chủ các con sóng để biết mình đang ở đâu.</p>
            </header>

            <div class="glass p-10 rounded-3xl">
                <div class="flex flex-col md:flex-row items-center gap-12">
                    <div class="flex-1 space-y-6">
                        <h2 class="text-3xl font-bold text-amber-400">Chu kỳ đẩy 1-2-3-4-5</h2>
                        <p class="text-slate-400 leading-relaxed">Sóng 1, 3, 5 là sóng tăng. Sóng 3 thường là sóng dài nhất. Sóng 2 và 4 là các nhịp điều chỉnh. Hệ thống Tom tập trung bắt đầu **Sóng 3** hoặc **Sóng 5** để tối ưu lợi nhuận.</p>
                        <h2 class="text-3xl font-bold text-red-400">Chu kỳ chỉnh A-B-C</h2>
                        <p class="text-slate-400 leading-relaxed">Xảy ra sau khi sóng 5 kết thúc. Đây là giai đoạn thị trường đảo chiều hoặc đi ngang tích lũy.</p>
                    </div>
                    <div class="w-full md:w-80 h-64 bg-white/5 rounded-2xl flex items-center justify-center border border-white/10 italic text-slate-500">
                        [Đồ họa Sóng Elliot minh họa]
                    </div>
                </div>
            </div>
        </div>

        <!-- Quiz Tab -->
        <div id="quiz" class="tab-content space-y-12">
            <header class="text-center space-y-4">
                <h1 class="text-5xl font-bold">Luyện tập <span class="gradient-text">Kiến thức</span></h1>
                <p class="text-slate-400 text-lg">Kiểm tra xem bạn đã sẵn sàng đối mặt với thị trường chưa.</p>
            </header>

            <div class="max-w-2xl mx-auto space-y-8">
                <!-- Q1 -->
                <div class="glass p-8 rounded-3xl space-y-6 quiz-card" data-correct="C">
                    <h3 class="text-xl font-bold">Câu 1: Hiện tượng nào báo hiệu sự đảo chiều xu hướng trong SMC?</h3>
                    <div class="space-y-3">
                        <button onclick="checkAnswer(this, 'A')" class="w-full text-left p-4 rounded-xl border border-white/5 hover:bg-white/5 transition-all">A. Giá tạo đỉnh cao hơn (Higher High)</button>
                        <button onclick="checkAnswer(this, 'B')" class="w-full text-left p-4 rounded-xl border border-white/5 hover:bg-white/5 transition-all">B. Giá lấp đầy một vùng FVG</button>
                        <button onclick="checkAnswer(this, 'C')" class="w-full text-left p-4 rounded-xl border border-white/5 hover:bg-white/5 transition-all">C. Giá tạo thành một CHoCH (Change of Character)</button>
                    </div>
                    <div class="feedback hidden text-sm font-bold pt-2"></div>
                </div>

                <!-- Q2 -->
                <div class="glass p-8 rounded-3xl space-y-6 quiz-card" data-correct="B">
                    <h3 class="text-xl font-bold">Câu 2: Trong Sóng Elliot, sóng nào thường mạnh mẽ và dài nhất?</h3>
                    <div class="space-y-3">
                        <button onclick="checkAnswer(this, 'A')" class="w-full text-left p-4 rounded-xl border border-white/5 hover:bg-white/5 transition-all">A. Sóng 1</button>
                        <button onclick="checkAnswer(this, 'B')" class="w-full text-left p-4 rounded-xl border border-white/5 hover:bg-white/5 transition-all">B. Sóng 3</button>
                        <button onclick="checkAnswer(this, 'C')" class="w-full text-left p-4 rounded-xl border border-white/5 hover:bg-white/5 transition-all">C. Sóng 4</button>
                    </div>
                    <div class="feedback hidden text-sm font-bold pt-2"></div>
                </div>

                <!-- Q3 -->
                <div class="glass p-8 rounded-3xl space-y-6 quiz-card" data-correct="B">
                    <h3 class="text-xl font-bold">Câu 3: Điều kiện tiên quyết để AI của Tom phát lệnh BÁN tại Supply Zone là gì?</h3>
                    <div class="space-y-3">
                        <button onclick="checkAnswer(this, 'A')" class="w-full text-left p-4 rounded-xl border border-white/5 hover:bg-white/5 transition-all">A. Chỉ cần giá chạm vào vùng Supply</button>
                        <button onclick="checkAnswer(this, 'B')" class="w-full text-left p-4 rounded-xl border border-white/5 hover:bg-white/5 transition-all">B. Giá chạm vùng Supply + có nến xác nhận đỏ (Bearish)</button>
                        <button onclick="checkAnswer(this, 'C')" class="w-full text-left p-4 rounded-xl border border-white/5 hover:bg-white/5 transition-all">C. Giá phá vỡ vùng Supply đi lên</button>
                    </div>
                    <div class="feedback hidden text-sm font-bold pt-2"></div>
                </div>
            </div>
        </div>
    </main>

    <script>
        function showTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function checkAnswer(btn, choice) {
            const card = btn.closest('.quiz-card');
            const correct = card.dataset.correct;
            const feedback = card.querySelector('.feedback');
            
            // Reset buttons
            card.querySelectorAll('button').forEach(b => {
                b.classList.remove('bg-green-600/20', 'border-green-600/50', 'bg-red-600/20', 'border-red-600/50');
            });

            if (choice === correct) {
                btn.classList.add('bg-green-600/20', 'border-green-600/50');
                feedback.innerText = "Chính xác! Bạn có tố chất của một Pro Trader đấy.";
                feedback.classList.remove('hidden', 'text-red-400');
                feedback.classList.add('text-green-400');
            } else {
                btn.classList.add('bg-red-600/20', 'border-red-600/50');
                feedback.innerText = "Sai mất rồi. Hãy đọc lại kiến thức ở các tab trước nhé.";
                feedback.classList.remove('hidden', 'text-green-400');
                feedback.classList.add('text-red-400');
            }
        }
    </script>
</body>
</html>
