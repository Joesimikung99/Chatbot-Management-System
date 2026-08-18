<?php
/**
 * Admin Layout — HTML Head + Page Open
 * AI Chatbot System — CBMS  (Multi-Bot Sidebar)
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

use App\Helpers\Auth;
use App\Helpers\Database;

Auth::requireLogin();

$appUrl    = rtrim($_ENV['APP_URL'] ?? 'https://appupili.up.ac.th/cbms', '/');
$adminUrl  = $appUrl . '/admin';
$user      = Auth::user();
$pageTitle = $pageTitle ?? 'Admin';

$userBots  = Auth::userBots();
$activeBot = Auth::activeBot();
$activeBotId = Auth::activeBotId();

// Determine current page for sidebar active state
$currentPage = basename($_SERVER['SCRIPT_FILENAME'], '.php');

// ── Per-bot stats (counts for badges) ─────────────────────────────────
$db = Database::getInstance();

// App name: from system_settings (user-editable) → .env → fallback
$appName = Database::appName();
$botStats = [];
foreach ($userBots as $b) {
    $bid = (int)$b['id'];
    $_badgeCount = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM messages m
         JOIN conversations c ON c.id = m.conversation_id
         WHERE m.is_fallback = 1 AND m.is_reviewed = 0 AND m.role = 'assistant' AND c.bot_id = ?",
        [$bid]
    );
    $_handoffCount = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM handoff_requests WHERE bot_id = ? AND status = 'pending'",
        [$bid]
    );
    $botStats[$bid] = ['unanswered' => $_badgeCount, 'handoff' => $_handoffCount];
}

// Per-bot nav items
$botNavItems = [
    ['page' => 'index',         'href' => 'index.php',         'label' => 'Dashboard',       'perm' => 'view_dashboard',
     'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
    ['page' => 'conversations', 'href' => 'conversations.php', 'label' => 'Conversations',   'perm' => 'view_conversations',
     'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
    ['page' => 'analytics',     'href' => 'analytics.php',     'label' => 'Analytics',       'perm' => 'view_analytics',
     'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
    ['page' => 'token-usage',   'href' => 'token-usage.php',   'label' => 'Token Usage',     'perm' => 'view_token_usage',
     'icon' => 'M13 10V3L4 14h7v7l9-11h-7z'],
    ['page' => 'reports',       'href' => 'reports.php',       'label' => 'Reports',         'perm' => 'view_analytics',
     'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
    ['page' => 'knowledge',     'href' => 'knowledge.php',     'label' => 'Knowledge Base',  'perm' => 'manage_knowledge',
     'icon' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253'],
    ['page' => 'wiki',          'href' => 'wiki.php',          'label' => 'Wiki Knowledge',  'perm' => 'manage_knowledge',
     'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
    ['page' => 'feedback',      'href' => 'feedback.php',      'label' => 'Feedback',        'perm' => 'view_conversations',
     'icon' => 'M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14zM4 22h0a1 1 0 01-1-1v-9a1 1 0 011-1h0'],
    ['page' => 'missed',         'href' => 'missed.php',        'label' => 'Unanswered',      'perm' => 'view_conversations',
     'icon' => 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    ['page' => 'handoff',        'href' => 'handoff.php',       'label' => 'Handoff',         'perm' => 'view_conversations',
     'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
    ['page' => 'integration',   'href' => 'integration.php',   'label' => 'Integration',     'perm' => 'manage_settings',
     'icon' => 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1'],
];

// Global admin items
$adminItems = [
    ['page' => 'bots',        'href' => 'bots.php',        'label' => 'Bots',            'perm' => 'manage_bots',
     'icon' => 'M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714a2.25 2.25 0 00.659 1.591L19 14.5M14.25 3.104c.251.023.501.05.75.082M19 14.5l-2.47 2.47a2.25 2.25 0 01-1.59.659H9.06a2.25 2.25 0 01-1.591-.659L5 14.5m14 0V5a2 2 0 00-2-2H7a2 2 0 00-2 2v9.5'],
    ['page' => 'models',      'href' => 'models.php',      'label' => 'AI Models',       'perm' => 'manage_models',
     'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
    ['page' => 'users',       'href' => 'users.php',       'label' => 'User Management', 'perm' => 'manage_users',
     'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
    ['page' => 'email-settings', 'href' => 'email-settings.php', 'label' => 'Email Settings', 'perm' => 'manage_settings',
     'icon' => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
    ['page' => 'settings',    'href' => 'settings.php',    'label' => 'Settings',        'perm' => 'manage_settings',
     'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
    ['page' => 'guide',       'href' => 'guide.php',       'label' => 'User Guide',      'perm' => 'view_dashboard',
     'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
    ['page' => 'user-manual', 'href' => $appUrl . '/user_manual.html', 'label' => 'User Manual', 'perm' => 'view_dashboard', 'target' => '_blank',
     'icon' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253'],
    ['page' => 'admin-manual', 'href' => $appUrl . '/admin_manual.html', 'label' => 'Admin Manual', 'perm' => 'manage_settings', 'target' => '_blank',
     'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
];
?>
<!DOCTYPE html>
<html lang="th" class="h-full" x-data="{ sidebarOpen: window.innerWidth >= 1024 }" @resize.window="sidebarOpen = window.innerWidth >= 1024">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken()) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars($appName) ?></title>
  <link rel="icon" type="image/x-icon" href="<?= $appUrl ?>/assets/favicon.ico">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- TailwindCSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Sarabun', 'Inter', 'sans-serif'] },
          colors: {
            brand: {
              50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe',
              300: '#a5b4fc', 400: '#818cf8', 500: '#6366f1',
              600: '#4f46e5', 700: '#4338ca', 800: '#3730a3', 900: '#312e81'
            }
          }
        }
      }
    }
  </script>

  <!-- Alpine.js + Collapse plugin -->
  <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

  <!-- ApexCharts -->
  <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.46.0/dist/apexcharts.min.js"></script>

  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

  <style>
    body { font-family: 'Sarabun', 'Inter', sans-serif; }

    /* Sidebar transition */
    .sidebar-transition { transition: transform .25s cubic-bezier(0.4,0,0.2,1); }
    .main-transition { transition: margin-left .25s cubic-bezier(0.4,0,0.2,1); }

    /* Scrollbar */
    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

    /* Active nav item */
    .nav-item-active {
      background: linear-gradient(135deg, #4f46e5, #6366f1);
      color: #fff !important;
      box-shadow: 0 4px 12px rgba(79,70,229,.35);
    }
    .nav-item-active svg { color: #fff !important; }

    /* Nav hover */
    .nav-item:not(.nav-item-active):hover {
      background: #f1f5f9;
      color: #1e293b;
    }

    /* Bot section */
    .bot-section { border-left: 2px solid transparent; transition: border-color .2s; }
    .bot-section.active-bot { border-left-color: #6366f1; }
    .bot-nav-item {
      transition: all .15s;
      font-size: 13px;
    }
    .bot-nav-item:not(.nav-item-active):hover {
      background: #f1f5f9;
      color: #1e293b;
    }

    /* Topbar shadow */
    .topbar-shadow { box-shadow: 0 1px 0 0 #e2e8f0; }

    /* Card hover */
    .card-hover { transition: box-shadow .2s, transform .2s; }
    .card-hover:hover { box-shadow: 0 8px 24px rgba(0,0,0,.08); transform: translateY(-1px); }

    /* Badge styles */
    .badge-super_admin { background: #fee2e2; color: #b91c1c; }
    .badge-admin       { background: #fef3c7; color: #b45309; }
    .badge-viewer      { background: #f1f5f9; color: #475569; }

    /* Page fade in */
    @keyframes pageFadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
    .page-content { animation: pageFadeIn .3s ease-out; }

    /* Tooltip */
    [data-tooltip]:hover::after {
      content: attr(data-tooltip);
      position: absolute; left: 110%; top: 50%; transform: translateY(-50%);
      background: #1e293b; color: #fff; font-size: 11px;
      padding: 3px 8px; border-radius: 6px; white-space: nowrap; z-index: 100;
    }
    [data-tooltip] { position: relative; }

    /* Sidebar section collapse animation */
    .bot-children { overflow: hidden; transition: max-height .25s ease, opacity .2s ease; }
    .bot-children.collapsed { max-height: 0 !important; opacity: 0; }
  </style>
</head>

<body class="h-full bg-slate-50 text-slate-700 overflow-hidden">
<div class="flex h-screen">

  <!-- ════════════════ SIDEBAR ════════════════ -->
  <!-- Mobile overlay -->
  <div class="fixed inset-0 bg-slate-900/50 z-20 lg:hidden backdrop-blur-sm"
       x-show="sidebarOpen" x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
       x-transition:leave="transition ease-in duration-150"
       x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
       @click="sidebarOpen = false">
  </div>

  <!-- Sidebar Panel -->
  <aside class="fixed top-0 left-0 z-30 h-full w-72 bg-white sidebar-transition flex flex-col"
         :class="sidebarOpen ? 'translate-x-0 shadow-xl' : '-translate-x-full'"
         style="border-right: 1px solid #e2e8f0;">

    <!-- Sidebar Header -->
    <div class="flex items-center gap-3 px-5 py-4 border-b border-slate-100 shrink-0">
      <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
           style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round"
                d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
        </svg>
      </div>
      <div class="overflow-hidden">
        <p class="font-bold text-slate-800 text-sm truncate"><?= htmlspecialchars($appName) ?></p>
        <p class="text-xs text-slate-400 truncate">Admin Dashboard</p>
      </div>
      <button @click="sidebarOpen = false" class="ml-auto lg:hidden text-slate-400 hover:text-slate-600">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto py-3 px-3" x-data="sidebarNav()">

      <!-- ── Bots Section Label ── -->
      <div class="px-2 pt-1 pb-2 flex items-center justify-between">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Bots</p>
        <?php if (Auth::can('manage_bots')): ?>
        <a href="bots.php" class="text-[10px] text-brand-500 hover:text-brand-700 font-semibold" title="จัดการบอท">+ จัดการ</a>
        <?php endif; ?>
      </div>

      <?php if (empty($userBots)): ?>
      <div class="px-3 py-4 text-center">
        <p class="text-xs text-slate-400">ยังไม่มีบอท</p>
        <?php if (Auth::can('manage_bots')): ?>
        <a href="bots.php" class="text-xs text-brand-600 hover:underline mt-1 inline-block">สร้างบอทแรก →</a>
        <?php endif; ?>
      </div>
      <?php else: ?>

      <div class="space-y-1">
        <?php foreach ($userBots as $botIdx => $_sidebarBot):
            $bid = (int)$_sidebarBot['id'];
            $isActiveBot = ($activeBotId === $bid);
            $unansweredCount = $botStats[$bid]['unanswered'] ?? 0;
            $botInitial = strtoupper(mb_substr($_sidebarBot['name'], 0, 1));
        ?>
        <div class="bot-section rounded-xl <?= $isActiveBot ? 'active-bot bg-slate-50/80' : '' ?>">
          <!-- Bot Header (clickable to expand/collapse + switch bot) -->
          <button @click="toggleBot(<?= $bid ?>)"
                  class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-left transition-all group
                         <?= $isActiveBot ? 'bg-brand-50/60' : 'hover:bg-slate-50' ?>">
            <!-- Avatar -->
            <?php if (!empty($_sidebarBot['avatar_url'])): ?>
              <img src="<?= htmlspecialchars($_sidebarBot['avatar_url']) ?>" class="w-7 h-7 rounded-lg object-cover shrink-0">
            <?php else: ?>
              <span class="w-7 h-7 rounded-lg flex items-center justify-center text-[11px] font-bold text-white shrink-0"
                    style="background:linear-gradient(135deg,<?= $isActiveBot ? '#4f46e5,#6366f1' : '#64748b,#94a3b8' ?>);">
                <?= $botInitial ?>
              </span>
            <?php endif; ?>

            <!-- Name + status -->
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold truncate <?= $isActiveBot ? 'text-brand-700' : 'text-slate-700' ?>">
                <?= htmlspecialchars($_sidebarBot['name']) ?>
              </p>
              <?php if (!$_sidebarBot['is_active']): ?>
                <span class="text-[10px] text-slate-400">ปิดใช้งาน</span>
              <?php endif; ?>
            </div>

            <!-- Handoff pending badge -->
            <?php $handoffCount = $botStats[$bid]['handoff'] ?? 0; if ($handoffCount > 0): ?>
            <span class="px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[10px] font-bold leading-none" title="Handoff รอดำเนินการ">
              <?= $handoffCount > 99 ? '99+' : $handoffCount ?>
            </span>
            <?php endif; ?>

            <!-- Unanswered badge -->
            <?php if ($unansweredCount > 0): ?>
            <span class="px-1.5 py-0.5 rounded-full bg-red-100 text-red-600 text-[10px] font-bold leading-none">
              <?= $unansweredCount > 99 ? '99+' : $unansweredCount ?>
            </span>
            <?php endif; ?>

            <!-- Chevron -->
            <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform duration-200"
                 :class="openBots.includes(<?= $bid ?>) ? 'rotate-90' : ''"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
          </button>

          <!-- Bot Sub-Nav (collapsible) -->
          <div x-show="openBots.includes(<?= $bid ?>)" x-collapse>
            <div class="pl-4 pr-1 pb-2 pt-1 space-y-0.5">
              <?php foreach ($botNavItems as $item):
                if (!Auth::can($item['perm'])) continue;
                $isItemActive = ($isActiveBot && $currentPage === $item['page']);
              ?>
              <a href="<?= htmlspecialchars($item['href']) ?>"
                 onclick="ensureBotSwitch(event, <?= $bid ?>, '<?= htmlspecialchars($item['href']) ?>')"
                 class="bot-nav-item nav-item flex items-center gap-2.5 px-2.5 py-1.5 rounded-lg font-medium cursor-pointer
                        <?= $isItemActive ? 'nav-item-active' : 'text-slate-500' ?>">
                <svg class="w-4 h-4 shrink-0 <?= $isItemActive ? 'text-white' : 'text-slate-400' ?>"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="<?= $item['icon'] ?>"/>
                </svg>
                <span class="truncate"><?= htmlspecialchars($item['label']) ?></span>
                <?php if ($item['page'] === 'missed' && $unansweredCount > 0 && $isActiveBot): ?>
                <span class="ml-auto px-1.5 py-0.5 rounded-full bg-red-100 text-red-600 text-[10px] font-bold leading-none">
                  <?= $unansweredCount > 99 ? '99+' : $unansweredCount ?>
                </span>
                <?php endif; ?>
                <?php $hCount = $botStats[$bid]['handoff'] ?? 0; if ($item['page'] === 'handoff' && $hCount > 0 && $isActiveBot): ?>
                <span class="ml-auto px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[10px] font-bold leading-none">
                  <?= $hCount > 99 ? '99+' : $hCount ?>
                </span>
                <?php endif; ?>
              </a>
              <?php endforeach; ?>

              <?php if (Auth::can('manage_bots')): ?>
              <a href="bot-edit.php?id=<?= $bid ?>"
                 class="bot-nav-item nav-item flex items-center gap-2.5 px-2.5 py-1.5 rounded-lg font-medium cursor-pointer
                        <?= ($currentPage === 'bot-edit' && ($activeBotId === $bid || (int)($_GET['id'] ?? 0) === $bid)) ? 'nav-item-active' : 'text-slate-400' ?>">
                <svg class="w-4 h-4 shrink-0 <?= ($currentPage === 'bot-edit' && (int)($_GET['id'] ?? 0) === $bid) ? 'text-white' : 'text-slate-300' ?>"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round"
                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span class="truncate">ตั้งค่าบอท</span>
              </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- ── Admin Section ── -->
      <?php $showAdminSection = array_reduce($adminItems, fn($carry, $i) => $carry || Auth::can($i['perm']), false); ?>
      <?php if ($showAdminSection): ?>
      <div class="pt-4 pb-2 px-2">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">ระบบ</p>
      </div>

      <div class="space-y-0.5">
        <?php foreach ($adminItems as $item):
          if (!Auth::can($item['perm'])) continue;
          $isActive = $currentPage === $item['page'];
        ?>
        <a href="<?= htmlspecialchars($item['href']) ?>"
           <?= !empty($item['target']) ? 'target="' . htmlspecialchars($item['target']) . '"' : '' ?>
           class="nav-item flex items-center gap-3 px-3 py-2 rounded-xl text-sm font-medium transition-all duration-150 cursor-pointer
                  <?= $isActive ? 'nav-item-active' : 'text-slate-600' ?>">
          <svg class="w-[18px] h-[18px] shrink-0 <?= $isActive ? 'text-white' : 'text-slate-400' ?>"
               fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="<?= $item['icon'] ?>"/>
          </svg>
          <?= htmlspecialchars($item['label']) ?>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </nav>

    <!-- Sidebar Footer — User Card -->
    <div class="p-3 border-t border-slate-100 shrink-0" x-data="{ open: false }" @click.outside="open = false">
      <div class="flex items-center gap-3 p-2 rounded-xl hover:bg-slate-50 transition-colors cursor-pointer"
           @click="open = !open">
        <div class="relative shrink-0">
          <?php if ($user['avatar_url']): ?>
            <img src="<?= htmlspecialchars($user['avatar_url']) ?>"
                 class="w-9 h-9 rounded-xl object-cover" alt="">
          <?php else: ?>
            <div class="w-9 h-9 rounded-xl flex items-center justify-center text-white text-sm font-bold"
                 style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
              <?= strtoupper(substr($user['display_name'] ?? 'U', 0, 1)) ?>
            </div>
          <?php endif; ?>
          <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white"
                style="background:#10b981;"></span>
        </div>

        <div class="flex-1 overflow-hidden">
          <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($user['display_name'] ?? '') ?></p>
          <span class="inline-block text-[10px] font-medium px-1.5 py-0.5 rounded-md badge-<?= $user['role'] ?>">
            <?= match($user['role']) {
              'super_admin' => 'Super Admin',
              'admin'       => 'Admin',
              default       => 'Viewer'
            } ?>
          </span>
        </div>

        <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
      </div>

      <!-- User dropdown -->
      <div x-show="open" x-transition:enter="transition ease-out duration-150"
           x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
           class="mt-1 bg-white rounded-xl border border-slate-100 shadow-lg overflow-hidden">
        <a href="<?= $adminUrl ?>/logout.php"
           class="flex items-center gap-2 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
          </svg>
          ออกจากระบบ
        </a>
      </div>
    </div>
  </aside>

  <!-- ════════════════ MAIN AREA ════════════════ -->
  <div class="flex-1 flex flex-col min-w-0 main-transition"
       :class="sidebarOpen ? 'lg:ml-72' : 'lg:ml-0'">

    <!-- ─── TOPBAR ─────────────────────────────────────────────────── -->
    <header class="sticky top-0 z-10 bg-white topbar-shadow px-4 sm:px-6 h-14 flex items-center gap-4">
      <!-- Hamburger -->
      <button @click="sidebarOpen = !sidebarOpen"
              class="p-2 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700 transition-colors">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
      </button>

      <!-- Breadcrumb / Page Title -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2">
          <h2 class="text-[15px] font-semibold text-slate-800 truncate"><?= htmlspecialchars($pageTitle) ?></h2>
          <?php if ($activeBot): ?>
          <span class="hidden sm:inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-brand-50 text-brand-600 text-xs font-medium border border-brand-100">
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5"/>
            </svg>
            <?= htmlspecialchars($activeBot['name']) ?>
          </span>
          <?php endif; ?>
        </div>
        <?php if (!empty($breadcrumb)): ?>
        <p class="text-xs text-slate-400 truncate">
          <?php foreach ($breadcrumb as $i => $crumb): ?>
            <?= $i > 0 ? '<span class="mx-1">/</span>' : '' ?>
            <?= htmlspecialchars($crumb) ?>
          <?php endforeach; ?>
        </p>
        <?php endif; ?>
      </div>

      <!-- Right side -->
      <div class="flex items-center gap-3">
        <!-- Notifications Bell -->
        <button class="relative p-2 rounded-lg text-slate-500 hover:bg-slate-100 transition-colors">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
          </svg>
        </button>

        <!-- User Avatar (topbar) -->
        <div class="flex items-center gap-2">
          <?php if ($user['avatar_url']): ?>
            <img src="<?= htmlspecialchars($user['avatar_url']) ?>"
                 class="w-8 h-8 rounded-lg object-cover border border-slate-200" alt="">
          <?php else: ?>
            <div class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-xs font-bold"
                 style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
              <?= strtoupper(substr($user['display_name'] ?? 'U', 0, 1)) ?>
            </div>
          <?php endif; ?>
          <span class="hidden sm:block text-sm font-medium text-slate-700">
            <?= htmlspecialchars($user['display_name'] ?? '') ?>
          </span>
        </div>
      </div>
    </header>

    <!-- ─── PAGE CONTENT WRAPPER ─────────────────────────────────── -->
    <main class="flex-1 overflow-y-auto p-4 sm:p-6 page-content">
