/**
 * CBMS AI Chat Widget
 * ฝังในเว็บไซต์ใดก็ได้ด้วย:
 * <script src="https://appupili.up.ac.th/cbms/widget.js"
 *         data-color="#4f46e5"
 *         data-icon="https://..."
 *         data-logo="https://..."
 *         data-icon-size="60"
 *         data-draggable="true"><\/script>
 */
(function () {
  'use strict';

  const API_URL  = 'https://appupili.up.ac.th/cbms/api/chat.php';
  const FONT_URL = 'https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600&display=swap';

  // ── Read script attributes ────────────────────────────────────────
  const currentScript = document.currentScript || document.querySelector('script[src*="widget.js"]');
  const widgetColor   = (currentScript && currentScript.getAttribute('data-color'))     || '#4f46e5';
  const widgetIcon    = (currentScript && currentScript.getAttribute('data-icon'))      || '';
  const widgetLogo    = (currentScript && currentScript.getAttribute('data-logo'))      || '';
  const widgetSize    = parseInt((currentScript && currentScript.getAttribute('data-icon-size')) || '60', 10);
  const widgetDrag    = (currentScript && currentScript.getAttribute('data-draggable')) === 'true';
  const widgetBot     = (currentScript && currentScript.getAttribute('data-bot'))       || 'default';

  // ── Inject Font ──────────────────────────────────────────────────
  if (!document.querySelector('link[href*="Sarabun"]')) {
    const link  = document.createElement('link');
    link.rel    = 'stylesheet';
    link.href   = FONT_URL;
    document.head.appendChild(link);
  }

  // ── State ────────────────────────────────────────────────────────
  const sessionKey = 'cbms_session_id_' + widgetBot;
  let sessionId = localStorage.getItem(sessionKey);
  if (!sessionId) {
    sessionId = widgetBot + '-' + Math.random().toString(36).substr(2, 9) + '-' + Date.now();
    localStorage.setItem(sessionKey, sessionId);
  }
  let messages  = [];
  let isOpen    = false;
  let isLoading = false;
  let botConfig = null;

  // ── Build icon HTML ──────────────────────────────────────────────
  const iconOpenHtml = widgetIcon
    ? `<img id="cbms-icon-open" src="${widgetIcon}" alt="Chat" style="width:100%;height:100%;object-fit:cover;border-radius:50%;pointer-events:none;" />`
    : `<svg id="cbms-icon-open" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" style="width:28px;height:28px;color:#fff;pointer-events:none;">
         <path stroke-linecap="round" stroke-linejoin="round"
               d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
       </svg>`;

  const avatarHtml = widgetLogo
    ? `<img src="${widgetLogo}" style="width:100%;height:100%;object-fit:cover;" />`
    : `<span style="font-size:20px;">🤖</span>`;

  const bubbleBg = widgetIcon ? 'transparent' : `linear-gradient(135deg,${widgetColor},${widgetColor}dd)`;

  // ── Create Widget ─────────────────────────────────────────────────
  const container = document.createElement('div');
  container.id    = 'cbms-widget';
  container.innerHTML = `
    <style>
      #cbms-widget * { box-sizing: border-box; }
      #cbms-widget {
        position:fixed; bottom:24px; right:24px; z-index:99999;
        font-family:'Sarabun',sans-serif;
      }

      #cbms-bubble {
        width:${widgetSize}px; height:${widgetSize}px; border-radius:50%;
        cursor:${widgetDrag ? 'grab' : 'pointer'};
        background:${bubbleBg};
        border:none; display:flex; align-items:center; justify-content:center;
        box-shadow:0 8px 32px rgba(0,0,0,.25);
        transition:transform .25s ease, box-shadow .25s ease;
        position:relative; overflow:hidden; user-select:none;
      }
      #cbms-bubble:hover { transform:scale(1.08); box-shadow:0 12px 40px rgba(0,0,0,.35); }
      #cbms-bubble:active { ${widgetDrag ? 'cursor:grabbing;' : ''} }
      #cbms-bubble .badge {
        position:absolute; top:-2px; right:-2px; width:18px; height:18px;
        background:#ef4444; border-radius:50%; border:2px solid #fff;
        font-size:10px; font-weight:700; color:#fff;
        display:none; align-items:center; justify-content:center;
      }

      #cbms-window {
        position:fixed;
        width:380px; max-width:calc(100vw - 48px);
        height:560px; max-height:calc(100vh - 120px);
        background:#fff; border-radius:24px;
        box-shadow:0 20px 60px rgba(0,0,0,.18);
        display:flex; flex-direction:column; overflow:hidden;
        transform:scale(0.85);
        opacity:0; pointer-events:none;
        transition:transform .25s cubic-bezier(.34,1.56,.64,1), opacity .2s ease;
        transform-origin: bottom right;
      }
      #cbms-window.open {
        transform:scale(1); opacity:1; pointer-events:all;
      }

      #cbms-header {
        background:linear-gradient(135deg,${widgetColor},${widgetColor}dd);
        padding:16px 20px; color:#fff;
        display:flex; align-items:center; gap:12px;
      }
      .cbms-avatar {
        width:40px; height:40px; border-radius:12px;
        background:rgba(255,255,255,.2);
        display:flex; align-items:center; justify-content:center;
        overflow:hidden;
      }
      #cbms-header h3 { margin:0; font-size:15px; font-weight:700; }
      #cbms-header p  { margin:0; font-size:12px; opacity:.8; }
      #cbms-close-btn {
        margin-left:auto; background:none; border:none; cursor:pointer;
        color:rgba(255,255,255,.7); padding:4px; border-radius:8px;
        display:flex; align-items:center; justify-content:center;
        transition:color .15s;
      }
      #cbms-close-btn:hover { color:#fff; }

      .cbms-status-dot {
        width:8px; height:8px; border-radius:50%; background:#4ade80;
        display:inline-block; margin-right:4px;
        animation:cbms-pulse 2s infinite;
      }
      @keyframes cbms-pulse { 0%,100%{opacity:1} 50%{opacity:.5} }

      #cbms-messages {
        flex:1; overflow-y:auto; padding:16px;
        display:flex; flex-direction:column; gap:12px;
        background:#f8fafc;
      }
      #cbms-messages::-webkit-scrollbar { width:4px; }
      #cbms-messages::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:99px; }

      .cbms-msg { display:flex; gap:8px; animation:cbms-fadein .3s ease; }
      @keyframes cbms-fadein { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }
      .cbms-msg.user { justify-content:flex-end; }

      .cbms-bubble-user {
        background:linear-gradient(135deg,${widgetColor},${widgetColor}dd);
        color:#fff; padding:10px 14px; border-radius:18px 18px 4px 18px;
        font-size:14px; line-height:1.6; max-width:78%; word-break:break-word;
        box-shadow:0 2px 8px ${widgetColor}40;
      }
      .cbms-bubble-bot {
        background:#fff; color:#1e293b; padding:10px 14px;
        border-radius:18px 18px 18px 4px; font-size:14px; line-height:1.6;
        max-width:78%; word-break:break-word;
        border:1px solid #e2e8f0; box-shadow:0 1px 4px rgba(0,0,0,.06);
      }
      .cbms-bubble-bot.fallback { border-color:#fca5a5; background:#fff5f5; }
      .cbms-bubble-bot a { word-break:break-all; }
      .cbms-bubble-bot table { font-size:13px; }
      .cbms-bubble-bot strong { font-weight:600; }

      .cbms-fb-wrap { display:flex; gap:4px; margin-top:4px; }
      .cbms-fb-btn {
        border:none; background:none; padding:3px; border-radius:6px;
        cursor:pointer; color:#cbd5e1; transition:all .15s;
        display:flex; align-items:center; justify-content:center;
      }
      .cbms-fb-btn:hover { color:#64748b; background:#f1f5f9; }
      .cbms-fb-btn.pos { color:#10b981 !important; }
      .cbms-fb-btn.neg { color:#ef4444 !important; }
      .cbms-fb-btn:disabled { opacity:.5; cursor:default; }
      .cbms-fb-btn svg { width:14px; height:14px; }

      .cbms-typing { display:flex; align-items:center; gap:4px; padding:10px 14px; }
      .cbms-typing span {
        width:8px; height:8px; border-radius:50%; background:#94a3b8;
        animation:cbms-bounce .8s infinite;
      }
      .cbms-typing span:nth-child(2) { animation-delay:.15s; }
      .cbms-typing span:nth-child(3) { animation-delay:.3s; }
      @keyframes cbms-bounce { 0%,100%{transform:translateY(0)} 40%{transform:translateY(-6px)} }

      #cbms-input-area {
        padding:14px 16px; border-top:1px solid #f1f5f9;
        background:#fff; display:flex; gap:10px; align-items:flex-end;
      }
      #cbms-input {
        flex:1; resize:none; border:1px solid #e2e8f0; border-radius:14px;
        padding:10px 14px; font-size:14px; font-family:'Sarabun',sans-serif;
        outline:none; max-height:100px; overflow-y:auto; line-height:1.5;
        transition:border-color .2s;
      }
      #cbms-input:focus { border-color:${widgetColor}; box-shadow:0 0 0 3px ${widgetColor}20; }
      #cbms-input::placeholder { color:#94a3b8; }
      #cbms-send {
        width:42px; height:42px; border-radius:12px; border:none; cursor:pointer;
        background:linear-gradient(135deg,${widgetColor},${widgetColor}dd);
        color:#fff; display:flex; align-items:center; justify-content:center;
        flex-shrink:0; transition:transform .15s, box-shadow .15s;
      }
      #cbms-send:hover  { transform:scale(1.05); box-shadow:0 4px 12px ${widgetColor}55; }
      #cbms-send:active { transform:scale(0.97); }
      #cbms-send:disabled { opacity:.5; cursor:not-allowed; transform:none; }

      .cbms-footer { text-align:center; padding:6px 0 2px; font-size:10px; color:#94a3b8; }
      .cbms-footer a { color:#94a3b8; }

      .cbms-quick-replies { display:flex; flex-wrap:wrap; gap:6px; padding:0 16px 12px; }
      .cbms-qr-btn {
        padding:6px 14px; border-radius:16px; border:1px solid ${widgetColor}40;
        background:#fff; color:${widgetColor}; font-size:13px; font-family:'Sarabun',sans-serif;
        cursor:pointer; transition:all .15s; white-space:nowrap;
      }
      .cbms-qr-btn:hover { background:${widgetColor}; color:#fff; border-color:${widgetColor}; }

      .cbms-handoff-btn {
        display:flex; align-items:center; gap:6px; margin:0 16px 10px;
        padding:8px 14px; border-radius:12px; border:1px dashed #f59e0b;
        background:#fffbeb; color:#92400e; font-size:13px; font-family:'Sarabun',sans-serif;
        cursor:pointer; transition:all .15s; width:calc(100% - 32px);
      }
      .cbms-handoff-btn:hover { background:#fef3c7; border-color:#d97706; }
      .cbms-handoff-btn svg { width:16px; height:16px; flex-shrink:0; }

      .cbms-handoff-form { padding:12px 16px; background:#fffbeb; border-top:1px solid #fde68a; display:none; }
      .cbms-handoff-form.show { display:block; }
      .cbms-handoff-form label { display:block; font-size:12px; font-weight:600; color:#92400e; margin-bottom:4px; }
      .cbms-handoff-form select, .cbms-handoff-form input, .cbms-handoff-form textarea {
        width:100%; padding:8px 12px; border:1px solid #fde68a; border-radius:10px;
        font-size:13px; font-family:'Sarabun',sans-serif; outline:none; margin-bottom:8px;
      }
      .cbms-handoff-form select:focus, .cbms-handoff-form input:focus, .cbms-handoff-form textarea:focus {
        border-color:#f59e0b; box-shadow:0 0 0 2px #f59e0b30;
      }
      .cbms-handoff-submit {
        width:100%; padding:8px; border:none; border-radius:10px;
        background:#f59e0b; color:#fff; font-size:13px; font-weight:600;
        font-family:'Sarabun',sans-serif; cursor:pointer; transition:background .15s;
      }
      .cbms-handoff-submit:hover { background:#d97706; }
      .cbms-handoff-submit:disabled { opacity:.5; cursor:not-allowed; }

      @media(max-width:480px) {
        #cbms-window { width:calc(100vw - 16px); height:70vh; border-radius:20px; }
        #cbms-widget { bottom:16px; right:16px; }
      }
    </style>

    <button id="cbms-bubble" aria-label="เปิดแชทบอท">
      ${iconOpenHtml}
      <svg id="cbms-icon-close" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
           style="display:none;position:absolute;width:24px;height:24px;color:#fff;pointer-events:none;">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
      </svg>
      <span class="badge" id="cbms-badge"></span>
    </button>

    <div id="cbms-window" role="dialog" aria-label="AI Chatbot">
      <div id="cbms-header">
        <div class="cbms-avatar">${avatarHtml}</div>
        <div>
          <h3>AI Assistant</h3>
          <p><span class="cbms-status-dot"></span>Online · ตอบทันที</p>
        </div>
        <button id="cbms-close-btn" aria-label="ปิด">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>
      <div id="cbms-messages"></div>
      <div id="cbms-handoff-form" class="cbms-handoff-form">
        <label>ช่องทางที่สะดวกให้ติดต่อกลับ</label>
        <select id="cbms-handoff-type" onchange="(function(s){var p=s.closest('.cbms-handoff-form').querySelector('#cbms-handoff-contact');p.placeholder=s.value==='phone'?'เบอร์โทรศัพท์...':'LINE ID หรือ ชื่อ Facebook...';})(this)">
          <option value="line">LINE</option>
          <option value="facebook">Facebook</option>
          <option value="phone">โทรศัพท์</option>
        </select>
        <label>ข้อมูลติดต่อ</label>
        <input type="text" id="cbms-handoff-contact" placeholder="LINE ID หรือ ชื่อ Facebook...">
        <label>ข้อความเพิ่มเติม (ไม่บังคับ)</label>
        <textarea id="cbms-handoff-msg" rows="2" placeholder="อยากสอบถามเรื่อง..."></textarea>
        <button class="cbms-handoff-submit" id="cbms-handoff-submit">ส่งคำขอติดต่อ</button>
      </div>
      <div id="cbms-input-area">
        <textarea id="cbms-input" placeholder="พิมพ์ข้อความ..." rows="1" aria-label="พิมพ์ข้อความ"></textarea>
        <button id="cbms-send" aria-label="ส่ง">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
          </svg>
        </button>
      </div>
      <p class="cbms-footer">Powered by AI Chatbot · <a href="https://www.up.ac.th" target="_blank">มหาวิทยาลัยพะเยา</a></p>
    </div>
  `;

  document.body.appendChild(container);

  // ── DOM References ────────────────────────────────────────────────
  const bubble    = document.getElementById('cbms-bubble');
  const win       = document.getElementById('cbms-window');
  const msgArea   = document.getElementById('cbms-messages');
  const input     = document.getElementById('cbms-input');
  const sendBtn   = document.getElementById('cbms-send');
  const iconOpen  = document.getElementById('cbms-icon-open');
  const iconClose = document.getElementById('cbms-icon-close');
  const closeBtn  = document.getElementById('cbms-close-btn');

  // ── Drag support ──────────────────────────────────────────────────
  let dragMoved = false;
  if (widgetDrag) {
    let startX, startY, offX, offY;

    function onDragStart(e) {
      const ev = e.touches ? e.touches[0] : e;
      const r = bubble.getBoundingClientRect();
      offX = ev.clientX - r.left;
      offY = ev.clientY - r.top;
      startX = ev.clientX;
      startY = ev.clientY;
      dragMoved = false;
      document.addEventListener('mousemove', onDragMove);
      document.addEventListener('mouseup', onDragEnd);
      document.addEventListener('touchmove', onDragMove, { passive: true });
      document.addEventListener('touchend', onDragEnd);
    }

    function onDragMove(e) {
      const ev = e.touches ? e.touches[0] : e;
      if (Math.abs(ev.clientX - startX) > 4 || Math.abs(ev.clientY - startY) > 4) dragMoved = true;
      if (!dragMoved) return;
      const w = container;
      let x = ev.clientX - offX;
      let y = ev.clientY - offY;
      x = Math.max(0, Math.min(x, window.innerWidth - widgetSize));
      y = Math.max(0, Math.min(y, window.innerHeight - widgetSize));
      w.style.left   = x + 'px';
      w.style.top    = y + 'px';
      w.style.right  = 'auto';
      w.style.bottom = 'auto';
    }

    function onDragEnd() {
      document.removeEventListener('mousemove', onDragMove);
      document.removeEventListener('mouseup', onDragEnd);
      document.removeEventListener('touchmove', onDragMove);
      document.removeEventListener('touchend', onDragEnd);
    }

    bubble.addEventListener('mousedown', onDragStart);
    bubble.addEventListener('touchstart', onDragStart, { passive: true });
  }

  // ── Fetch Bot Config ───────────────────────────────────────────────
  async function fetchConfig() {
    if (botConfig) return botConfig;
    try {
      const res = await fetch(API_URL + '?action=config&bot=' + encodeURIComponent(widgetBot));
      const data = await res.json();
      if (data.success) botConfig = data.data;
    } catch (e) {}
    return botConfig;
  }

  // ── Quick Replies ─────────────────────────────────────────────────
  function showQuickReplies(replies) {
    if (!replies || !replies.length) return;
    const wrap = document.createElement('div');
    wrap.className = 'cbms-quick-replies';
    wrap.id = 'cbms-quick-replies';
    replies.forEach(text => {
      const btn = document.createElement('button');
      btn.className = 'cbms-qr-btn';
      btn.textContent = text;
      btn.addEventListener('click', () => {
        wrap.remove();
        input.value = text;
        sendMessage();
      });
      wrap.appendChild(btn);
    });
    msgArea.appendChild(wrap);
    scrollBottom();
  }

  // ── Handoff ───────────────────────────────────────────────────────
  const handoffForm = document.getElementById('cbms-handoff-form');
  const handoffSubmitBtn = document.getElementById('cbms-handoff-submit');

  function showHandoffButton() {
    if (document.getElementById('cbms-handoff-trigger')) return;
    const btn = document.createElement('button');
    btn.className = 'cbms-handoff-btn';
    btn.id = 'cbms-handoff-trigger';
    btn.innerHTML = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> ขอคุยกับเจ้าหน้าที่';
    btn.addEventListener('click', () => {
      handoffForm.classList.toggle('show');
    });
    const inputArea = document.getElementById('cbms-input-area');
    inputArea.parentNode.insertBefore(btn, inputArea);
  }

  handoffSubmitBtn.addEventListener('click', async () => {
    const contactType = document.getElementById('cbms-handoff-type').value;
    const contactId = document.getElementById('cbms-handoff-contact').value.trim();
    if (!contactId) { alert('กรุณากรอก ID หรือชื่อ'); return; }
    const userMsg = document.getElementById('cbms-handoff-msg').value.trim();

    handoffSubmitBtn.disabled = true;
    handoffSubmitBtn.textContent = 'กำลังส่ง...';
    try {
      const res = await fetch(API_URL + '?action=handoff&bot=' + encodeURIComponent(widgetBot), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: sessionId, contact_type: contactType, contact_id: contactId, message: userMsg })
      });
      const data = await res.json();
      if (data.success) {
        handoffForm.classList.remove('show');
        addBotMessage('ได้รับคำขอเรียบร้อยแล้วครับ เจ้าหน้าที่จะติดต่อกลับโดยเร็ว 🙏', false, null);
        const trigger = document.getElementById('cbms-handoff-trigger');
        if (trigger) trigger.remove();
      } else {
        alert(data.message || 'เกิดข้อผิดพลาด');
      }
    } catch (e) { alert('ไม่สามารถส่งคำขอได้'); }
    handoffSubmitBtn.disabled = false;
    handoffSubmitBtn.textContent = 'ส่งคำขอติดต่อ';
  });

  // ── Welcome Message ───────────────────────────────────────────────
  async function addWelcome() {
    const cfg = await fetchConfig();
    const welcomeMsg = (cfg && cfg.welcome_message) || 'สวัสดีครับ! 😊 ยินดีให้บริการ\nมีคำถามอะไรครับ?';
    addBotMessage(welcomeMsg, false, null);
    if (cfg && cfg.quick_replies && cfg.quick_replies.length) {
      showQuickReplies(cfg.quick_replies);
    }
    if (cfg && cfg.handoff_enabled) {
      showHandoffButton();
    }
  }

  // ── Position window relative to bubble ──────────────────────────
  function positionWindow() {
    const br = bubble.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const pad = 8;
    const gap = 12;
    const ww = Math.min(380, vw - pad * 2);
    const maxH = vh - br.height - gap - pad * 2;
    const wh = Math.max(300, Math.min(560, maxH));

    const iconCY = br.top + br.height / 2;
    const iconRight = br.right;

    let top;
    let origin = '';

    if (iconCY > vh / 2) {
      top = br.top - wh - gap;
      origin = 'bottom right';
    } else {
      top = br.bottom + gap;
      origin = 'top right';
    }

    let left = iconRight - ww;

    if (left < pad) left = pad;
    if (top < pad) top = pad;
    if (top + wh > vh - pad) top = vh - wh - pad;

    win.style.left   = left + 'px';
    win.style.top    = top + 'px';
    win.style.right  = 'auto';
    win.style.bottom = 'auto';
    win.style.width  = ww + 'px';
    win.style.height = wh + 'px';
    win.style.transformOrigin = origin;
  }

  // ── Toggle Chat ───────────────────────────────────────────────────
  function toggleChat() {
    if (widgetDrag && dragMoved) return;
    isOpen = !isOpen;
    if (isOpen) positionWindow();
    win.classList.toggle('open', isOpen);
    iconOpen.style.display  = isOpen ? 'none'  : 'block';
    iconClose.style.display = isOpen ? 'block' : 'none';
    if (isOpen && !widgetIcon) {
      bubble.style.background = `linear-gradient(135deg,${widgetColor},${widgetColor}dd)`;
    } else if (!isOpen && widgetIcon) {
      bubble.style.background = 'transparent';
    }
    if (isOpen) {
      if (messages.length === 0) addWelcome();
      setTimeout(() => input.focus(), 200);
    }
  }
  bubble.addEventListener('click', toggleChat);
  closeBtn.addEventListener('click', () => { isOpen = true; toggleChat(); });

  // ── Add Message Bubble ────────────────────────────────────────────
  function addUserMessage(text) {
    messages.push({ role:'user', content:text });
    const div = document.createElement('div');
    div.className = 'cbms-msg user';
    div.innerHTML = `<div class="cbms-bubble-user">${escHtml(text)}</div>`;
    msgArea.appendChild(div);
    scrollBottom();
  }

  function addBotMessage(text, isFallback, messageId) {
    messages.push({ role:'assistant', content:text, messageId:messageId });
    const wrapper = document.createElement('div');

    const div = document.createElement('div');
    div.className = 'cbms-msg bot';
    div.innerHTML = `<div class="cbms-bubble-bot${isFallback?' fallback':''}">${formatBotMsg(text)}</div>`;
    wrapper.appendChild(div);

    if (messageId) {
      const fbWrap = document.createElement('div');
      fbWrap.className = 'cbms-fb-wrap';
      fbWrap.style.marginLeft = '0';

      const thumbUp = document.createElement('button');
      thumbUp.className = 'cbms-fb-btn';
      thumbUp.title = 'ถูกใจ';
      thumbUp.innerHTML = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z"/></svg>';

      const thumbDown = document.createElement('button');
      thumbDown.className = 'cbms-fb-btn';
      thumbDown.title = 'ไม่ถูกใจ';
      thumbDown.innerHTML = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 15v4a3 3 0 003 3l4-9V2H5.72a2 2 0 00-2 1.7l-1.38 9a2 2 0 002 2.3H10z"/></svg>';

      function doFeedback(type, activeBtn) {
        thumbUp.disabled = true;
        thumbDown.disabled = true;
        activeBtn.classList.add(type === 'positive' ? 'pos' : 'neg');
        fetch(API_URL + '?action=feedback&bot=' + encodeURIComponent(widgetBot), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ message_id: messageId, feedback: type })
        }).catch(() => {});
      }

      thumbUp.addEventListener('click', () => doFeedback('positive', thumbUp));
      thumbDown.addEventListener('click', () => doFeedback('negative', thumbDown));

      fbWrap.appendChild(thumbUp);
      fbWrap.appendChild(thumbDown);
      wrapper.appendChild(fbWrap);
    }

    msgArea.appendChild(wrapper);
    scrollBottom();
  }

  function showTyping() {
    const el = document.createElement('div');
    el.className = 'cbms-msg bot'; el.id = 'cbms-typing';
    el.innerHTML = '<div class="cbms-bubble-bot cbms-typing"><span></span><span></span><span></span></div>';
    msgArea.appendChild(el); scrollBottom(); return el;
  }

  // ── Session continuity ───────────────────────────────────────────
  function setSession(sid) {
    if (sid && sid !== sessionId) {
      sessionId = sid;
      try { localStorage.setItem(sessionKey, sid); } catch (e) {}
    }
  }

  // ── Send Message ──────────────────────────────────────────────────
  async function sendMessage() {
    const text = input.value.trim();
    if (!text || isLoading) return;

    input.value = ''; input.style.height = 'auto';
    isLoading = true; sendBtn.disabled = true;

    addUserMessage(text);
    const typing = showTyping();

    try {
      await streamMessage(text, typing);
    } catch (e) {
      // Fallback to the non-streaming endpoint if streaming fails.
      try {
        const res  = await fetch(API_URL + '?bot=' + encodeURIComponent(widgetBot), {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ message: text, session_id: sessionId }),
        });
        const data = await res.json();
        typing.remove();
        if (data.success) {
          setSession(data.data.session_id);
          addBotMessage(data.data.reply, data.data.is_fallback, data.data.message_id || null);
        } else {
          addBotMessage('ขออภัยครับ เกิดข้อผิดพลาด กรุณาลองใหม่', true, null);
        }
      } catch (e2) {
        typing.remove();
        addBotMessage('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้ในขณะนี้', true, null);
      }
    } finally {
      isLoading = false; sendBtn.disabled = false;
      input.focus();
    }
  }

  // Stream the reply token-by-token via SSE. Throws on failure so the
  // caller can fall back to the regular (buffered) endpoint.
  async function streamMessage(text, typing) {
    const res = await fetch(API_URL + '?action=stream&bot=' + encodeURIComponent(widgetBot), {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text, session_id: sessionId }),
    });
    if (!res.ok || !res.body) throw new Error('stream unavailable');

    const reader = res.body.getReader();
    const dec = new TextDecoder();
    let buf = '', acc = '', tmpDiv = null, bubble = null, meta = null, errored = null;
    let renderQueued = false, finished = false;

    function ensureBubble() {
      if (!tmpDiv) {
        typing.remove();
        tmpDiv = document.createElement('div');
        tmpDiv.className = 'cbms-msg bot';
        tmpDiv.innerHTML = '<div class="cbms-bubble-bot"></div>';
        msgArea.appendChild(tmpDiv);
        bubble = tmpDiv.querySelector('.cbms-bubble-bot');
      }
      return bubble;
    }

    // Re-running the markdown formatter + innerHTML on every token is O(n²)
    // and janks on long answers — coalesce renders to one per animation frame.
    function scheduleRender() {
      if (renderQueued) return;
      renderQueued = true;
      requestAnimationFrame(() => {
        renderQueued = false;
        if (finished) return;
        ensureBubble().innerHTML = formatBotMsg(acc);
        msgArea.scrollTop = msgArea.scrollHeight;
      });
    }

    while (true) {
      const r = await reader.read();
      if (r.done) break;
      buf += dec.decode(r.value, { stream: true });
      let i;
      while ((i = buf.indexOf('\n\n')) !== -1) {
        const evt = buf.slice(0, i); buf = buf.slice(i + 2);
        const line = evt.split('\n').find(l => l.indexOf('data:') === 0);
        if (!line) continue;
        const raw = line.slice(5).trim();
        if (raw === '' || raw === '{}') continue;
        let j; try { j = JSON.parse(raw); } catch (e) { continue; }
        if (j.session_id) setSession(j.session_id);
        if (j.delta != null) { acc += j.delta; scheduleRender(); }
        if (j.error) errored = j.error;
        if (j.done) meta = j;
      }
    }

    finished = true;
    typing.remove();
    if (errored && !acc) throw new Error(errored);   // let sendMessage() fall back

    // Swap the progressive bubble for a finalized message (adds feedback buttons).
    if (tmpDiv) tmpDiv.remove();
    addBotMessage(acc || 'ขออภัยครับ เกิดข้อผิดพลาด', meta ? meta.is_fallback : false, meta ? meta.message_id : null);
  }

  sendBtn.addEventListener('click', sendMessage);
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
  });
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 100) + 'px';
  });

  // ── Helpers ───────────────────────────────────────────────────────
  function scrollBottom() {
    requestAnimationFrame(() => msgArea.scrollTop = msgArea.scrollHeight);
  }
  function escHtml(t) {
    return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /**
   * Format bot message: escape HTML first, then convert markdown-like syntax to safe HTML.
   * Supports: links, bold, headers, bullet points, numbered lists, tables, blockquotes.
   */
  function formatBotMsg(raw) {
    let t = escHtml(raw);

    // Markdown links: [text](url)
    t = t.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
      '<a href="$2" target="_blank" rel="noopener noreferrer" style="color:' + widgetColor + ';text-decoration:underline;">$1</a>');

    // Bare URLs (not already inside an href or anchor text).
    // NOTE: no regex lookbehind here — a lookbehind literal throws at script
    // parse time on Safari/iOS < 16.4 and kills the entire widget.
    t = t.replace(/https?:\/\/[^\s<,)"&*]+/g, function (url, off, str) {
      const before = str.slice(Math.max(0, off - 12), off);
      if (/href=("|&quot;)$|>$/.test(before)) return url;
      return '<a href="' + url + '" target="_blank" rel="noopener noreferrer" style="color:' + widgetColor + ';text-decoration:underline;word-break:break-all;">' + url + '</a>';
    });

    // Bold: **text**
    t = t.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

    // Headers: ### text (must be at start of line)
    t = t.replace(/^### (.+)$/gm, '<strong style="font-size:14px;display:block;margin:8px 0 4px;">$1</strong>');
    t = t.replace(/^## (.+)$/gm, '<strong style="font-size:15px;display:block;margin:8px 0 4px;">$1</strong>');

    // Blockquote: > text
    t = t.replace(/^&gt; (.+)$/gm,
      '<div style="border-left:3px solid #cbd5e1;padding:4px 10px;margin:4px 0;color:#64748b;font-size:13px;">$1</div>');

    // Simple markdown tables
    t = formatTable(t);

    // Bullet points: - text or * text (at start of line, not bold **)
    t = t.replace(/^[\-\*] (.+)$/gm, '<div style="padding-left:12px;">• $1</div>');

    // Numbered list: 1. text
    t = t.replace(/^(\d+)\. (.+)$/gm, '<div style="padding-left:12px;">$1. $2</div>');

    // Indented bullets:   - text or   * text
    t = t.replace(/^  [\-\*] (.+)$/gm, '<div style="padding-left:24px;">◦ $1</div>');

    // Newlines to <br> (for remaining plain text lines)
    t = t.replace(/\n/g, '<br>');

    // Clean up double <br> after block elements
    t = t.replace(/(<\/div>)<br>/g, '$1');
    t = t.replace(/(<\/table>)<br>/g, '$1');
    t = t.replace(/(<\/strong>)<br>/g, '$1');

    return t;
  }

  /** Convert markdown table syntax to HTML table */
  function formatTable(text) {
    const lines = text.split('\n');
    let result = [];
    let inTable = false;
    let tableHtml = '';
    let isHeader = true;

    for (let i = 0; i < lines.length; i++) {
      const line = lines[i].trim();
      // Detect table row: starts and ends with |
      if (/^\|(.+)\|$/.test(line)) {
        // Skip separator row (|---|---|)
        if (/^\|[\s\-:|]+\|$/.test(line)) continue;

        if (!inTable) {
          inTable = true;
          isHeader = true;
          tableHtml = '<table style="border-collapse:collapse;width:100%;margin:6px 0;font-size:13px;">';
        }

        const cells = line.split('|').filter(c => c !== '').map(c => c.trim());
        const tag = isHeader ? 'th' : 'td';
        const style = isHeader
          ? 'style="border:1px solid #e2e8f0;padding:4px 8px;background:#f1f5f9;font-weight:600;text-align:left;"'
          : 'style="border:1px solid #e2e8f0;padding:4px 8px;"';
        tableHtml += '<tr>' + cells.map(c => `<${tag} ${style}>${c}</${tag}>`).join('') + '</tr>';
        isHeader = false;
      } else {
        if (inTable) {
          tableHtml += '</table>';
          result.push(tableHtml);
          inTable = false;
          tableHtml = '';
        }
        result.push(lines[i]);
      }
    }
    if (inTable) {
      tableHtml += '</table>';
      result.push(tableHtml);
    }
    return result.join('\n');
  }
})();
