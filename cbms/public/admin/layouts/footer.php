    </main><!-- end page content -->

    <!-- ─── FOOTER ────────────────────────────────────────────────── -->
    <footer class="bg-white border-t border-slate-100 py-5 px-6 text-center shrink-0">
      <div class="max-w-4xl mx-auto">
        <p class="text-sm font-medium text-slate-600">
          AI Chatbot Management System พัฒนาโดย งานเทคโนโลยีสารสนเทศ สถาบันนวัตกรรมการเรียนรู้ มหาวิทยาลัยพะเยา
        </p>
        <p class="text-xs text-slate-400 mt-1.5 flex items-center justify-center gap-1.5">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
          </svg>
          Contact 0 5446 6666 ต่อ 3541
        </p>
      </div>
    </footer>

  </div><!-- end main area -->
</div><!-- end flex wrapper -->

<script>
// ── SweetAlert2 Global Helpers (Noti) ─────────────────────────────────
const Noti = {
  _toast(icon, title) {
    return Swal.fire({ icon, title, toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true, didOpen: (t) => { t.onmouseenter = Swal.stopTimer; t.onmouseleave = Swal.resumeTimer; } });
  },
  success(msg) { return this._toast('success', msg); },
  error(msg)   { return this._toast('error', msg); },
  warning(msg) { return this._toast('warning', msg); },
  info(msg)    { return this._toast('info', msg); },
  confirm(text, title = 'ยืนยัน') {
    return Swal.fire({ title, text, icon: 'warning', showCancelButton: true, confirmButtonColor: '#4f46e5', cancelButtonColor: '#94a3b8', confirmButtonText: 'ยืนยัน', cancelButtonText: 'ยกเลิก', reverseButtons: true }).then(r => r.isConfirmed);
  },
  prompt(text, defaultVal = '', inputType = 'text') {
    return Swal.fire({ title: text, input: inputType, inputValue: defaultVal, showCancelButton: true, confirmButtonColor: '#4f46e5', cancelButtonColor: '#94a3b8', confirmButtonText: 'ตกลง', cancelButtonText: 'ยกเลิก', reverseButtons: true, inputValidator: (v) => { if (!v || !v.trim()) return 'กรุณากรอกข้อมูล'; } }).then(r => r.isConfirmed ? r.value : null);
  }
};

// ── Helper: format number with commas ─────────────────────────────────
function numFmt(n) { return Number(n).toLocaleString('th-TH'); }
function currFmt(n) { return '$' + Number(n).toFixed(4); }
function pctFmt(n, dec=1) { return (n >= 0 ? '+' : '') + Number(n).toFixed(dec) + '%'; }

// ── Sidebar Nav Component ─────────────────────────────────────────────
function sidebarNav() {
  const ACTIVE_BOT = <?= json_encode($activeBotId) ?>;
  const storageKey = 'cbms_sidebar_open_bots';

  // Restore from localStorage, always include active bot
  let saved = [];
  try { saved = JSON.parse(localStorage.getItem(storageKey) || '[]'); } catch(e) {}
  if (ACTIVE_BOT && !saved.includes(ACTIVE_BOT)) saved.push(ACTIVE_BOT);

  return {
    openBots: saved,

    toggleBot(botId) {
      const isOpen = this.openBots.includes(botId);
      if (isOpen) {
        // Collapse this bot
        this.openBots = this.openBots.filter(id => id !== botId);
      } else {
        // Expand this bot (keep others open for easy comparison)
        this.openBots.push(botId);
      }
      this.persist();
    },

    persist() {
      try { localStorage.setItem(storageKey, JSON.stringify(this.openBots)); } catch(e) {}
    }
  };
}

// ── Switch Bot ───────────────────────────────────────────────────────
async function switchBot(botId) {
  try {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const res = await fetch('api/switch-bot.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ bot_id: botId })
    });
    const data = await res.json();
    if (data.success) {
      window.location.reload();
    } else {
      Noti.error(data.message || 'ไม่สามารถเปลี่ยนบอทได้');
    }
  } catch (e) {
    Noti.error('เกิดข้อผิดพลาดในการเปลี่ยนบอท');
  }
}

// ── Ensure correct bot is active before navigating ───────────────────
function ensureBotSwitch(event, botId, href) {
  const ACTIVE_BOT = <?= json_encode($activeBotId) ?>;
  if (botId === ACTIVE_BOT) return; // correct bot, follow link normally
  event.preventDefault();
  // Switch bot first, then navigate to target page
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  fetch('api/switch-bot.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
    body: JSON.stringify({ bot_id: botId })
  }).then(r => r.json()).then(d => {
    if (d.success) {
      window.location.href = href;
    } else {
      Noti.error(d.message || 'ไม่สามารถเปลี่ยนบอทได้');
    }
  }).catch(() => Noti.error('เกิดข้อผิดพลาดในการเปลี่ยนบอท'));
}

// ── CSRF helper for fetch() calls ─────────────────────────────────────
async function apiFetch(url, options = {}) {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  options.headers = Object.assign({ 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, options.headers ?? {});
  const res = await fetch(url, options);
  if (!res.ok) {
    const err = await res.json().catch(() => ({ message: 'Request failed' }));
    throw new Error(err.message ?? 'Request failed');
  }
  return res.json();
}
</script>
</body>
</html>
