<?php
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Database;
$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
$base    = rtrim($_ENV['APP_URL'] ?? 'https://appupili.up.ac.th/cbms', '/');
$appName = Database::appName();
?>
<!DOCTYPE html>
<html lang="th" class="scroll-smooth">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($appName) ?> — Demo</title>
<meta name="description" content="ระบบ AI Chatbot แบบ Multi-Platform พร้อม RAG Engine และ Admin Dashboard สำหรับมหาวิทยาลัยพะเยา">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
tailwind.config={theme:{extend:{
  fontFamily:{sans:['Sarabun','Inter','sans-serif']},
  colors:{brand:{50:'#eef2ff',100:'#e0e7ff',200:'#c7d2fe',300:'#a5b4fc',400:'#818cf8',500:'#6366f1',600:'#4f46e5',700:'#4338ca',800:'#3730a3',900:'#312e81'}},
  animation:{'float':'float 7s ease-in-out infinite','float-rev':'float 9s ease-in-out infinite reverse','fade-up':'fadeUp .7s ease both','pop':'pop .5s ease both','bob':'bob 2.4s ease-in-out infinite'},
  keyframes:{
    float:{'0%,100%':{transform:'translateY(0)'},'50%':{transform:'translateY(-18px)'}},
    bob:{'0%,100%':{transform:'translateY(0)'},'50%':{transform:'translateY(-5px)'}},
    fadeUp:{from:{opacity:'0',transform:'translateY(22px)'},to:{opacity:'1',transform:'none'}},
    pop:{from:{opacity:'0',transform:'scale(.92)'},to:{opacity:'1',transform:'scale(1)'}}
  }
}}}
</script>
<style>
  body{font-family:'Sarabun','Inter',sans-serif;overflow-x:clip;}
  /* Animated deep-space gradient */
  .gradient-bg{background:linear-gradient(135deg,#1e1b4b 0%,#312e81 28%,#1e3a5f 58%,#0f172a 100%);background-size:400% 400%;animation:shift 14s ease infinite;}
  @keyframes shift{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}

  /* Robust gradient text (webkit + standard, with text-fill fallback) */
  .text-grad{
    background:linear-gradient(110deg,#a5b4fc 0%,#c084fc 45%,#f0abfc 100%);
    -webkit-background-clip:text;background-clip:text;
    -webkit-text-fill-color:transparent;color:transparent;
    display:inline-block;padding-bottom:.08em;
  }

  .glass{background:rgba(255,255,255,.06);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.12);}
  .orb{position:absolute;border-radius:50%;filter:blur(90px);opacity:.14;pointer-events:none;}
  .grid-overlay{background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:44px 44px;}

  .card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);transition:transform .25s,box-shadow .25s,background .25s,border-color .25s;}
  .card:hover{background:rgba(255,255,255,.09);transform:translateY(-6px);box-shadow:0 22px 50px rgba(79,70,229,.25);border-color:rgba(129,140,248,.4);}

  .btn-primary{background:linear-gradient(135deg,#4f46e5,#7c3aed);transition:transform .2s,box-shadow .2s;box-shadow:0 8px 24px rgba(79,70,229,.35);}
  .btn-primary:hover{transform:translateY(-2px);box-shadow:0 14px 36px rgba(124,58,237,.55);}
  .btn-ghost{border:1px solid rgba(255,255,255,.22);transition:background .2s,border-color .2s,transform .2s;}
  .btn-ghost:hover{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.4);transform:translateY(-2px);}

  .badge{background:rgba(99,102,241,.16);border:1px solid rgba(129,140,248,.35);}
  .chip{background:linear-gradient(135deg,rgba(99,102,241,.25),rgba(124,58,237,.25));border:1px solid rgba(129,140,248,.3);}

  /* Chat preview */
  .bubble-bot{background:#fff;color:#1e293b;}
  .bubble-user{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;}
  .typing span{width:7px;height:7px;border-radius:50%;background:#94a3b8;display:inline-block;animation:bob 1.1s infinite}
  .typing span:nth-child(2){animation-delay:.15s}.typing span:nth-child(3){animation-delay:.3s}

  .reveal{opacity:0;transform:translateY(22px);transition:opacity .7s ease,transform .7s ease;}
  .reveal.show{opacity:1;transform:none;}
</style>
</head>
<body class="gradient-bg min-h-screen text-white relative">

<!-- Decorative layers -->
<div class="fixed inset-0 grid-overlay opacity-60 pointer-events-none"></div>
<div class="orb w-[28rem] h-[28rem] bg-indigo-500 top-[-12%] left-[-8%] animate-float"></div>
<div class="orb w-96 h-96 bg-fuchsia-600 bottom-[6%] right-[-8%] animate-float-rev"></div>
<div class="orb w-72 h-72 bg-sky-500 top-[40%] left-[55%] animate-float"></div>

<!-- Nav -->
<nav class="fixed top-0 left-0 right-0 z-50 glass">
  <div class="max-w-6xl mx-auto px-6 h-16 flex items-center justify-between">
    <a href="<?= $base ?>/" class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-xl flex items-center justify-center text-lg shadow-lg" style="background:linear-gradient(135deg,#4f46e5,#7c3aed)">🤖</div>
      <span class="font-bold text-lg tracking-tight"><?= htmlspecialchars($appName) ?></span>
    </a>
    <div class="hidden md:flex items-center gap-7 text-sm text-indigo-200">
      <a href="#features" class="hover:text-white transition-colors">ฟีเจอร์</a>
      <a href="#platforms" class="hover:text-white transition-colors">แพลตฟอร์ม</a>
      <a href="#embed" class="hover:text-white transition-colors">วิธีใช้งาน</a>
    </div>
    <div class="flex gap-3">
      <a href="<?= $base ?>/admin/login.php" class="btn-primary px-4 py-2 rounded-xl text-sm font-semibold">Admin Panel</a>
    </div>
  </div>
</nav>

<!-- Hero -->
<section class="relative min-h-screen flex items-center px-6 pt-28 pb-20">
  <div class="max-w-6xl mx-auto grid lg:grid-cols-2 gap-14 items-center w-full">

    <!-- Left: copy -->
    <div class="text-center lg:text-left animate-fade-up">
      <span class="badge inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-indigo-200 text-sm font-medium mb-6">
        🚀 Powered by OpenRouter AI · RAG Technology
      </span>
      <h1 class="text-5xl md:text-6xl font-extrabold leading-[1.08] mb-6 tracking-tight">
        ผู้ช่วย AI อัจฉริยะ<br>
        สำหรับ <span class="text-grad">มหาวิทยาลัยพะเยา</span>
      </h1>
      <p class="text-lg text-indigo-200/90 max-w-xl mx-auto lg:mx-0 mb-8 leading-relaxed">
        ระบบตอบคำถามอัตโนมัติแบบ Multi-Platform รองรับ Web, Facebook Messenger และ LINE
        พร้อม Knowledge Base จาก Google Drive และ Dashboard จัดการแบบ Real-time
      </p>
      <div class="flex flex-wrap gap-4 justify-center lg:justify-start mb-10">
        <a href="<?= $base ?>/admin/login.php" class="btn-primary px-8 py-3.5 rounded-2xl text-base font-bold flex items-center gap-2">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
          </svg>
          เข้าสู่ Admin Dashboard
        </a>
      </div>
      <!-- Stats -->
      <div class="flex justify-center lg:justify-start gap-8 text-center lg:text-left">
        <div>
          <div class="text-2xl font-extrabold text-grad">100+</div>
          <div class="text-xs text-indigo-300 mt-0.5">AI Models</div>
        </div>
        <div class="w-px bg-white/15"></div>
        <div>
          <div class="text-2xl font-extrabold text-grad">3</div>
          <div class="text-xs text-indigo-300 mt-0.5">แพลตฟอร์ม</div>
        </div>
        <div class="w-px bg-white/15"></div>
        <div>
          <div class="text-2xl font-extrabold text-grad">24/7</div>
          <div class="text-xs text-indigo-300 mt-0.5">พร้อมให้บริการ</div>
        </div>
      </div>
    </div>

    <!-- Right: chat preview mockup -->
    <div class="relative animate-pop hidden lg:block">
      <div class="absolute -inset-4 bg-gradient-to-tr from-indigo-600/30 to-fuchsia-600/30 blur-2xl rounded-[2rem]"></div>
      <div class="relative glass rounded-[1.75rem] p-5 shadow-2xl max-w-md mx-auto animate-float">
        <!-- header -->
        <div class="flex items-center gap-3 pb-4 border-b border-white/10">
          <div class="w-11 h-11 rounded-2xl flex items-center justify-center text-xl" style="background:linear-gradient(135deg,#4f46e5,#7c3aed)">🤖</div>
          <div>
            <div class="font-bold leading-tight">น้องบันน่า · AI Assistant</div>
            <div class="text-xs text-emerald-300 flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-400"></span>Online · ตอบทันที</div>
          </div>
        </div>
        <!-- messages -->
        <div class="space-y-3 py-4">
          <div class="flex justify-end">
            <div class="bubble-user px-4 py-2.5 rounded-2xl rounded-br-md text-sm max-w-[80%] shadow-lg">ห้องสมุดเปิดกี่โมงครับ?</div>
          </div>
          <div class="flex">
            <div class="bubble-bot px-4 py-2.5 rounded-2xl rounded-bl-md text-sm max-w-[85%] shadow leading-relaxed">
              เปิดบริการ จันทร์–ศุกร์ <b>08.30–16.30 น.</b> ค่ะ 📚<br>ส่วนห้องอ่านหนังสือเปิด <b>24 ชั่วโมง</b> ทุกวันเลยนะคะ 😊
            </div>
          </div>
          <div class="flex justify-end">
            <div class="bubble-user px-4 py-2.5 rounded-2xl rounded-br-md text-sm max-w-[80%] shadow-lg">แล้วยืมหนังสือได้กี่เล่ม?</div>
          </div>
          <div class="flex">
            <div class="bubble-bot px-3.5 py-3 rounded-2xl rounded-bl-md shadow">
              <span class="typing inline-flex gap-1.5"><span></span><span></span><span></span></span>
            </div>
          </div>
        </div>
        <!-- input -->
        <div class="flex items-center gap-2 pt-3 border-t border-white/10">
          <div class="flex-1 bg-white/8 border border-white/15 rounded-xl px-4 py-2.5 text-sm text-indigo-200/70">พิมพ์ข้อความ...</div>
          <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0" style="background:linear-gradient(135deg,#4f46e5,#7c3aed)">
            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- scroll cue -->
  <a href="#features" class="absolute bottom-6 left-1/2 -translate-x-1/2 text-indigo-300/70 hover:text-white transition-colors animate-bob">
    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
  </a>
</section>

<!-- Features -->
<section id="features" class="relative py-24 px-6">
  <div class="max-w-6xl mx-auto">
    <div class="text-center mb-14 reveal">
      <span class="chip inline-block px-3 py-1 rounded-full text-xs font-semibold text-indigo-200 mb-4">FEATURES</span>
      <h2 class="text-3xl md:text-4xl font-extrabold mb-3">ฟีเจอร์ครบ จบในระบบเดียว</h2>
      <p class="text-indigo-300">ออกแบบมาเพื่อองค์กร ใช้งานได้ทุกแพลตฟอร์ม</p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
      <?php $features = [
        ['🤖','RAG AI Engine','ดึงข้อมูลจาก Knowledge Base แล้วตอบคำถามด้วย AI อย่างแม่นยำ อ้างอิงแหล่งที่มาได้'],
        ['📱','Multi-Platform','รองรับ Web Chat, Facebook Messenger และ LINE Official Account ในระบบเดียว'],
        ['📂','Google Drive Sync','ดึงเอกสารจาก Google Drive มา Embed เป็นเวกเตอร์โดยอัตโนมัติ'],
        ['📊','Real-time Analytics','Dashboard แสดง Token usage, Cost และ Conversations แบบ Live'],
        ['🔐','Microsoft O365 Auth','เข้าสู่ระบบด้วย Microsoft พร้อมตรวจสอบสิทธิ์ผ่าน CAMS API'],
        ['💡','Multi-Model Support','เลือกใช้ AI Model จาก OpenRouter ได้หลายร้อยโมเดล'],
      ]; foreach ($features as $i => [$ic,$ti,$de]): ?>
      <div class="card rounded-2xl p-6 reveal" style="transition-delay:<?= $i*70 ?>ms">
        <div class="w-12 h-12 rounded-xl chip flex items-center justify-center text-2xl mb-4"><?= $ic ?></div>
        <h3 class="font-bold text-lg mb-2"><?= $ti ?></h3>
        <p class="text-indigo-300 text-sm leading-relaxed"><?= $de ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Platforms -->
<section id="platforms" class="relative py-24 px-6">
  <div class="max-w-5xl mx-auto">
    <div class="text-center mb-14 reveal">
      <span class="chip inline-block px-3 py-1 rounded-full text-xs font-semibold text-indigo-200 mb-4">PLATFORMS</span>
      <h2 class="text-3xl md:text-4xl font-extrabold">รองรับทุกช่องทางที่ลูกค้าอยู่</h2>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
      <?php $platforms = [
        ['🌐','Web Chat','ฝังได้ทุกเว็บด้วยโค้ดบรรทัดเดียว','admin/login.php','ทดลองในแอดมิน'],
        ['📘','Facebook','Messenger Bot พร้อม Persistent Menu','#','เร็วๆ นี้'],
        ['💚','LINE','Official Account ตอบอัตโนมัติ 24 ชม.','#','เร็วๆ นี้'],
      ]; foreach ($platforms as $i => [$ic,$na,$de,$link,$cta]): ?>
      <div class="card rounded-2xl p-7 text-center reveal" style="transition-delay:<?= $i*90 ?>ms">
        <div class="text-5xl mb-4"><?= $ic ?></div>
        <h3 class="font-bold text-lg mb-2"><?= $na ?></h3>
        <p class="text-indigo-300 text-sm mb-5 leading-relaxed"><?= $de ?></p>
        <a href="<?= $link !== '#' ? $base.'/'.$link : '#' ?>"
           class="inline-block text-sm px-5 py-2 rounded-xl border border-indigo-400/50 text-indigo-200 hover:bg-indigo-500/20 hover:border-indigo-400 transition-colors<?= $link === '#' ? ' opacity-60 pointer-events-none' : '' ?>">
          <?= $cta ?>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Embed Code -->
<section id="embed" class="relative py-24 px-6">
  <div class="max-w-3xl mx-auto reveal">
    <div class="glass rounded-3xl p-8 md:p-10 text-center relative overflow-hidden">
      <div class="absolute -top-16 -right-16 w-48 h-48 bg-fuchsia-500/20 blur-3xl rounded-full"></div>
      <span class="chip inline-block px-3 py-1 rounded-full text-xs font-semibold text-indigo-200 mb-4">QUICK START</span>
      <h2 class="text-2xl md:text-3xl font-extrabold mb-2">ฝัง Chat Widget ภายใน 1 นาที</h2>
      <p class="text-indigo-300 mb-7">วางโค้ดบรรทัดเดียวก่อนปิดแท็ก &lt;/body&gt; ในเว็บของคุณ</p>
      <div class="bg-slate-950/70 ring-1 ring-white/10 rounded-2xl p-5 text-left font-mono text-sm mb-2 relative group" x-data="{copied:false}">
        <div class="text-slate-500 mb-1">&lt;!-- เพิ่มก่อนปิด &lt;/body&gt; --&gt;</div>
        <div class="break-all"><span class="text-indigo-300">&lt;script</span> <span class="text-yellow-300">src</span>=<span class="text-emerald-400">"<?= $base ?>/widget.js"</span><span class="text-indigo-300">&gt;&lt;/script&gt;</span></div>
        <button @click="navigator.clipboard.writeText('&lt;script src=\'<?= $base ?>/widget.js\'&gt;&lt;/script&gt;');copied=true;setTimeout(()=>copied=false,2000)"
                class="absolute top-3 right-3 px-3 py-1.5 rounded-lg bg-white/10 text-white/70 text-xs hover:bg-white/20 transition-colors">
          <span x-text="copied?'✅ คัดลอกแล้ว':'📋 คัดลอก'"></span>
        </button>
      </div>
    </div>
  </div>
</section>

<!-- Footer -->
<footer class="relative py-12 text-center text-indigo-400 text-sm border-t border-white/10">
  <div class="flex items-center justify-center gap-2 mb-3">
    <div class="w-8 h-8 rounded-lg flex items-center justify-center text-sm" style="background:linear-gradient(135deg,#4f46e5,#7c3aed)">🤖</div>
    <span class="font-bold text-white"><?= htmlspecialchars($appName) ?></span>
  </div>
  <p>© <?= date('Y') ?> มหาวิทยาลัยพะเยา · สงวนลิขสิทธิ์</p>
  <p class="mt-2 text-xs text-indigo-500">Built with PHP 8.4 · OpenRouter AI · TailwindCSS</p>
</footer>

<!-- Scroll reveal -->
<script>
  const io = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('show'); io.unobserve(e.target); } });
  }, { threshold: 0.12 });
  document.querySelectorAll('.reveal').forEach(el => io.observe(el));
</script>
</body>
</html>
