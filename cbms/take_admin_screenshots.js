const puppeteer = require('puppeteer-core');
const path = require('path');
const fs = require('fs');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const USER_DATA_DIR = path.join(__dirname, 'temp_chrome_profile');
const ADMIN_BASE_URL = 'https://appupili.up.ac.th/cbms/admin/';
const ASSETS_DIR = path.join(__dirname, 'public', 'assets', 'manual');

async function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

// Helper to inject highlight circles in the DOM
async function highlightElement(page, selector, label = '', shape = 'box') {
  await page.evaluate((sel, lbl, shp) => {
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
    if (!el) return;

    const rect = el.getBoundingClientRect();
    const highlight = document.createElement('div');
    highlight.className = 'manual-highlight temp-highlight';

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
      const topOffset = window.pageYOffset + rect.top - 45;
      const leftOffset = window.pageXOffset + rect.left + (rect.width / 2) - 45;
      labelEl.style.top = Math.max(0, topOffset) + 'px';
      labelEl.style.left = Math.max(0, leftOffset) + 'px';
      document.body.appendChild(labelEl);
    }
  }, selector, label, shape);
}

async function clearHighlights(page) {
  await page.evaluate(() => {
    const highlights = document.querySelectorAll('.temp-highlight');
    highlights.forEach(el => el.remove());
  });
}

(async () => {
  console.log('Launching Chrome with cloned session profile...');
  
  if (!fs.existsSync(ASSETS_DIR)) {
    fs.mkdirSync(ASSETS_DIR, { recursive: true });
  }

  const browser = await puppeteer.launch({
    executablePath: CHROME_PATH,
    headless: false,
    userDataDir: USER_DATA_DIR,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1400,900'],
    defaultViewport: {
      width: 1400,
      height: 900
    }
  });

  const page = await browser.newPage();
  await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

  try {
    // -------------------------------------------------------------
    // Check session status and wait for login
    // -------------------------------------------------------------
    console.log('Navigating to Admin Dashboard...');
    await page.goto(ADMIN_BASE_URL + 'index.php', { waitUntil: 'networkidle2' });
    await sleep(2000);

    let currentUrl = page.url();
    console.log('Current Page URL:', currentUrl);

    // Wait until the URL contains "index.php" and doesn't contain "login"
    if (!currentUrl.includes('index.php') || currentUrl.includes('login.php') || currentUrl.includes('microsoftonline')) {
      console.log('-------------------------------------------------------------------');
      console.log('ACTION REQUIRED: Please log in to the system in the Chrome window that just opened.');
      console.log('The script will wait up to 5 minutes for you to log in.');
      console.log('-------------------------------------------------------------------');
      
      // Wait until URL contains index.php and doesn't contain login.php or microsoftonline
      await page.waitForFunction(() => window.location.href.includes('index.php') && !window.location.href.includes('login.php') && !window.location.href.includes('microsoftonline'), { timeout: 300000 });
      console.log('Login detected! Resuming screenshot capture...');
      await sleep(5000); // Allow dashboard to load completely
    } else {
      console.log('Session is already valid! Starting to capture all admin menus...');
    }

    const menus = [
      { name: '1_dashboard', file: 'index.php', label: 'หน้า Dashboard รายงานสถิติภาพรวม' },
      { name: '2_conversations', file: 'conversations.php', label: 'หน้ารายการบทสนทนา (Conversations)' },
      { name: '3_analytics', file: 'analytics.php', label: 'หน้าวิเคราะห์ข้อมูลเชิงลึก (Analytics)' },
      { name: '4_token_usage', file: 'token-usage.php', label: 'หน้าสถิติการใช้งาน Token และค่าใช้จ่าย' },
      { name: '5_reports', file: 'reports.php', label: 'หน้ารายงานผลข้อมูลรวม (Reports)' },
      { name: '6_knowledge', file: 'knowledge.php', label: 'หน้าจัดการฐานข้อมูลความรู้ (Knowledge Base)' },
      { name: '7_feedback', file: 'feedback.php', label: 'หน้าดูข้อมูลป้อนกลับจากผู้ใช้ (Feedback)' },
      { name: '8_missed', file: 'missed.php', label: 'หน้าจัดการคำถามบอทตอบไม่ได้ (Unanswered)' },
      { name: '9_handoff', file: 'handoff.php', label: 'หน้าจัดการรายการขอพบเจ้าหน้าที่ (Handoff)' },
      { name: '10_integration', file: 'integration.php', label: 'หน้าติดตั้งและเชื่อมต่อระบบ (Integration)' },
      { name: '11_bots', file: 'bots.php', label: 'หน้าจัดการบอททั้งหมด (Bots Management)' },
      { name: '12_models', file: 'models.php', label: 'หน้าตั้งค่าโมเดล AI (AI Models)' },
      { name: '13_users', file: 'users.php', label: 'หน้าจัดการผู้ใช้ระบบ (User Management)' },
      { name: '14_email_settings', file: 'email-settings.php', label: 'หน้าตั้งค่าระบบอีเมล (Email Settings)' },
      { name: '15_settings', file: 'settings.php', label: 'หน้าตั้งค่าระบบทั่วไป (Settings)' }
    ];

    for (const menu of menus) {
      console.log(`Navigating to ${menu.label} (${menu.file})...`);
      
      // Navigate to the menu
      await page.goto(ADMIN_BASE_URL + menu.file, { waitUntil: 'networkidle2' }).catch(err => {
        console.error(`Failed to navigate to ${menu.file}:`, err);
      });
      await sleep(1500); // Wait for animations, dynamic charts or tables to load

      // Highlight the active sidebar menu link or page title to draw attention
      await highlightElement(page, 'aside a[href*="' + menu.file + '"]', menu.label, 'box');

      const screenshotPath = path.join(ASSETS_DIR, `admin_${menu.name}.png`);
      await page.screenshot({ path: screenshotPath });
      console.log(`Saved screenshot: ${screenshotPath}`);

      await clearHighlights(page);
    }

    console.log('All admin screenshots taken successfully!');

  } catch (error) {
    console.error('An error occurred during admin automation:', error);
  } finally {
    await browser.close();
  }
})();
