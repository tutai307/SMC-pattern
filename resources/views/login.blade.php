<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Felix Terminal — Đăng nhập</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { background: #080c13; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4">

    <div class="w-full max-w-sm">
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center gap-2 mb-2">
                <div class="w-8 h-8 bg-blue-500/20 border border-blue-500/30 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <span class="text-white font-bold text-lg tracking-wide">Felix Terminal</span>
            </div>
            <p class="text-slate-500 text-xs">Xác thực OTP qua email để tiếp tục</p>
        </div>

        <!-- Card -->
        <div class="bg-white/[0.03] border border-white/[0.08] rounded-2xl p-6 shadow-2xl">

            @if(session('otp_sent'))
            <!-- Step 2: nhập OTP -->
            <form method="POST" action="{{ route('login.verify') }}">
                @csrf
                <div class="mb-5">
                    <div class="flex items-center gap-2 mb-4">
                        <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                        <span class="text-green-400 text-xs font-semibold">OTP đã được gửi đến email của bạn</span>
                    </div>
                    <label class="block text-slate-400 text-xs font-semibold uppercase tracking-wider mb-2">Nhập mã OTP</label>
                    <input
                        type="text"
                        name="otp"
                        maxlength="6"
                        autofocus
                        autocomplete="one-time-code"
                        placeholder="000000"
                        class="w-full bg-white/5 border {{ $errors->has('otp') ? 'border-red-500/50' : 'border-white/10' }} rounded-xl px-4 py-3 text-white text-center text-2xl font-mono tracking-[0.5em] focus:outline-none focus:border-blue-500/50 transition-all placeholder:text-slate-700 placeholder:tracking-[0.3em]">
                    @error('otp')
                        <p class="text-red-400 text-xs mt-2">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 rounded-xl transition-all text-sm">
                    Xác nhận
                </button>

                <div class="text-center mt-4">
                    <form method="POST" action="{{ route('login.send') }}" class="inline">
                        @csrf
                        <button type="submit" class="text-slate-500 hover:text-slate-300 text-xs transition-all">
                            Gửi lại OTP
                        </button>
                    </form>
                </div>
            </form>

            @else
            <!-- Step 1: gửi OTP -->
            <form method="POST" action="{{ route('login.send') }}">
                @csrf
                <div class="mb-5">
                    <label class="block text-slate-400 text-xs font-semibold uppercase tracking-wider mb-2">Email admin</label>
                    <div class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-slate-400 text-sm font-mono truncate">
                        {{ Str::mask(env('ADMIN_EMAIL', '...'), '*', 3, -10) }}
                    </div>
                    <p class="text-slate-600 text-xs mt-1.5">OTP sẽ được gửi đến email được cấu hình trong .env</p>
                </div>

                @error('otp')
                    <p class="text-red-400 text-xs mb-4 bg-red-500/10 border border-red-500/20 rounded-lg px-3 py-2">{{ $message }}</p>
                @enderror

                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 rounded-xl transition-all text-sm flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    Gửi OTP
                </button>
            </form>
            @endif
        </div>

        <p class="text-center text-slate-700 text-xs mt-6">Felix v1 · SMC Pattern Terminal</p>
    </div>

</body>
</html>
