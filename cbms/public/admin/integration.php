<?php
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Helpers\Database;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

$pageTitle  = 'Integration Guide';
$breadcrumb = ['Admin', 'Integration'];
Auth::requirePermission('manage_settings');

$appUrl    = rtrim($_ENV['APP_URL'] ?? 'https://appupili.up.ac.th/cbms', '/');
$botId     = Auth::requireBot();
$activeBot = Auth::activeBot();
$botSlug   = $activeBot['slug'] ?? 'default';
$botName   = $activeBot['name'] ?? 'Default Bot';
$db        = Database::getInstance();

// Load bot's platform settings for status display
$botSettings = [];
$rows = $db->fetchAll('SELECT setting_key, setting_value FROM bot_settings WHERE bot_id = ?', [$botId]);
foreach ($rows as $r) $botSettings[$r['setting_key']] = $r['setting_value'];

$hasFbToken   = !empty($botSettings['facebook_page_token']);
$hasLineToken = !empty($botSettings['line_channel_token']);

require __DIR__ . '/layouts/header.php';
?>

<div>

  <!-- Current Bot Banner -->
  <div class="mb-6 p-4 rounded-xl bg-brand-50 border border-brand-200 flex items-center gap-3">
    <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-sm font-bold shrink-0"
         style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
      <?= strtoupper(mb_substr($botName, 0, 1)) ?>
    </div>
    <div class="flex-1 min-w-0">
      <p class="text-sm font-bold text-brand-800">บอท: <?= htmlspecialchars($botName) ?></p>
      <p class="text-xs text-brand-600">slug: <code class="font-mono"><?= htmlspecialchars($botSlug) ?></code></p>
    </div>
    <a href="bot-edit.php?id=<?= $botId ?>#widget"
       class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-semibold hover:bg-brand-700 transition-colors">
      ตั้งค่า Widget &amp; แชท
    </a>
  </div>

  <!-- Platform Cards Grid -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-8">

    <!-- Web Chat Widget -->
    <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden card-hover">
      <div class="px-5 py-4 bg-indigo-50 border-b border-indigo-100 flex items-center gap-2">
        <span class="text-lg">🌐</span>
        <h3 class="font-semibold text-indigo-800">Web Chat Widget</h3>
        <span class="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-700">
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> พร้อมใช้งาน
        </span>
      </div>
      <div class="p-5">
        <p class="text-xs text-slate-500 mb-3">ฝัง Widget ลงในเว็บไซต์ เพื่อให้ผู้เยี่ยมชมแชทกับ AI ได้ทันที</p>
        <div class="bg-slate-800 text-green-400 font-mono text-[10px] p-3 rounded-xl overflow-x-auto mb-3">
          <div>&lt;script src="<?= $appUrl ?>/widget.js"</div>
          <div>&nbsp; data-bot="<?= htmlspecialchars($botSlug) ?>"</div>
          <div>&nbsp; data-color="#4f46e5"&gt;&lt;/script&gt;</div>
        </div>
        <a href="bot-edit.php?id=<?= $botId ?>#widget"
           class="inline-flex items-center gap-1.5 text-xs font-semibold text-indigo-600 hover:text-indigo-800 transition-colors">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
          </svg>
          ปรับแต่ง Widget &amp; ทดสอบแชท
        </a>
      </div>
    </div>

    <!-- Facebook -->
    <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden card-hover">
      <div class="px-5 py-4 bg-blue-50 border-b border-blue-100 flex items-center gap-2">
        <span class="text-lg">📘</span>
        <h3 class="font-semibold text-blue-800">Facebook Messenger</h3>
        <?php if ($hasFbToken): ?>
        <span class="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-700">
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> เชื่อมต่อแล้ว
        </span>
        <?php else: ?>
        <span class="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500">
          <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> ยังไม่ได้ตั้งค่า
        </span>
        <?php endif; ?>
      </div>
      <div class="p-5">
        <p class="text-xs text-slate-500 mb-2">Webhook URL สำหรับบอทนี้:</p>
        <code class="block bg-blue-50 text-blue-700 text-[10px] font-mono p-2 rounded-lg break-all"><?= $appUrl ?>/api/webhook-facebook.php?bot=<?= htmlspecialchars($botSlug) ?></code>
        <div class="mt-3 flex gap-2">
          <a href="bot-edit.php?id=<?= $botId ?>#platform"
             class="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0"/>
            </svg>
            ตั้งค่า Token
          </a>
        </div>
      </div>
    </div>

    <!-- LINE -->
    <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden card-hover">
      <div class="px-5 py-4 bg-emerald-50 border-b border-emerald-100 flex items-center gap-2">
        <span class="text-lg">💚</span>
        <h3 class="font-semibold text-emerald-800">LINE Messaging API</h3>
        <?php if ($hasLineToken): ?>
        <span class="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-700">
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> เชื่อมต่อแล้ว
        </span>
        <?php else: ?>
        <span class="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500">
          <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> ยังไม่ได้ตั้งค่า
        </span>
        <?php endif; ?>
      </div>
      <div class="p-5">
        <p class="text-xs text-slate-500 mb-2">Webhook URL สำหรับบอทนี้:</p>
        <code class="block bg-emerald-50 text-emerald-700 text-[10px] font-mono p-2 rounded-lg break-all"><?= $appUrl ?>/api/webhook-line.php?bot=<?= htmlspecialchars($botSlug) ?></code>
        <div class="mt-3 flex gap-2">
          <a href="bot-edit.php?id=<?= $botId ?>#platform"
             class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600 hover:text-emerald-800">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0"/>
            </svg>
            ตั้งค่า Token
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Setup Guides -->
  <div class="space-y-5">

    <!-- Facebook Guide -->
    <details class="bg-white rounded-2xl border border-slate-100 overflow-hidden group">
      <summary class="px-6 py-4 cursor-pointer flex items-center gap-3 hover:bg-slate-50 transition-colors">
        <span class="text-xl">📘</span>
        <h3 class="font-semibold text-slate-800 flex-1">วิธีเชื่อมต่อ Facebook Messenger</h3>
        <svg class="w-5 h-5 text-slate-400 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
      </summary>
      <div class="px-6 pb-6">
        <div class="prose prose-sm max-w-none text-slate-600">
          <h4 class="text-slate-800 font-bold mb-2">1. สร้างแอปใน Meta for Developers</h4>
          <ol class="list-decimal pl-5 space-y-2 mb-6">
            <li>ไปที่ <a href="https://developers.facebook.com" target="_blank" class="text-blue-600 hover:underline">Meta for Developers</a> และสร้างแอปพลิเคชันใหม่ เลือกประเภทเป็น <strong>ธุรกิจ (Business)</strong></li>
            <li>ในหน้า Dashboard เพิ่มผลิตภัณฑ์ <strong>Messenger</strong></li>
            <li>ไปที่เมนู <strong>การตั้งค่า (Settings) &gt; ข้อมูลพื้นฐาน (Basic)</strong> เพื่อคัดลอก <code>App Secret</code></li>
          </ol>
          <h4 class="text-slate-800 font-bold mb-2">2. ตั้งค่า Webhook</h4>
          <ol class="list-decimal pl-5 space-y-2 mb-6">
            <li>ในหน้า Messenger Settings ไปที่หัวข้อ <strong>Webhooks</strong> คลิก "Setup Webhooks"</li>
            <li>กรอกข้อมูล:
              <ul class="list-disc pl-5 mt-2 space-y-1">
                <li><strong>Callback URL:</strong> <code class="bg-blue-50 px-1.5 py-0.5 rounded text-blue-600"><?= $appUrl ?>/api/webhook-facebook.php?bot=<?= htmlspecialchars($botSlug) ?></code></li>
                <li><strong>Verify Token:</strong> ค่าที่ตั้งไว้ใน <a href="bot-edit.php?id=<?= $botId ?>#platform" class="text-blue-600 underline">Platform Settings ของบอท</a></li>
              </ul>
            </li>
            <li>คลิก Verify and Save</li>
            <li>เพิ่มการติดตาม: <code>messages</code> และ <code>messaging_postbacks</code></li>
          </ol>
          <h4 class="text-slate-800 font-bold mb-2">3. เชื่อมต่อเพจ</h4>
          <ol class="list-decimal pl-5 space-y-2">
            <li>ในหัวข้อ Access Tokens คลิก <strong>เพิ่มเพจ</strong> แล้วเลือกเพจที่ต้องการ</li>
            <li>คัดลอก <strong>Page Access Token</strong></li>
            <li>นำ Token และ App Secret ไปใส่ใน <a href="bot-edit.php?id=<?= $botId ?>#platform" class="text-blue-600 underline">Platform Settings ของบอท</a></li>
          </ol>
        </div>
      </div>
    </details>

    <!-- LINE Guide -->
    <details class="bg-white rounded-2xl border border-slate-100 overflow-hidden group">
      <summary class="px-6 py-4 cursor-pointer flex items-center gap-3 hover:bg-slate-50 transition-colors">
        <span class="text-xl">💚</span>
        <h3 class="font-semibold text-slate-800 flex-1">วิธีเชื่อมต่อ LINE Messaging API</h3>
        <svg class="w-5 h-5 text-slate-400 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
      </summary>
      <div class="px-6 pb-6">
        <div class="prose prose-sm max-w-none text-slate-600">
          <h4 class="text-slate-800 font-bold mb-2">1. สร้าง Provider และ Channel</h4>
          <ol class="list-decimal pl-5 space-y-2 mb-6">
            <li>เข้าสู่ระบบที่ <a href="https://developers.line.biz" target="_blank" class="text-emerald-600 hover:underline">LINE Developers Console</a></li>
            <li>สร้าง Provider ใหม่ หรือเลือก Provider ที่มีอยู่</li>
            <li>สร้าง Channel ใหม่ เลือก <strong>Messaging API</strong></li>
          </ol>
          <h4 class="text-slate-800 font-bold mb-2">2. ตั้งค่า Messaging API</h4>
          <ol class="list-decimal pl-5 space-y-2 mb-6">
            <li>ไปที่แท็บ <strong>Messaging API</strong></li>
            <li>สร้าง Channel access token (Long-lived)</li>
            <li>เปิดใช้งาน <strong>Use webhook</strong></li>
            <li>ตั้ง Webhook URL: <code class="bg-emerald-50 px-1.5 py-0.5 rounded text-emerald-600"><?= $appUrl ?>/api/webhook-line.php?bot=<?= htmlspecialchars($botSlug) ?></code></li>
            <li>กด <strong>Verify</strong> เพื่อทดสอบ</li>
            <li>ปิด Auto-reply messages ใน LINE Official Account Manager</li>
          </ol>
          <h4 class="text-slate-800 font-bold mb-2">3. นำค่ามาใส่ในระบบ</h4>
          <ol class="list-decimal pl-5 space-y-2">
            <li>คัดลอก <strong>Channel ID</strong> และ <strong>Channel Secret</strong> จาก Basic settings</li>
            <li>คัดลอก <strong>Channel Access Token</strong> จาก Messaging API</li>
            <li>นำทั้ง 3 ค่าไปใส่ใน <a href="bot-edit.php?id=<?= $botId ?>#platform" class="text-emerald-600 underline">Platform Settings ของบอท</a></li>
          </ol>
        </div>
      </div>
    </details>

    <!-- Web Widget Guide -->
    <details class="bg-white rounded-2xl border border-slate-100 overflow-hidden group">
      <summary class="px-6 py-4 cursor-pointer flex items-center gap-3 hover:bg-slate-50 transition-colors">
        <span class="text-xl">🌐</span>
        <h3 class="font-semibold text-slate-800 flex-1">วิธีฝัง Web Chat Widget</h3>
        <svg class="w-5 h-5 text-slate-400 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
      </summary>
      <div class="px-6 pb-6">
        <div class="prose prose-sm max-w-none text-slate-600">
          <h4 class="text-slate-800 font-bold mb-2">วิธีใช้งาน</h4>
          <ol class="list-decimal pl-5 space-y-2 mb-4">
            <li>คัดลอก Embed Code จากหน้า <a href="bot-edit.php?id=<?= $botId ?>#widget" class="text-indigo-600 underline">Widget Settings ของบอท</a></li>
            <li>วาง Script ก่อนปิดแท็ก <code>&lt;/body&gt;</code> ในหน้าเว็บไซต์ของคุณ</li>
            <li>ปุ่มแชทจะปรากฏมุมขวาล่างโดยอัตโนมัติ</li>
          </ol>
          <h4 class="text-slate-800 font-bold mb-2">Attributes ที่ใช้ได้</h4>
          <div class="overflow-x-auto">
            <table class="text-xs w-full">
              <thead><tr class="bg-slate-50"><th class="px-3 py-2 text-left">Attribute</th><th class="px-3 py-2 text-left">คำอธิบาย</th><th class="px-3 py-2 text-left">ค่าเริ่มต้น</th></tr></thead>
              <tbody class="divide-y divide-slate-100">
                <tr><td class="px-3 py-2 font-mono">data-bot</td><td class="px-3 py-2">Slug ของบอท</td><td class="px-3 py-2">"default"</td></tr>
                <tr><td class="px-3 py-2 font-mono">data-color</td><td class="px-3 py-2">สีหลัก (Hex)</td><td class="px-3 py-2">#4f46e5</td></tr>
                <tr><td class="px-3 py-2 font-mono">data-icon</td><td class="px-3 py-2">URL รูปไอคอนปุ่มแชท</td><td class="px-3 py-2">-</td></tr>
                <tr><td class="px-3 py-2 font-mono">data-logo</td><td class="px-3 py-2">URL โลโก้ใน Header แชท</td><td class="px-3 py-2">-</td></tr>
                <tr><td class="px-3 py-2 font-mono">data-icon-size</td><td class="px-3 py-2">ขนาดไอคอน (px)</td><td class="px-3 py-2">60</td></tr>
                <tr><td class="px-3 py-2 font-mono">data-draggable</td><td class="px-3 py-2">ลากไอคอนได้</td><td class="px-3 py-2">"false"</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </details>
  </div>
</div>

<?php require __DIR__ . '/layouts/footer.php'; ?>
