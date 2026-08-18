<?php
/**
 * Admin Login Page
 * AI Chatbot System — CBMS
 * URL: /admin/login.php
 */

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Helpers\Database;
use App\Services\MicrosoftAuthService;
use App\Services\LogService;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');
Auth::startSession();

// Redirect if already logged in
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$allowLocalLogin  = filter_var($_ENV['ALLOW_LOCAL_LOGIN'] ?? true, FILTER_VALIDATE_BOOLEAN);
$showLocalForm    = $allowLocalLogin && (isset($_GET['local']) || isset($_GET['backdoor']));
$msClientId       = $_ENV['MICROSOFT_CLIENT_ID'] ?? '';
$enableMicrosoft  = !empty($msClientId);
$appName          = Database::appName();

$error   = '';
$success = '';

// Handle reason params
$reason = $_GET['reason'] ?? '';
if ($reason === 'expired')    $error = 'Session หมดอายุ กรุณาเข้าสู่ระบบใหม่';
if ($reason === 'unauthorized') $error = 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้';
if ($reason === 'domain')     $error = 'Domain ของ Email ไม่ได้รับอนุญาตให้เข้าสู่ระบบ';
if ($reason === 'suspended')  $error = 'บัญชีผู้ใช้นี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
if ($reason === 'cams')       $error = 'คุณไม่มีสิทธิ์เข้าใช้งานระบบนี้ กรุณาติดต่อผู้ดูแลระบบ';
if ($reason === 'ms_error') {
    $error = $_SESSION['auth_error'] ?? 'เกิดข้อผิดพลาดในการเชื่อมต่อกับ Microsoft กรุณาลองใหม่อีกครั้ง';
    unset($_SESSION['auth_error']);
}

// Generate Microsoft Login URL
$msLoginUrl = '#';
if ($enableMicrosoft) {
    try {
        $msAuth     = new MicrosoftAuthService();
        $msLoginUrl = $msAuth->getAuthorizationUrl();
    } catch (\Throwable $e) {
        // MS not configured — show local only
        $enableMicrosoft = false;
    }
}

// Handle Local Login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allowLocalLogin) {
    if (!Auth::validateCsrf()) {
        $error = 'CSRF token ไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password   = $_POST['password'] ?? '';

        if (empty($identifier) || empty($password)) {
            $error = 'กรุณากรอก Username/Email และ Password';
        } elseif (Auth::isLoginThrottled($identifier)) {
            $error = 'พยายามเข้าสู่ระบบล้มเหลวหลายครั้งเกินไป กรุณารอประมาณ 15 นาทีแล้วลองใหม่อีกครั้ง';
        } else {
            $user = Auth::attemptLocalLogin($identifier, $password);
            if ($user) {
                Auth::clearLoginAttempts($identifier);
                Auth::login($user);

                // Log activity
                try {
                    $logger = new LogService();
                    $logger->logActivity($user['id'], 'auth.login', 'admin_user', $user['id'], null, [
                        'method' => 'local',
                    ]);
                } catch (\Throwable $e) {}

                $redirect = $_GET['redirect'] ?? 'index.php';
                // Sanitize redirect (only allow relative paths)
                if (!preg_match('/^[a-zA-Z0-9\/_\-\.]+$/', $redirect)) {
                    $redirect = 'index.php';
                }
                header('Location: ' . $redirect);
                exit;
            } else {
                Auth::recordFailedLogin($identifier);
                $error = 'Username/Email หรือ Password ไม่ถูกต้อง';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>เข้าสู่ระบบ — <?= htmlspecialchars($appName) ?></title>

  <!-- Google Fonts: Sarabun (Thai) + Orbitron (Tech Header) + Rajdhani (Technical Monospace) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Orbitron:wght@500;700;900&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">

  <!-- Tailwind CSS Play CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['Sarabun', 'sans-serif'],
            cyber: ['Orbitron', 'sans-serif'],
            tech: ['Rajdhani', 'monospace'],
          },
          animation: {
            'spin-slow': 'spin 20s linear infinite',
            'spin-reverse': 'spin-rev 12s linear infinite',
            'float': 'float 6s ease-in-out infinite',
            'float-slow': 'float 9s ease-in-out infinite',
            'scanner': 'scanner 6s ease-in-out infinite',
          },
          keyframes: {
            'spin-rev': {
              from: { transform: 'rotate(360deg)' },
              to: { transform: 'rotate(0deg)' }
            },
            float: {
              '0%, 100%': { transform: 'translateY(0px)' },
              '50%': { transform: 'translateY(-12px)' }
            },
            scanner: {
              '0%, 100%': { top: '0%' },
              '50%': { top: '100%' }
            }
          }
        }
      }
    }
  </script>

  <!-- Alpine.js (via jsDelivr) -->
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

  <style>
    body {
      font-family: 'Sarabun', sans-serif;
      overflow: hidden;
    }

    /* Cyber Void dark backdrop */
    .cyber-void {
      background-color: #02040a;
      background-image: 
        radial-gradient(circle at 50% 35%, rgba(99, 102, 241, 0.15) 0%, transparent 60%),
        radial-gradient(circle at 15% 85%, rgba(189, 0, 255, 0.12) 0%, transparent 50%),
        radial-gradient(circle at 85% 15%, rgba(0, 242, 254, 0.08) 0%, transparent 45%);
    }

    /* Subtle grid perspective overlay */
    .cyber-grid {
      position: absolute;
      inset: 0;
      background-image: 
        linear-gradient(rgba(0, 242, 254, 0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0, 242, 254, 0.03) 1px, transparent 1px);
      background-size: 40px 40px;
      background-position: center center;
      transform: perspective(600px) rotateX(25deg);
      transform-origin: top center;
      mask-image: linear-gradient(to bottom, rgba(0,0,0,1) 20%, rgba(0,0,0,0) 90%);
      -webkit-mask-image: linear-gradient(to bottom, rgba(0,0,0,1) 20%, rgba(0,0,0,0) 90%);
      opacity: 0.75;
      pointer-events: none;
    }

    /* Scanlines */
    .scanlines {
      position: absolute;
      inset: 0;
      background: linear-gradient(
        rgba(18, 16, 16, 0) 50%, 
        rgba(0, 0, 0, 0.25) 50%
      );
      background-size: 100% 4px;
      pointer-events: none;
      z-index: 2;
      opacity: 0.4;
    }

    /* Floating ambient orbs */
    .orb {
      position: absolute;
      border-radius: 50%;
      filter: blur(100px);
      pointer-events: none;
      opacity: 0.2;
    }

    /* Glass Card styling */
    .glass-portal {
      background: rgba(4, 7, 20, 0.85);
      backdrop-filter: blur(25px);
      -webkit-backdrop-filter: blur(25px);
      border: 1px solid rgba(0, 242, 254, 0.2);
      box-shadow: 
        0 30px 80px rgba(0, 0, 0, 0.8),
        inset 0 0 30px rgba(0, 242, 254, 0.05);
      position: relative;
    }

    /* Corner Technical Brackets */
    .glass-portal::before, .glass-portal::after,
    .glass-portal-inner::before, .glass-portal-inner::after {
      content: '';
      position: absolute;
      width: 16px;
      height: 16px;
      border-color: rgba(0, 242, 254, 0.7);
      border-style: solid;
      pointer-events: none;
    }
    .glass-portal::before {
      top: -1px; left: -1px;
      border-width: 2px 0 0 2px;
      border-top-left-radius: 8px;
    }
    .glass-portal::after {
      top: -1px; right: -1px;
      border-width: 2px 2px 0 0;
      border-top-right-radius: 8px;
    }
    .glass-portal-inner::before {
      bottom: -1px; left: -1px;
      border-width: 0 0 2px 2px;
      border-bottom-left-radius: 8px;
    }
    .glass-portal-inner::after {
      bottom: -1px; right: -1px;
      border-width: 0 2px 2px 0;
      border-bottom-right-radius: 8px;
    }

    /* Technical scanner laser line */
    .laser-scanner {
      position: absolute;
      left: 0; width: 100%; height: 2px;
      background: linear-gradient(90deg, transparent, rgba(0, 242, 254, 0.5) 50%, transparent);
      box-shadow: 0 0 10px rgba(0, 242, 254, 0.8);
      pointer-events: none;
      z-index: 5;
      animation: scanner 6s ease-in-out infinite;
    }

    /* Button shine sweep animation setup */
    .tech-btn-ms {
      background: linear-gradient(135deg, rgba(6, 10, 28, 0.9) 0%, rgba(12, 22, 58, 0.95) 100%);
      border: 1px solid rgba(0, 242, 254, 0.3);
      box-shadow: 
        0 10px 25px rgba(0, 0, 0, 0.5),
        inset 0 0 15px rgba(0, 242, 254, 0.05);
      transition: all 0.3s cubic-bezier(0.2, 0.8, 0.2, 1);
      overflow: hidden;
    }
    .tech-btn-ms::after {
      content: '';
      position: absolute;
      top: 0; left: -100%; width: 100%; height: 100%;
      background: linear-gradient(90deg, transparent, rgba(0, 242, 254, 0.25), transparent);
      transition: all 0.8s ease;
    }
    .tech-btn-ms:hover::after {
      left: 100%;
    }
    .tech-btn-ms:hover {
      border-color: rgba(0, 242, 254, 0.8);
      box-shadow: 
        0 0 30px rgba(0, 242, 254, 0.4),
        0 0 60px rgba(189, 0, 255, 0.15),
        inset 0 0 15px rgba(0, 242, 254, 0.1);
    }

    /* Custom loading spinner */
    .hud-spinner {
      border: 2px solid rgba(0, 242, 254, 0.15);
      border-top-color: #00f2fe;
      border-right-color: #bd00ff;
      border-radius: 50%;
      width: 20px; height: 20px;
      animation: spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>

<body class="h-full cyber-void flex flex-col items-center justify-center min-h-screen p-4 relative overflow-hidden"
      x-data="{ 
        mx: 0, 
        my: 0,
        updateMouse(e) {
          this.mx = (e.clientX - window.innerWidth / 2) / 25;
          this.my = (e.clientY - window.innerHeight / 2) / 25;
        }
      }"
      @mousemove="updateMouse($event)">

  <!-- Scanlines & Digital overlays -->
  <div class="scanlines"></div>
  <div class="cyber-grid"></div>

  <!-- Pulsing technical glow orbs -->
  <div class="orb w-96 h-96 bg-indigo-500/10 top-[-10%] left-[-15%] animate-float"></div>
  <div class="orb w-[30rem] h-[30rem] bg-purple-600/10 bottom-[-15%] right-[-10%] animate-float-slow" style="animation-direction: reverse;"></div>
  <div class="orb w-80 h-80 bg-cyan-500/10 top-[30%] right-[5%] animate-pulse"></div>

  <!-- Geometric border lines representing HUD -->
  <div class="absolute inset-6 border border-white/5 pointer-events-none rounded-[2.5rem] z-0"></div>
  <div class="absolute inset-10 border border-white/5 pointer-events-none rounded-[2.2rem] z-0 border-dashed"></div>

  <!-- Portal Container -->
  <div class="glass-portal rounded-3xl w-full max-w-md p-8 animate-float relative z-10 transition-all duration-300 ease-out"
       style="animation-duration: 8s;"
       :style="{ transform: 'perspective(1000px) rotateX(' + (-my) + 'deg) rotateY(' + (mx) + 'deg)' }"
       x-data="{
         msLoading: false,
         localLoading: false
       }">
    
    <!-- Portal Inner (Corner brackets targets) -->
    <div class="glass-portal-inner"></div>

    <!-- Laser scanning beam -->
    <div class="laser-scanner"></div>

    <!-- System Telemetry Row -->
    <div class="flex justify-between items-center mb-8 text-[9px] font-tech text-white/30 tracking-wider">
      <div class="flex items-center gap-1.5 bg-white/5 px-2.5 py-1 rounded border border-white/5">
        <span class="w-1.5 h-1.5 bg-green-400 rounded-full shadow-[0_0_8px_#39ff14]"></span>
        <span class="text-white/60">SYS_STATUS: ONLINE</span>
      </div>
      <div class="bg-white/5 px-2.5 py-1 rounded border border-white/5 text-cyan-400/80 uppercase">
        SECURE GATEWAY v4.2
      </div>
    </div>

    <!-- Holographic AI Core Sphere -->
    <div class="text-center mb-8">
      <div class="relative w-36 h-36 mx-auto mb-5 flex items-center justify-center">
        <!-- SVG Concentric technical rings (rotating) -->
        <svg class="absolute inset-0 w-full h-full text-cyan-400/30 animate-spin-slow" viewBox="0 0 100 100">
          <circle cx="50" cy="50" r="46" stroke="currentColor" stroke-width="1" stroke-dasharray="10 8" fill="none" />
          <circle cx="50" cy="50" r="40" stroke="currentColor" stroke-width="0.5" stroke-dasharray="2 4" fill="none" />
        </svg>
        
        <svg class="absolute w-[86%] h-[86%] text-purple-500/40 animate-spin-reverse" viewBox="0 0 100 100" style="animation-duration: 12s;">
          <circle cx="50" cy="50" r="45" stroke="currentColor" stroke-width="1.2" stroke-dasharray="30 8 10 8" fill="none" />
          <circle cx="50" cy="50" r="42" stroke="currentColor" stroke-width="0.8" stroke-dasharray="1 5" fill="none" />
        </svg>

        <!-- Reticle grid -->
        <div class="absolute inset-0 pointer-events-none flex items-center justify-center">
          <div class="w-[90%] h-[1px] bg-gradient-to-r from-transparent via-cyan-400/20 to-transparent"></div>
        </div>
        <div class="absolute inset-0 pointer-events-none flex items-center justify-center">
          <div class="h-[90%] w-[1px] bg-gradient-to-b from-transparent via-cyan-400/20 to-transparent"></div>
        </div>

        <!-- Inner Target Corner Marks -->
        <div class="absolute inset-2 flex justify-between items-center pointer-events-none opacity-60">
          <div class="w-1.5 h-1.5 border-t border-l border-cyan-400"></div>
          <div class="w-1.5 h-1.5 border-t border-r border-cyan-400"></div>
        </div>
        <div class="absolute inset-2 flex flex-col justify-between items-center pointer-events-none opacity-60">
          <div class="w-full flex justify-between">
            <div class="w-1.5 h-1.5 border-t border-l border-cyan-400"></div>
            <div class="w-1.5 h-1.5 border-t border-r border-cyan-400"></div>
          </div>
          <div class="w-full flex justify-between">
            <div class="w-1.5 h-1.5 border-b border-l border-cyan-400"></div>
            <div class="w-1.5 h-1.5 border-b border-r border-cyan-400"></div>
          </div>
        </div>

        <!-- Glowing Central Sphere -->
        <div class="absolute w-[64%] h-[64%] rounded-full bg-gradient-to-tr from-cyan-500/80 via-indigo-600/80 to-purple-600/80 flex items-center justify-center shadow-[0_0_35px_rgba(0,242,254,0.6)] animate-pulse"
             style="animation-duration: 3s;">
          <!-- Modern Chatbot SVG Icon -->
          <svg class="w-10 h-10 text-white drop-shadow-[0_2px_8px_rgba(0,242,254,0.8)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
          </svg>
        </div>

        <!-- HUD core badge -->
        <div class="absolute -bottom-1.5 left-1/2 transform -translate-x-1/2 bg-[#02040c] border border-cyan-400/40 px-2 py-0.5 rounded text-[8px] font-cyber text-cyan-400 tracking-widest uppercase shadow-md shadow-black">
          AI_CORE_ACTIVE
        </div>
      </div>

      <!-- App Title -->
      <h1 class="text-2xl font-bold text-white tracking-wide font-sans mt-3"><?= htmlspecialchars($appName) ?></h1>
      <p class="text-cyan-300/70 text-xs mt-1 font-tech uppercase tracking-widest">// ADMINISTRATOR CONTROL PORTAL</p>
    </div>

    <!-- Error Alert Banner -->
    <?php if ($error): ?>
    <div class="mb-6 px-4 py-3.5 rounded-xl text-sm flex items-start gap-3 border border-red-500/30 animate-pulse-glow"
         style="background: rgba(239, 68, 68, 0.08);">
      <svg class="w-5 h-5 text-red-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <span class="text-red-200 font-sans leading-relaxed"><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <!-- ─── Single Microsoft Office 365 Login Button ─── -->
    <?php if ($enableMicrosoft && !$showLocalForm): ?>
    <div class="space-y-6">
      <div class="text-center">
        <p class="text-xs text-white/50 leading-relaxed font-sans px-4">
          กรุณาลงชื่อเข้าใช้ด้วยบัญชี Office 365 ของมหาวิทยาลัยพะเยา (@up.ac.th) เพื่อความปลอดภัยในการบริหารจัดการระบบ
        </p>
      </div>

      <a href="<?= htmlspecialchars($msLoginUrl) ?>" id="ms-login-btn"
         class="tech-btn-ms relative flex items-center justify-center gap-4 w-full py-5 px-7 rounded-[22px] text-white font-semibold cursor-pointer group shadow-[0_4px_20px_rgba(6,182,212,0.1)] hover:shadow-[0_6px_30px_rgba(189,0,255,0.25)] transition-all duration-300"
         @click="msLoading = true">
        
        <!-- Office 365 Logo container -->
        <div class="bg-white/10 p-2.5 rounded-xl border border-white/15 shrink-0 group-hover:bg-white/20 transition-all duration-300 shadow-[inset_0_0_10px_rgba(255,255,255,0.05)]">
          <svg viewBox="0 0 24 24" class="w-7 h-7" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <linearGradient id="o365Gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#00f2fe" />
                <stop offset="50%" stop-color="#818cf8" />
                <stop offset="100%" stop-color="#bd00ff" />
              </linearGradient>
            </defs>
            <path d="M12 .002a28.085 28.085 0 0 0-4.043.29c-.838.12-1.666.304-2.483.55a14.28 14.28 0 0 0-3.116 1.411 9.4 9.4 0 0 0-1.87 1.58A5.952 5.952 0 0 0 .006 6.8c.002.392.067.783.193 1.155a5.53 5.53 0 0 0 .97 1.761 11.238 11.238 0 0 0 2.227 2.052c.983.743 2.023 1.4 3.111 1.968a35.8 35.8 0 0 0 5.6 2.378c2.1.728 4.254 1.3 6.448 1.708l1.32.229a10.825 10.825 0 0 0 1.258.106 2.7 2.7 0 0 0 1.905-.632 2.394 2.394 0 0 0 .8-1.572 7.025 7.025 0 0 0 .151-1.391V6.26c0-.987-.145-1.97-.433-2.919A9.155 9.155 0 0 0 22.39 1.134 4.545 4.545 0 0 0 20.316.035a10.224 10.224 0 0 0-3.328-.033A38.257 38.257 0 0 0 12 .002Zm.543 2.71c.71-.004 1.418.04 2.122.13a17.206 17.206 0 0 1 2.378.498c.706.21 1.385.5 2.03.87a4.024 4.024 0 0 1 1.487 1.523c.319.605.474 1.28.452 1.959v7.839a3.86 3.86 0 0 1-.227 1.282.89.89 0 0 1-.724.5.94.94 0 0 1-.41-.044c-.218-.088-.413-.23-.57-.411a7.848 7.848 0 0 1-.84-1.289c-.394-.74-.836-1.454-1.321-2.138a35.8 35.8 0 0 0-3.23-3.844 57.065 57.065 0 0 0-4.81-4.63 27.272 27.272 0 0 1 4.675-2.26Zm-6.536.852a16.892 16.892 0 0 1 2.062 1.976c.772.846 1.516 1.719 2.228 2.618.665.84 1.3 1.705 1.905 2.589a69.865 69.865 0 0 1 3.25 5.234 40.54 40.54 0 0 1 1.79 3.992c.264.717.5 1.444.707 2.18.077.268.125.543.144.823a.753.753 0 0 1-.168.529.585.585 0 0 1-.456.19c-.328.002-.656-.026-.98-.084l-.84-.146c-1.895-.357-3.76-.856-5.578-1.492a34.333 34.333 0 0 1-5.02-2.115 32.551 32.551 0 0 1-4.225-2.613A9.761 9.761 0 0 1 .49 13.917a4.276 4.276 0 0 1-.476-1.636c.01-.652.176-1.292.483-1.87a7.202 7.202 0 0 1 1.353-1.745A13.228 13.228 0 0 1 4.5 6.94a24.28 24.28 0 0 1 6.007-3.376ZM6.502 6.568a18.397 18.397 0 0 0-3.327 2.072 6.388 6.388 0 0 0-1.868 2.13 1.968 1.968 0 0 0-.256.776c.026.046.069.079.119.09a.377.377 0 0 0 .193 0c.266-.08.52-.2.759-.356.634-.413 1.25-.85 1.848-1.312a32.96 32.96 0 0 0 3.738-3.374c-.407-.468-.813-.938-1.206-1.426Z" fill="url(#o365Gradient)"/>
          </svg>
        </div>

        <div class="text-left leading-tight flex-1 z-10 pl-1.5">
          <template x-if="!msLoading">
            <div>
              <span class="block text-[15.5px] font-bold tracking-wide text-white group-hover:text-cyan-300 transition-colors">เข้าสู่ระบบด้วย Office 365</span>
              <span class="block text-[9.5px] text-cyan-300/50 font-tech tracking-widest mt-0.5 uppercase">// ESTABLISH M365 SECURE LINK</span>
            </div>
          </template>
          <template x-if="msLoading">
            <span class="flex items-center gap-3 text-[15.5px] font-bold text-cyan-300">
              <span class="hud-spinner"></span> 
              <span>ESTABLISHING SECURE BRIDGE...</span>
            </span>
          </template>
        </div>

        <svg class="w-4 h-4 text-white/40 group-hover:text-cyan-400 group-hover:translate-x-1.5 transition-all duration-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
        </svg>
      </a>

      <p class="text-[8px] text-white/30 text-center font-tech uppercase tracking-[0.2em]">
        OFFICIAL ACADEMIC SECURITY PROTOCOL · UP.AC.TH
      </p>
    </div>
    <?php endif; ?>

    <!-- ─── Emergency Backdoor Local Login Form ─── -->
    <?php if ($showLocalForm): ?>
    <form method="POST" action="" @submit="localLoading = true" class="space-y-5">
      <?= Auth::csrfField() ?>

      <div class="p-3.5 rounded-xl border border-yellow-500/20 bg-yellow-500/5 text-center">
        <span class="text-[10px] font-tech text-yellow-400 uppercase tracking-widest block font-bold">
          [ MANUAL OVERRIDE PROTOCOL ACTIVE ]
        </span>
        <span class="text-[9px] text-white/40 block mt-0.5">
          LOCAL BYPASS AUTHENTICATION
        </span>
      </div>

      <!-- Identifier Field -->
      <div class="space-y-1.5 relative">
        <label for="identifier" class="block text-[10px] font-tech text-white/50 uppercase tracking-wider">// USERNAME / EMAIL</label>
        <div class="relative rounded-xl overflow-hidden">
          <input type="text" id="identifier" name="identifier" required placeholder="admin@domain.com"
                 class="w-full px-4 py-3 bg-[#020410]/95 border border-cyan-500/25 rounded-xl text-white text-sm placeholder-white/20 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400/30 transition-all font-sans">
        </div>
      </div>

      <!-- Password Field -->
      <div class="space-y-1.5 relative">
        <label for="password" class="block text-[10px] font-tech text-white/50 uppercase tracking-wider">// SECURITY PASSWORD</label>
        <div class="relative rounded-xl overflow-hidden">
          <input type="password" id="password" name="password" required placeholder="••••••••••••"
                 class="w-full px-4 py-3 bg-[#020410]/95 border border-cyan-500/25 rounded-xl text-white text-sm placeholder-white/20 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400/30 transition-all font-sans">
        </div>
      </div>

      <!-- Submit Button -->
      <button type="submit" :disabled="localLoading"
              class="relative flex items-center justify-center gap-3.5 w-full py-4 px-6 rounded-2xl text-white font-bold cursor-pointer transition-all duration-300 tech-btn-ms">
        <template x-if="!localLoading">
          <span class="text-sm tracking-wide">AUTHORIZE MANUAL BYPASS</span>
        </template>
        <template x-if="localLoading">
          <span class="flex items-center gap-2.5 text-sm font-bold">
            <span class="hud-spinner"></span> 
            <span class="text-cyan-300">DECRYPTING AND LOGGING IN...</span>
          </span>
        </template>
      </button>

      <!-- Cancel Override Link -->
      <div class="text-center pt-2">
        <a href="login.php" class="text-[10px] text-cyan-400/60 hover:text-cyan-400 font-tech uppercase tracking-widest transition-all duration-300">// CANCEL OVERRIDE</a>
      </div>
    </form>
    <?php endif; ?>

  </div>

  <!-- Technical Monospace Footer -->
  <footer class="relative z-10 text-center py-8 text-[9px] font-tech text-white/30 tracking-wider">
    <p class="uppercase">© <?= date('Y') ?> <?= htmlspecialchars($appName) ?> · มหาวิทยาลัยพะเยา</p>
    <p class="text-[7px] text-cyan-400/40 mt-1 uppercase">MAINFRAME // SECURE CONNECTION ACCESS v4.2</p>
  </footer>

</body>
</html>
