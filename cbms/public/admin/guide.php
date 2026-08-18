<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/vendor/autoload.php';
use App\Helpers\Auth;

$dotenv = \Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$pageTitle  = 'User Guide';
$breadcrumb = ['Admin', 'User Guide'];
Auth::requireLogin();

$user = Auth::user();
$role = $user['role'] ?? 'viewer';

require __DIR__ . '/layouts/header.php';
?>

<div x-data="guideApp()" class="max-w-5xl mx-auto">

  <!-- Header -->
  <div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-800">คู่มือการใช้งานระบบ</h1>
    <p class="text-sm text-slate-500 mt-1">AI Chatbot Management System (CBMS) — คำแนะนำและวิธีใช้งานแต่ละส่วนของระบบ</p>
  </div>

  <div class="flex flex-col lg:flex-row gap-6">

    <!-- Table of Contents (sticky sidebar) -->
    <div class="lg:w-56 shrink-0">
      <div class="lg:sticky lg:top-20 bg-white rounded-2xl border border-slate-100 shadow-sm p-4">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">สารบัญ</p>
        <nav class="space-y-1">
          <template x-for="sec in sections" :key="sec.id">
            <a :href="'#' + sec.id"
               @click.prevent="scrollToSection(sec.id)"
               class="block px-3 py-1.5 rounded-lg text-sm font-medium transition-colors"
               :class="activeSection === sec.id ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700'"
               x-text="sec.label">
            </a>
          </template>
        </nav>
      </div>
    </div>

    <!-- Content -->
    <div class="flex-1 space-y-6">

      <!-- ═══ Overview ═══ -->
      <section id="sec-overview" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">ภาพรวมระบบ</h2>
        </div>
        <div class="prose prose-sm max-w-none text-slate-600 space-y-3">
          <p><strong>CBMS (AI Chatbot Management System)</strong> คือระบบบริหารจัดการ AI Chatbot สำหรับองค์กร รองรับการทำงานหลายบอทพร้อมกัน สามารถเชื่อมต่อกับช่องทางต่าง ๆ เช่น LINE, Facebook Messenger และ Web Chat</p>
          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
            <p class="font-semibold text-slate-700 mb-2">ความสามารถหลัก:</p>
            <ul class="list-disc list-inside space-y-1 text-sm">
              <li>สร้างและจัดการบอท AI หลายตัว แยกอิสระจากกัน</li>
              <li>ระบบ Knowledge Base — อัปโหลดเอกสารจาก Google Drive ให้บอทเรียนรู้</li>
              <li>ระบบ RAG (Retrieval-Augmented Generation) ค้นหาข้อมูลจาก Knowledge Base มาตอบคำถาม</li>
              <li>รองรับหลายช่องทาง: LINE OA, Facebook Messenger, Web Chat Widget</li>
              <li>ระบบ Handoff — ส่งต่อสนทนาให้เจ้าหน้าที่เมื่อ AI ตอบไม่ได้</li>
              <li>รายงานวิเคราะห์: Dashboard, Analytics, Token Usage</li>
              <li>จัดการสิทธิ์ผู้ใช้งาน (Super Admin / Admin / Viewer)</li>
            </ul>
          </div>
        </div>
      </section>

      <!-- ═══ Getting Started ═══ -->
      <section id="sec-start" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">เริ่มต้นใช้งาน</h2>
        </div>
        <div class="space-y-4 text-sm text-slate-600">
          <div class="flex gap-4 items-start">
            <span class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 text-sm font-bold flex items-center justify-center shrink-0">1</span>
            <div>
              <p class="font-semibold text-slate-800">เข้าสู่ระบบ</p>
              <p>ล็อกอินด้วย Microsoft Account ขององค์กร หรือบัญชี Local ที่ Super Admin สร้างให้</p>
            </div>
          </div>
          <div class="flex gap-4 items-start">
            <span class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 text-sm font-bold flex items-center justify-center shrink-0">2</span>
            <div>
              <p class="font-semibold text-slate-800">สร้างบอท</p>
              <p>ไปที่เมนู <strong>Bots > สร้างบอทใหม่</strong> ตั้งชื่อ, กำหนด System Prompt, เลือก AI Model และสีธีม</p>
            </div>
          </div>
          <div class="flex gap-4 items-start">
            <span class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 text-sm font-bold flex items-center justify-center shrink-0">3</span>
            <div>
              <p class="font-semibold text-slate-800">เพิ่ม Knowledge Base</p>
              <p>อัปโหลดไฟล์จาก Google Drive (เปิดลิงก์แชร์เป็น "Anyone with the link") ระบบจะแปลงเอกสารเป็นข้อมูลที่บอทใช้ตอบคำถามได้</p>
            </div>
          </div>
          <div class="flex gap-4 items-start">
            <span class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 text-sm font-bold flex items-center justify-center shrink-0">4</span>
            <div>
              <p class="font-semibold text-slate-800">เชื่อมต่อช่องทาง</p>
              <p>ไปที่ <strong>Integration</strong> เชื่อมต่อ LINE OA, Facebook Page หรือคัดลอก Web Widget ไปวางบนเว็บไซต์</p>
            </div>
          </div>
          <div class="flex gap-4 items-start">
            <span class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 text-sm font-bold flex items-center justify-center shrink-0">5</span>
            <div>
              <p class="font-semibold text-slate-800">ทดสอบและเปิดใช้งาน</p>
              <p>ใช้ Live Preview ในหน้า <strong>ตั้งค่าบอท</strong> ทดสอบการสนทนา จากนั้นเปิดสถานะบอทเป็น Active</p>
            </div>
          </div>
        </div>
      </section>

      <!-- ═══ Dashboard ═══ -->
      <section id="sec-dashboard" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">Dashboard</h2>
        </div>
        <div class="text-sm text-slate-600 space-y-3">
          <p>หน้าแรกของบอทที่เลือก แสดงสรุปข้อมูลสำคัญ:</p>
          <div class="grid sm:grid-cols-2 gap-3">
            <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
              <p class="font-semibold text-slate-700">สรุปสถิติวันนี้</p>
              <p class="text-xs mt-1">จำนวนสนทนา, ข้อความ, ค่าใช้จ่าย Token ของวันนี้</p>
            </div>
            <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
              <p class="font-semibold text-slate-700">กราฟแนวโน้ม</p>
              <p class="text-xs mt-1">แสดงจำนวนสนทนาและข้อความย้อนหลัง 7 วัน</p>
            </div>
            <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
              <p class="font-semibold text-slate-700">สนทนาล่าสุด</p>
              <p class="text-xs mt-1">รายการสนทนาที่เกิดขึ้นล่าสุด คลิกเข้าดูรายละเอียดได้</p>
            </div>
            <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
              <p class="font-semibold text-slate-700">คำถามที่ตอบไม่ได้</p>
              <p class="text-xs mt-1">แสดงจำนวนคำถามที่บอทยังไม่มีข้อมูลตอบ รอตรวจสอบ</p>
            </div>
          </div>
        </div>
      </section>

      <!-- ═══ Conversations ═══ -->
      <section id="sec-conversations" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">Conversations (ประวัติสนทนา)</h2>
        </div>
        <div class="text-sm text-slate-600 space-y-3">
          <p>ดูประวัติสนทนาทั้งหมดของบอท พร้อมรายละเอียดแต่ละข้อความ</p>
          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100 space-y-2">
            <p class="font-semibold text-slate-700">คุณสมบัติ:</p>
            <ul class="list-disc list-inside space-y-1 text-xs">
              <li><strong>ค้นหา</strong> — ค้นหาสนทนาด้วยคำค้น ชื่อผู้ใช้ หรือ Session ID</li>
              <li><strong>กรอง</strong> — กรองตาม Platform (LINE, Web, Facebook) และ Tags</li>
              <li><strong>Tags</strong> — ดูสถิติแท็กและกรองสนทนาตามแท็กที่ต้องการ</li>
              <li><strong>ดูรายละเอียด</strong> — คลิกที่สนทนาเพื่อดูข้อความทั้งหมดแบบ Chat View</li>
              <li><strong>จัดการแท็ก</strong> — เพิ่ม/ลบแท็กของแต่ละสนทนาในหน้ารายละเอียด</li>
              <li><strong>Feedback</strong> — ดูผลตอบรับ (Like/Dislike) ที่ผู้ใช้ให้กับแต่ละข้อความ</li>
            </ul>
          </div>
        </div>
      </section>

      <!-- ═══ Knowledge Base ═══ -->
      <section id="sec-knowledge" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">Knowledge Base</h2>
        </div>
        <div class="text-sm text-slate-600 space-y-3">
          <p>จัดการแหล่งข้อมูลที่บอทใช้ในการตอบคำถาม ระบบใช้เทคนิค RAG (Retrieval-Augmented Generation) ค้นหาข้อมูลจากเอกสารและนำมาประกอบการตอบ</p>

          <div class="bg-emerald-50 rounded-xl p-4 border border-emerald-100">
            <p class="font-semibold text-emerald-800 mb-2">วิธีเพิ่มเอกสาร:</p>
            <ol class="list-decimal list-inside space-y-1 text-xs text-emerald-700">
              <li>อัปโหลดไฟล์ไปยัง Google Drive</li>
              <li>คลิกขวา > แชร์ > เปลี่ยนเป็น "Anyone with the link"</li>
              <li>คัดลอกลิงก์แชร์</li>
              <li>กลับมาที่ Knowledge Base > คลิก <strong>"เพิ่มไฟล์ (Google Drive)"</strong></li>
              <li>วางลิงก์และยืนยัน — ระบบจะดาวน์โหลด, ตัดเนื้อหาเป็น Chunks และสร้าง Embedding อัตโนมัติ</li>
            </ol>
          </div>

          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100 space-y-2">
            <p class="font-semibold text-slate-700">ไฟล์ที่รองรับ:</p>
            <div class="flex flex-wrap gap-2">
              <span class="px-2.5 py-1 rounded-lg bg-blue-50 text-blue-700 text-xs font-medium">Google Docs</span>
              <span class="px-2.5 py-1 rounded-lg bg-green-50 text-green-700 text-xs font-medium">Google Sheets</span>
              <span class="px-2.5 py-1 rounded-lg bg-red-50 text-red-700 text-xs font-medium">PDF</span>
              <span class="px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-medium">Word (.docx)</span>
              <span class="px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 text-xs font-medium">Excel (.xlsx)</span>
              <span class="px-2.5 py-1 rounded-lg bg-orange-50 text-orange-700 text-xs font-medium">PowerPoint (.pptx)</span>
              <span class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 text-xs font-medium">Plain Text (.txt)</span>
            </div>
          </div>

          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100 space-y-2">
            <p class="font-semibold text-slate-700">การจัดการ:</p>
            <ul class="list-disc list-inside space-y-1 text-xs">
              <li><strong>เปิด/ปิด</strong> — Toggle เพื่อเปิดหรือปิดการใช้งานแหล่งข้อมูลแต่ละรายการ</li>
              <li><strong>Re-Embed</strong> — สร้าง Embedding ใหม่เมื่อเอกสารต้นทางมีการเปลี่ยนแปลง</li>
              <li><strong>ลบ</strong> — ลบแหล่งข้อมูลและ Chunks ทั้งหมด</li>
              <li><strong>Embed ทั้งหมด (Pending)</strong> — สร้าง Embedding สำหรับไฟล์ที่ยังไม่ได้ Embed</li>
            </ul>
          </div>
        </div>
      </section>

      <!-- ═══ Unanswered ═══ -->
      <section id="sec-unanswered" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-red-50 text-red-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">Unanswered (คำถามที่ตอบไม่ได้)</h2>
        </div>
        <div class="text-sm text-slate-600 space-y-3">
          <p>รวบรวมคำถามที่บอทไม่สามารถหาคำตอบจาก Knowledge Base ได้ เพื่อให้ Admin ตรวจสอบและเพิ่มข้อมูล</p>
          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100 space-y-2">
            <p class="font-semibold text-slate-700">การดำเนินการ:</p>
            <ul class="list-disc list-inside space-y-1 text-xs">
              <li><strong>เพิ่มคำตอบ</strong> — เขียนคำตอบที่ถูกต้อง ระบบจะบันทึกลง Knowledge Base โดยอัตโนมัติ (สร้างเป็น Manual Q&A)</li>
              <li><strong>อ่านแล้ว</strong> — ทำเครื่องหมายว่าตรวจสอบแล้ว (กรณีคำถามไม่จำเป็นต้องเพิ่มข้อมูล)</li>
              <li><strong>อ่านแล้วทั้งหมด</strong> — ทำเครื่องหมายว่าตรวจสอบแล้วทุกรายการ</li>
            </ul>
          </div>
          <div class="bg-amber-50 rounded-xl p-3 border border-amber-100">
            <p class="text-xs text-amber-800"><strong>แนะนำ:</strong> ตรวจสอบหน้านี้เป็นประจำเพื่อปรับปรุง Knowledge Base ให้ครอบคลุมคำถามที่ผู้ใช้ถามบ่อย</p>
          </div>
        </div>
      </section>

      <!-- ═══ Handoff ═══ -->
      <section id="sec-handoff" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">Handoff (ส่งต่อเจ้าหน้าที่)</h2>
        </div>
        <div class="text-sm text-slate-600 space-y-3">
          <p>เมื่อผู้ใช้ต้องการพูดคุยกับเจ้าหน้าที่จริง บอทจะสร้าง Handoff Request ให้ Admin ดำเนินการ</p>
          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100 space-y-2">
            <p class="font-semibold text-slate-700">สถานะ Handoff:</p>
            <div class="space-y-2">
              <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded-lg bg-amber-100 text-amber-700 text-xs font-bold">Pending</span>
                <span class="text-xs">— รอเจ้าหน้าที่รับเรื่อง</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded-lg bg-blue-100 text-blue-700 text-xs font-bold">In Progress</span>
                <span class="text-xs">— กำลังดำเนินการ</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded-lg bg-emerald-100 text-emerald-700 text-xs font-bold">Resolved</span>
                <span class="text-xs">— แก้ไขเสร็จแล้ว</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded-lg bg-slate-100 text-slate-600 text-xs font-bold">Closed</span>
                <span class="text-xs">— ปิดเรื่อง</span>
              </div>
            </div>
          </div>
          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100 space-y-2">
            <p class="font-semibold text-slate-700">การทำงาน:</p>
            <ul class="list-disc list-inside space-y-1 text-xs">
              <li>คลิกที่ Handoff Request เพื่อดูรายละเอียดสนทนาและข้อมูลที่ผู้ใช้กรอก</li>
              <li>เปลี่ยนสถานะตามขั้นตอนการดำเนินงาน</li>
              <li>เพิ่มบันทึกภายใน (Internal Notes) สำหรับทีม</li>
              <li>ระบบจะส่งอีเมลแจ้งเตือนเมื่อมี Handoff ใหม่ (ถ้าเปิดใช้งาน Email Settings)</li>
            </ul>
          </div>
        </div>
      </section>

      <!-- ═══ Analytics ═══ -->
      <section id="sec-analytics" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-pink-50 text-pink-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">Analytics & Token Usage</h2>
        </div>
        <div class="text-sm text-slate-600 space-y-3">
          <div class="grid sm:grid-cols-2 gap-3">
            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
              <p class="font-semibold text-slate-700 mb-1">Analytics</p>
              <ul class="list-disc list-inside space-y-1 text-xs">
                <li>กราฟจำนวนสนทนาแยกตามช่วงเวลา</li>
                <li>สัดส่วนช่องทาง (LINE / Web / Facebook)</li>
                <li>จำนวนข้อความเฉลี่ยต่อสนทนา</li>
                <li>อัตราการตอบสำเร็จ vs Fallback</li>
              </ul>
            </div>
            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
              <p class="font-semibold text-slate-700 mb-1">Token Usage</p>
              <ul class="list-disc list-inside space-y-1 text-xs">
                <li>สรุปการใช้ Token แยกตาม Model</li>
                <li>ค่าใช้จ่ายประมาณการ (USD)</li>
                <li>กราฟแนวโน้มการใช้งานรายวัน</li>
                <li>เปรียบเทียบ Prompt Tokens vs Completion Tokens</li>
              </ul>
            </div>
          </div>
        </div>
      </section>

      <!-- ═══ Feedback ═══ -->
      <section id="sec-feedback" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-cyan-50 text-cyan-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14zM4 22h0a1 1 0 01-1-1v-9a1 1 0 011-1h0"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">Feedback</h2>
        </div>
        <div class="text-sm text-slate-600 space-y-3">
          <p>ดูผลตอบรับจากผู้ใช้ที่กด Like / Dislike ให้กับคำตอบของบอท</p>
          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100 space-y-2">
            <ul class="list-disc list-inside space-y-1 text-xs">
              <li>ข้อความที่ได้ <span class="text-red-500 font-semibold">Dislike</span> ควรนำไปปรับปรุง Knowledge Base หรือ System Prompt</li>
              <li>สามารถกรองดูเฉพาะ Dislike เพื่อหาจุดที่ต้องปรับปรุง</li>
              <li>Feedback ช่วยวัดคุณภาพการตอบของบอทในมุมมองผู้ใช้จริง</li>
            </ul>
          </div>
        </div>
      </section>

      <!-- ═══ Integration ═══ -->
      <section id="sec-integration" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-green-50 text-green-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">Integration (เชื่อมต่อช่องทาง)</h2>
        </div>
        <div class="text-sm text-slate-600 space-y-3">
          <div class="space-y-3">
            <div class="bg-green-50 rounded-xl p-4 border border-green-100">
              <div class="flex items-center gap-2 mb-2">
                <span class="w-6 h-6 rounded bg-green-500 flex items-center justify-center">
                  <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="currentColor"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>
                </span>
                <p class="font-semibold text-green-800">LINE OA</p>
              </div>
              <ul class="list-disc list-inside space-y-1 text-xs text-green-700">
                <li>กรอก Channel Access Token และ Channel Secret จาก LINE Developers Console</li>
                <li>คัดลอก Webhook URL ไปตั้งค่าที่ LINE OA > Messaging API > Webhook</li>
                <li>เปิดใช้ "Use Webhook" และปิด "Auto-reply messages"</li>
              </ul>
            </div>

            <div class="bg-blue-50 rounded-xl p-4 border border-blue-100">
              <div class="flex items-center gap-2 mb-2">
                <span class="w-6 h-6 rounded bg-blue-600 flex items-center justify-center">
                  <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                </span>
                <p class="font-semibold text-blue-800">Facebook Messenger</p>
              </div>
              <ul class="list-disc list-inside space-y-1 text-xs text-blue-700">
                <li>กรอก Page Access Token และ Verify Token จาก Facebook Developer App</li>
                <li>คัดลอก Callback URL ไปตั้งค่าที่ Facebook App > Webhooks</li>
                <li>เลือก Subscribe fields: messages, messaging_postbacks</li>
              </ul>
            </div>

            <div class="bg-indigo-50 rounded-xl p-4 border border-indigo-100">
              <div class="flex items-center gap-2 mb-2">
                <span class="w-6 h-6 rounded bg-indigo-600 flex items-center justify-center">
                  <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                </span>
                <p class="font-semibold text-indigo-800">Web Chat Widget</p>
              </div>
              <ul class="list-disc list-inside space-y-1 text-xs text-indigo-700">
                <li>คัดลอกโค้ด Script Tag จากหน้า Integration</li>
                <li>วางโค้ดก่อน <code class="bg-indigo-100 px-1 rounded">&lt;/body&gt;</code> ของเว็บไซต์ที่ต้องการ</li>
                <li>Widget จะแสดงปุ่มแชทมุมล่างขวาของเว็บไซต์</li>
                <li>ปรับแต่งสีและข้อความได้ในหน้าตั้งค่าบอท</li>
              </ul>
            </div>
          </div>
        </div>
      </section>

      <!-- ═══ Bot Settings ═══ -->
      <section id="sec-bot-settings" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">ตั้งค่าบอท</h2>
        </div>
        <div class="text-sm text-slate-600 space-y-3">
          <p>กำหนดค่าต่าง ๆ ของบอทแต่ละตัว:</p>
          <div class="grid sm:grid-cols-2 gap-3">
            <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
              <p class="font-semibold text-slate-700 text-xs">ข้อมูลพื้นฐาน</p>
              <p class="text-xs mt-1">ชื่อบอท, Slug, คำอธิบาย, Avatar, สีธีม</p>
            </div>
            <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
              <p class="font-semibold text-slate-700 text-xs">System Prompt</p>
              <p class="text-xs mt-1">กำหนดบุคลิก บทบาท และขอบเขตการตอบของ AI</p>
            </div>
            <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
              <p class="font-semibold text-slate-700 text-xs">AI Model</p>
              <p class="text-xs mt-1">เลือก Model, ปรับ Temperature, Max Tokens, Top-P</p>
            </div>
            <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
              <p class="font-semibold text-slate-700 text-xs">Handoff Form</p>
              <p class="text-xs mt-1">กำหนดฟอร์มส่งต่อเจ้าหน้าที่ เปิด/ปิด ฟิลด์ต่าง ๆ</p>
            </div>
            <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
              <p class="font-semibold text-slate-700 text-xs">สมาชิก</p>
              <p class="text-xs mt-1">จัดการผู้ใช้ที่มีสิทธิ์เข้าถึงบอทนี้</p>
            </div>
            <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
              <p class="font-semibold text-slate-700 text-xs">Live Preview</p>
              <p class="text-xs mt-1">ทดสอบสนทนากับบอทแบบ Real-time</p>
            </div>
          </div>

          <div class="bg-blue-50 rounded-xl p-3 border border-blue-100">
            <p class="text-xs text-blue-800"><strong>เคล็ดลับ System Prompt:</strong> เขียนให้ชัดเจนว่าบอทเป็นใคร ตอบเรื่องอะไร ไม่ตอบเรื่องอะไร ใช้ภาษาอะไร และมีน้ำเสียงแบบไหน จะช่วยให้บอทตอบได้ตรงจุดมากขึ้น</p>
          </div>
        </div>
      </section>

      <!-- ═══ Admin Section ═══ -->
      <?php if (Auth::can('manage_bots') || Auth::can('manage_users') || Auth::can('manage_settings')): ?>
      <section id="sec-admin" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-red-50 text-red-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">การจัดการระบบ (Admin)</h2>
        </div>
        <div class="text-sm text-slate-600 space-y-4">

          <?php if (Auth::can('manage_bots')): ?>
          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
            <p class="font-semibold text-slate-700 mb-2">Bots — จัดการบอท</p>
            <ul class="list-disc list-inside space-y-1 text-xs">
              <li>สร้าง, แก้ไข, ลบบอท</li>
              <li>เปิด/ปิดการใช้งานบอท</li>
              <li>ดูภาพรวมบอททั้งหมดในระบบ</li>
            </ul>
          </div>
          <?php endif; ?>

          <?php if (Auth::can('manage_models')): ?>
          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
            <p class="font-semibold text-slate-700 mb-2">AI Models — จัดการโมเดล AI</p>
            <ul class="list-disc list-inside space-y-1 text-xs">
              <li>ดูรายการโมเดลที่ Sync จาก OpenRouter</li>
              <li>เปิด/ปิดโมเดลที่อนุญาตใช้งานในระบบ</li>
              <li>ดูราคา Token ของแต่ละโมเดล</li>
              <li>Sync โมเดลใหม่จาก OpenRouter API</li>
            </ul>
          </div>
          <?php endif; ?>

          <?php if (Auth::can('manage_users')): ?>
          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
            <p class="font-semibold text-slate-700 mb-2">User Management — จัดการผู้ใช้</p>
            <ul class="list-disc list-inside space-y-1 text-xs">
              <li>เพิ่ม, แก้ไข, ลบผู้ใช้งาน</li>
              <li>กำหนด Role: Super Admin, Admin, Viewer</li>
              <li>เปิด/ปิดการใช้งานผู้ใช้</li>
              <li>รีเซ็ตรหัสผ่านสำหรับบัญชี Local</li>
            </ul>
            <div class="mt-3 space-y-1">
              <p class="text-xs font-semibold text-slate-600">สิทธิ์ตาม Role:</p>
              <div class="overflow-x-auto">
                <table class="w-full text-xs border-collapse">
                  <thead>
                    <tr class="bg-slate-100">
                      <th class="text-left p-2 rounded-tl-lg">ความสามารถ</th>
                      <th class="text-center p-2">Super Admin</th>
                      <th class="text-center p-2">Admin</th>
                      <th class="text-center p-2 rounded-tr-lg">Viewer</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr><td class="p-2">ดู Dashboard / สนทนา</td><td class="text-center p-2 text-emerald-600">&#10003;</td><td class="text-center p-2 text-emerald-600">&#10003;</td><td class="text-center p-2 text-emerald-600">&#10003;</td></tr>
                    <tr><td class="p-2">จัดการ Knowledge Base</td><td class="text-center p-2 text-emerald-600">&#10003;</td><td class="text-center p-2 text-emerald-600">&#10003;</td><td class="text-center p-2 text-red-400">&#10007;</td></tr>
                    <tr><td class="p-2">จัดการบอท / Integration</td><td class="text-center p-2 text-emerald-600">&#10003;</td><td class="text-center p-2 text-emerald-600">&#10003;</td><td class="text-center p-2 text-red-400">&#10007;</td></tr>
                    <tr><td class="p-2">จัดการผู้ใช้</td><td class="text-center p-2 text-emerald-600">&#10003;</td><td class="text-center p-2 text-red-400">&#10007;</td><td class="text-center p-2 text-red-400">&#10007;</td></tr>
                    <tr><td class="p-2">ตั้งค่าระบบ / Email</td><td class="text-center p-2 text-emerald-600">&#10003;</td><td class="text-center p-2 text-red-400">&#10007;</td><td class="text-center p-2 text-red-400">&#10007;</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <?php if (Auth::can('manage_settings')): ?>
          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
            <p class="font-semibold text-slate-700 mb-2">Email Settings — ตั้งค่าอีเมล</p>
            <ul class="list-disc list-inside space-y-1 text-xs">
              <li>กำหนดค่า SMTP Server สำหรับส่งอีเมลแจ้งเตือน Handoff</li>
              <li>ปรับแต่ง Template อีเมลแจ้งเตือน</li>
              <li>ทดสอบส่งอีเมล</li>
            </ul>
          </div>

          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
            <p class="font-semibold text-slate-700 mb-2">Settings — ตั้งค่าระบบ</p>
            <ul class="list-disc list-inside space-y-1 text-xs">
              <li>ตั้งชื่อระบบ (App Name)</li>
              <li>กำหนด OpenRouter API Key</li>
              <li>กำหนด Google Drive API Key</li>
              <li>ตั้งค่า Embedding Model</li>
            </ul>
          </div>
          <?php endif; ?>

        </div>
      </section>
      <?php endif; ?>

      <!-- ═══ Tips ═══ -->
      <section id="sec-tips" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-yellow-50 text-yellow-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">เคล็ดลับและแนวปฏิบัติที่ดี</h2>
        </div>
        <div class="text-sm text-slate-600 space-y-3">
          <div class="grid sm:grid-cols-2 gap-3">
            <div class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-xl p-4 border border-emerald-100">
              <p class="font-semibold text-emerald-800 text-xs mb-2">Knowledge Base</p>
              <ul class="list-disc list-inside space-y-1 text-xs text-emerald-700">
                <li>ใช้ภาษาที่ชัดเจนในเอกสาร หลีกเลี่ยงตัวย่อ</li>
                <li>แบ่งเนื้อหาเป็นหัวข้อย่อย จะช่วยให้ RAG ค้นหาข้อมูลได้แม่นยำขึ้น</li>
                <li>อัปเดตเอกสารและ Re-Embed เมื่อข้อมูลเปลี่ยนแปลง</li>
                <li>ตรวจสอบ Unanswered Questions สม่ำเสมอ</li>
              </ul>
            </div>
            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl p-4 border border-blue-100">
              <p class="font-semibold text-blue-800 text-xs mb-2">System Prompt</p>
              <ul class="list-disc list-inside space-y-1 text-xs text-blue-700">
                <li>กำหนดบทบาทที่ชัดเจน เช่น "คุณคือผู้ช่วยตอบคำถามของ..."</li>
                <li>ระบุขอบเขต: ตอบเรื่องอะไร ไม่ตอบเรื่องอะไร</li>
                <li>กำหนดน้ำเสียง: สุภาพ เป็นทางการ หรือเป็นกันเอง</li>
                <li>เพิ่มคำสั่ง Fallback เช่น "ถ้าไม่มีข้อมูล ให้แนะนำติดต่อ..."</li>
              </ul>
            </div>
            <div class="bg-gradient-to-br from-amber-50 to-orange-50 rounded-xl p-4 border border-amber-100">
              <p class="font-semibold text-amber-800 text-xs mb-2">ประสิทธิภาพ</p>
              <ul class="list-disc list-inside space-y-1 text-xs text-amber-700">
                <li>เลือก Model ที่เหมาะกับงาน — Model เล็กเร็วกว่าและค่าใช้จ่ายต่ำกว่า</li>
                <li>ปรับ Temperature ต่ำ (0.1-0.3) สำหรับข้อมูลที่ต้องแม่นยำ</li>
                <li>ใช้ Max Tokens ที่เหมาะสม ไม่มากเกินไป</li>
                <li>ติดตาม Token Usage เพื่อควบคุมค่าใช้จ่าย</li>
              </ul>
            </div>
            <div class="bg-gradient-to-br from-pink-50 to-rose-50 rounded-xl p-4 border border-pink-100">
              <p class="font-semibold text-pink-800 text-xs mb-2">การดูแลระบบ</p>
              <ul class="list-disc list-inside space-y-1 text-xs text-pink-700">
                <li>ตรวจสอบ Handoff Requests เป็นประจำ</li>
                <li>ดู Feedback เพื่อปรับปรุงคุณภาพ</li>
                <li>ใช้ Analytics วิเคราะห์พฤติกรรมผู้ใช้</li>
                <li>สำรอง API Key และตั้งค่าสำคัญ</li>
              </ul>
            </div>
          </div>
        </div>
      </section>

      <!-- ═══ FAQ ═══ -->
      <section id="sec-faq" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </div>
          <h2 class="text-lg font-bold text-slate-800">คำถามที่พบบ่อย (FAQ)</h2>
        </div>
        <div class="space-y-2">
          <div x-data="{ open: false }" class="border border-slate-100 rounded-xl overflow-hidden">
            <button @click="open = !open" class="w-full flex items-center justify-between p-4 text-left hover:bg-slate-50 transition-colors">
              <span class="text-sm font-semibold text-slate-700">บอทตอบไม่ตรงคำถาม ทำยังไง?</span>
              <svg class="w-4 h-4 text-slate-400 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open" x-collapse class="px-4 pb-4">
              <div class="text-sm text-slate-600 space-y-1">
                <p>1. ตรวจสอบ Knowledge Base ว่ามีข้อมูลที่เกี่ยวข้องหรือไม่</p>
                <p>2. ปรับปรุง System Prompt ให้ชัดเจนขึ้น</p>
                <p>3. ใช้หน้า Unanswered เพิ่มคำตอบที่ถูกต้อง</p>
                <p>4. ลอง Re-Embed เอกสารที่เกี่ยวข้อง</p>
              </div>
            </div>
          </div>

          <div x-data="{ open: false }" class="border border-slate-100 rounded-xl overflow-hidden">
            <button @click="open = !open" class="w-full flex items-center justify-between p-4 text-left hover:bg-slate-50 transition-colors">
              <span class="text-sm font-semibold text-slate-700">เพิ่มไฟล์จาก Google Drive แล้ว Error?</span>
              <svg class="w-4 h-4 text-slate-400 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open" x-collapse class="px-4 pb-4">
              <div class="text-sm text-slate-600 space-y-1">
                <p>1. ตรวจสอบว่าเปิดแชร์ลิงก์เป็น "Anyone with the link" แล้ว</p>
                <p>2. ตรวจสอบว่า Google Drive API Key ถูกต้อง (Settings)</p>
                <p>3. ไฟล์อาจมีขนาดใหญ่เกินไป ลองแบ่งเป็นไฟล์ย่อย</p>
                <p>4. รองรับเฉพาะ Google Docs, Sheets, PDF, Word, Excel, PowerPoint, Text</p>
              </div>
            </div>
          </div>

          <div x-data="{ open: false }" class="border border-slate-100 rounded-xl overflow-hidden">
            <button @click="open = !open" class="w-full flex items-center justify-between p-4 text-left hover:bg-slate-50 transition-colors">
              <span class="text-sm font-semibold text-slate-700">ค่าใช้จ่าย Token คิดยังไง?</span>
              <svg class="w-4 h-4 text-slate-400 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open" x-collapse class="px-4 pb-4">
              <div class="text-sm text-slate-600 space-y-1">
                <p>ระบบใช้ AI ผ่าน OpenRouter API ค่าใช้จ่ายคำนวณจาก:</p>
                <p>1. <strong>Prompt Tokens</strong> — จำนวน Token ที่ส่งไป (คำถาม + context)</p>
                <p>2. <strong>Completion Tokens</strong> — จำนวน Token ที่ AI ตอบกลับ</p>
                <p>3. ราคาแตกต่างตามแต่ละ Model — ดูได้ที่หน้า AI Models</p>
                <p>ติดตามการใช้งานได้ที่หน้า Token Usage</p>
              </div>
            </div>
          </div>

          <div x-data="{ open: false }" class="border border-slate-100 rounded-xl overflow-hidden">
            <button @click="open = !open" class="w-full flex items-center justify-between p-4 text-left hover:bg-slate-50 transition-colors">
              <span class="text-sm font-semibold text-slate-700">LINE Webhook ไม่ทำงาน?</span>
              <svg class="w-4 h-4 text-slate-400 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open" x-collapse class="px-4 pb-4">
              <div class="text-sm text-slate-600 space-y-1">
                <p>1. ตรวจสอบว่า Webhook URL ถูกต้อง (คัดลอกจากหน้า Integration)</p>
                <p>2. ตรวจสอบว่าเปิด "Use Webhook" ใน LINE OA แล้ว</p>
                <p>3. ปิด "Auto-reply messages" และ "Greeting messages" ใน LINE OA</p>
                <p>4. ตรวจสอบ Channel Access Token และ Channel Secret</p>
                <p>5. กด "Verify" ที่ LINE Developers Console เพื่อทดสอบ</p>
              </div>
            </div>
          </div>

          <div x-data="{ open: false }" class="border border-slate-100 rounded-xl overflow-hidden">
            <button @click="open = !open" class="w-full flex items-center justify-between p-4 text-left hover:bg-slate-50 transition-colors">
              <span class="text-sm font-semibold text-slate-700">จะปรับปรุงคุณภาพบอทให้ดีขึ้นได้อย่างไร?</span>
              <svg class="w-4 h-4 text-slate-400 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open" x-collapse class="px-4 pb-4">
              <div class="text-sm text-slate-600 space-y-1">
                <p>1. <strong>เพิ่ม Knowledge Base</strong> — ยิ่งมีข้อมูลมาก บอทตอบได้ดีขึ้น</p>
                <p>2. <strong>ตรวจ Unanswered</strong> — เพิ่มคำตอบสำหรับคำถามที่ตอบไม่ได้</p>
                <p>3. <strong>ดู Feedback</strong> — แก้ไขคำตอบที่ได้ Dislike</p>
                <p>4. <strong>ปรับ System Prompt</strong> — เพิ่มคำแนะนำให้ AI</p>
                <p>5. <strong>เลือก Model ที่เหมาะสม</strong> — Model ใหม่มักตอบได้ดีกว่า</p>
              </div>
            </div>
          </div>
        </div>
      </section>

    </div>
  </div>
</div>

<script>
function guideApp() {
  const sectionIds = [
    'sec-overview', 'sec-start', 'sec-dashboard', 'sec-conversations',
    'sec-knowledge', 'sec-unanswered', 'sec-handoff', 'sec-analytics',
    'sec-feedback', 'sec-integration', 'sec-bot-settings',
    <?php if (Auth::can('manage_bots') || Auth::can('manage_users') || Auth::can('manage_settings')): ?>
    'sec-admin',
    <?php endif; ?>
    'sec-tips', 'sec-faq'
  ];

  const labels = {
    'sec-overview': 'ภาพรวมระบบ',
    'sec-start': 'เริ่มต้นใช้งาน',
    'sec-dashboard': 'Dashboard',
    'sec-conversations': 'Conversations',
    'sec-knowledge': 'Knowledge Base',
    'sec-unanswered': 'Unanswered',
    'sec-handoff': 'Handoff',
    'sec-analytics': 'Analytics',
    'sec-feedback': 'Feedback',
    'sec-integration': 'Integration',
    'sec-bot-settings': 'ตั้งค่าบอท',
    'sec-admin': 'จัดการระบบ',
    'sec-tips': 'เคล็ดลับ',
    'sec-faq': 'FAQ'
  };

  return {
    activeSection: 'sec-overview',
    sections: sectionIds.map(id => ({ id, label: labels[id] })),

    init() {
      const container = document.querySelector('.flex-1.overflow-y-auto');
      if (!container) return;
      const observer = new IntersectionObserver((entries) => {
        for (const e of entries) {
          if (e.isIntersecting) {
            this.activeSection = e.target.id;
            break;
          }
        }
      }, { root: container, rootMargin: '-20% 0px -60% 0px', threshold: 0 });

      this.$nextTick(() => {
        sectionIds.forEach(id => {
          const el = document.getElementById(id);
          if (el) observer.observe(el);
        });
      });
    },

    scrollToSection(id) {
      const el = document.getElementById(id);
      if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        this.activeSection = id;
      }
    }
  };
}
</script>

<?php require __DIR__ . '/layouts/footer.php'; ?>
