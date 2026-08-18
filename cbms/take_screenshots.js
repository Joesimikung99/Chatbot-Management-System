const puppeteer = require('puppeteer-core');
const path = require('path');
const fs = require('fs');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const TARGET_URL = 'https://appupili.up.ac.th/cbms/';
const ASSETS_DIR = path.join(__dirname, 'public', 'assets', 'manual');

async function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

// Helper to inject highlight circle/box on an element in the DOM
async function highlightElement(page, selector, label = '', shape = 'circle') {
  await page.evaluate((sel, lbl, shp) => {
    // Inject styles
    if (!document.getElementById('manual-highlight-style')) {
      const style = document.createElement('style');
      style.id = 'manual-highlight-style';
      style.innerHTML = `
        .manual-highlight {
          position: absolute !important;
          border: 4px solid #ef4444 !important;
          box-shadow: 0 0 16px rgba(239, 68, 68, 0.9) !important;
          pointer-events: none !important;
          z-index: 999999999 !important;
          box-sizing: border-box !important;
        }
        .manual-label {
          position: absolute !important;
          background: #ef4444 !important;
          color: white !important;
          padding: 6px 12px !important;
          border-radius: 8px !important;
          font-family: 'Sarabun', sans-serif !important;
          font-size: 14px !important;
          font-weight: bold !important;
          pointer-events: none !important;
          z-index: 999999999 !important;
          box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4) !important;
          white-space: nowrap !important;
        }
      `;
      document.head.appendChild(style);
    }

    const el = document.querySelector(sel);
    if (!el) {
      console.warn('Element not found:', sel);
      return;
    }

    const rect = el.getBoundingClientRect();
    const highlight = document.createElement('div');
    highlight.className = 'manual-highlight';
    
    // Add custom class to clean up later
    highlight.classList.add('temp-highlight');

    const pad = 6;
    highlight.style.top = (window.pageYOffset + rect.top - pad) + 'px';
    highlight.style.left = (window.pageXOffset + rect.left - pad) + 'px';
    highlight.style.width = (rect.width + pad * 2) + 'px';
    highlight.style.height = (rect.height + pad * 2) + 'px';

    if (shp === 'circle') {
      highlight.style.borderRadius = '50%';
    } else {
      highlight.style.borderRadius = '12px';
    }

    document.body.appendChild(highlight);

    if (lbl) {
      const labelEl = document.createElement('div');
      labelEl.className = 'manual-label temp-highlight';
      labelEl.textContent = lbl;
      
      // Calculate coordinates to position above
      const topOffset = window.pageYOffset + rect.top - 45;
      const leftOffset = window.pageXOffset + rect.left + (rect.width / 2) - 45;
      
      labelEl.style.top = Math.max(0, topOffset) + 'px';
      labelEl.style.left = Math.max(0, leftOffset) + 'px';
      
      document.body.appendChild(labelEl);
    }
  }, selector, label, shape);
}

// Helper to remove any injected highlights
async function clearHighlights(page) {
  await page.evaluate(() => {
    const highlights = document.querySelectorAll('.temp-highlight');
    highlights.forEach(el => el.remove());
  });
}

// Helper to ensure handoff button is visible (mocks it if config is disabled)
async function ensureHandoffButton(page) {
  await page.evaluate(() => {
    if (!document.getElementById('cbms-handoff-trigger')) {
      const btn = document.createElement('button');
      btn.className = 'cbms-handoff-btn';
      btn.id = 'cbms-handoff-trigger';
      btn.innerHTML = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;flex-shrink:0;margin-right:6px;"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> ขอคุยกับเจ้าหน้าที่';
      btn.addEventListener('click', () => {
        document.getElementById('cbms-handoff-form').classList.toggle('show');
      });
      const inputArea = document.getElementById('cbms-input-area');
      if (inputArea) {
        inputArea.parentNode.insertBefore(btn, inputArea);
      }
    }
  });
}

(async () => {
  console.log('Starting screenshot automation using Chrome...');
  
  if (!fs.existsSync(ASSETS_DIR)) {
    fs.mkdirSync(ASSETS_DIR, { recursive: true });
  }

  const browser = await puppeteer.launch({
    executablePath: CHROME_PATH,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1280,850'],
    defaultViewport: {
      width: 1280,
      height: 850
    }
  });

  const page = await browser.newPage();
  
  // Set User Agent for safety
  await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

  try {
    // -------------------------------------------------------------
    // STEP 1: Landing Page & Chat Bubble
    // -------------------------------------------------------------
    console.log('Navigating to landing page...');
    await page.goto(TARGET_URL, { waitUntil: 'networkidle2' });
    await sleep(2000); // Allow landing page to load

    console.log('Injecting Chatbot Widget script...');
    await page.evaluate(() => {
      const script = document.createElement('script');
      script.src = 'https://appupili.up.ac.th/cbms/widget.js';
      script.setAttribute('data-color', '#4f46e5');
      script.setAttribute('data-draggable', 'true');
      document.body.appendChild(script);
    });

    console.log('Waiting for widget bubble to appear...');
    await page.waitForSelector('#cbms-bubble', { timeout: 10000 });
    await sleep(1000); // Allow bubble entry animation to settle

    console.log('Annotating Landing Page...');
    // Highlight Chat widget bubble
    await highlightElement(page, '#cbms-bubble', '1. คลิกไอคอนนี้เพื่อคุยกับ AI', 'circle');
    // Highlight Admin panel button in nav
    await highlightElement(page, 'nav a[href*="admin/login.php"]', 'ระบบผู้ดูแลระบบ (Admin Panel)', 'box');

    const step1Path = path.join(ASSETS_DIR, 'step1_landing.png');
    await page.screenshot({ path: step1Path });
    console.log(`Saved Step 1 screenshot to ${step1Path}`);

    // Clear highlights for the next step
    await clearHighlights(page);

    // -------------------------------------------------------------
    // STEP 2: Chat Widget Opened
    // -------------------------------------------------------------
    console.log('Opening Chat Widget...');
    await page.click('#cbms-bubble');
    await sleep(1500); // Wait for chat window animation and welcome message

    console.log('Ensuring Handoff Button exists...');
    await ensureHandoffButton(page);

    console.log('Annotating Open Chat Window...');
    // Highlight Chat Header
    await highlightElement(page, '#cbms-header', '1. หัวข้อ & สถานะออนไลน์', 'box');
    // Highlight Handoff button
    await highlightElement(page, '#cbms-handoff-trigger', '2. ปุ่มขอติดต่อเจ้าหน้าที่', 'box');
    // Highlight Input Textarea and Send button
    await highlightElement(page, '#cbms-input-area', '3. ช่องพิมพ์ข้อความ & ปุ่มส่ง', 'box');

    const step2Path = path.join(ASSETS_DIR, 'step2_widget_open.png');
    await page.screenshot({ path: step2Path });
    console.log(`Saved Step 2 screenshot to ${step2Path}`);

    await clearHighlights(page);

    // -------------------------------------------------------------
    // STEP 3: Active Conversation & Feedbacks
    // -------------------------------------------------------------
    console.log('Sending message to bot...');
    await page.type('#cbms-input', 'สวัสดีครับ แนะนำมหาวิทยาลัยพะเยาให้หน่อย');
    await page.click('#cbms-send');
    
    console.log('Waiting for bot response...');
    // Wait for response to be loaded and typing indicator to disappear
    // Typing indicator ID is #cbms-typing, we wait until it's not in DOM anymore
    await sleep(1000); // Initial wait
    
    // Wait up to 20 seconds for the typing indicator to disappear
    let attempts = 0;
    while (attempts < 20) {
      const typingExists = await page.evaluate(() => !!document.getElementById('cbms-typing'));
      if (!typingExists) {
        break;
      }
      console.log('Bot is typing...');
      await sleep(1000);
      attempts++;
    }
    await sleep(1500); // Allow final message render to settle

    console.log('Annotating Chat Conversation...');
    // Highlight bot message
    await highlightElement(page, '.cbms-msg.bot:last-child .cbms-bubble-bot', '1. คำตอบจาก AI Chatbot', 'box');
    // Highlight feedback buttons
    await highlightElement(page, '.cbms-fb-wrap:last-child', '2. ปุ่มแสดงความพึงพอใจ', 'box');

    const step3Path = path.join(ASSETS_DIR, 'step3_chat_active.png');
    await page.screenshot({ path: step3Path });
    console.log(`Saved Step 3 screenshot to ${step3Path}`);

    await clearHighlights(page);

    // -------------------------------------------------------------
    // STEP 4: Handoff Form
    // -------------------------------------------------------------
    console.log('Ensuring Handoff Button exists before clicking...');
    await ensureHandoffButton(page);

    console.log('Clicking request agent button...');
    await page.click('#cbms-handoff-trigger');
    await sleep(800); // Wait for form transition

    console.log('Annotating Handoff Form...');
    // Highlight Handoff Form container
    await highlightElement(page, '#cbms-handoff-form', 'กรอกรายละเอียดติดต่อเจ้าหน้าที่', 'box');

    const step4Path = path.join(ASSETS_DIR, 'step4_handoff_form.png');
    await page.screenshot({ path: step4Path });
    console.log(`Saved Step 4 screenshot to ${step4Path}`);

    console.log('All screenshots taken successfully!');

  } catch (error) {
    console.error('An error occurred during automation:', error);
  } finally {
    await browser.close();
  }
})();
