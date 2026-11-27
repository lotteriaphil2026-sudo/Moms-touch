'use strict';

(function () {
  const dailyKey = userId => `momsTouchInstallLastShown:${userId}`;
  const optOutKey = userId => `momsTouchInstallDoNotShow:${userId}`;
  const shownToday = new Set();
  let backdrop;
  let dialog;
  let lastFocused;
  let previousOverflow = '';
  let previousAppInert = false;
  let activeUserId;

  function mount() {
    if (backdrop) return;

    document.body.insertAdjacentHTML('beforeend', `
      <div id="mtInstallBackdrop" class="mt-install-backdrop" aria-hidden="true" inert>
        <section class="mt-install-dialog" role="dialog" aria-modal="true" aria-labelledby="mtInstallTitle" tabindex="-1">
          <div class="mt-install-top">
            <button type="button" class="mt-install-close" id="mtInstallClose" aria-label="Close installation instructions">×</button>
            <div class="mt-install-logo">MOM’S <span>TOUCH</span></div>
            <h2 id="mtInstallTitle">Take Mom’s Touch<br>with you.</h2>
            <p>Add our Members Club to your home screen for quick, app-like access. No app store needed.</p>
          </div>
          <div class="mt-install-content">
            <div class="mt-install-tabs" role="tablist" aria-label="Choose your device">
              <button type="button" class="mt-install-tab" role="tab" id="mtIosTab" aria-selected="true" aria-controls="mtIosPanel">iPhone / iPad</button>
              <button type="button" class="mt-install-tab" role="tab" id="mtAndroidTab" aria-selected="false" aria-controls="mtAndroidPanel">Android</button>
            </div>
            <div id="mtIosPanel" role="tabpanel" aria-labelledby="mtIosTab">
              <ol class="mt-install-steps">
                <li><span class="mt-install-num">1</span><span class="mt-install-step-text">Open <strong>momstouchph.com</strong> in Safari or Chrome.</span><span class="mt-install-icon" aria-hidden="true"><svg viewBox="0 0 48 48" fill="none"><rect x="8" y="10" width="32" height="28" rx="6" stroke="#4f4a42" stroke-width="2.6"/><rect x="12" y="14" width="24" height="18" rx="3" stroke="#9c9588" stroke-width="2"/><circle cx="18" cy="21" r="1.8" fill="#9c9588"/><path d="M22 26h10" stroke="#9c9588" stroke-width="2.2" stroke-linecap="round"/><path d="M16 36h16" stroke="#4f4a42" stroke-width="2.6" stroke-linecap="round"/></svg></span></li>
                <li><span class="mt-install-num">2</span><span class="mt-install-step-text">Tap the <strong>Share</strong> icon <span class="mt-install-glyph" aria-label="share">□↑</span> in your browser.</span><span class="mt-install-icon" aria-hidden="true"><svg viewBox="0 0 48 48" fill="none"><rect x="12" y="16" width="24" height="20" rx="4" stroke="#4f4a42" stroke-width="2.6"/><path d="M24 28V10" stroke="#4f4a42" stroke-width="2.6" stroke-linecap="round"/><path d="M18.5 15.5L24 10l5.5 5.5" stroke="#4f4a42" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span></li>
                <li><span class="mt-install-num">3</span><span class="mt-install-step-text">Choose <strong>Add to Home Screen</strong> <span class="mt-install-mini" aria-hidden="true">+</span> (you may need to scroll).</span><span class="mt-install-icon" aria-hidden="true"><svg viewBox="0 0 48 48" fill="none"><rect x="10" y="10" width="28" height="28" rx="7" stroke="#4f4a42" stroke-width="2.6"/><path d="M24 17v14M17 24h14" stroke="#4f4a42" stroke-width="2.6" stroke-linecap="round"/><path d="M17 34h14" stroke="#9c9588" stroke-width="2" stroke-linecap="round"/></svg></span></li>
                <li><span class="mt-install-num">4</span><span class="mt-install-step-text">Tap <strong>Add</strong> to put Mom’s Touch on your home screen.</span><span class="mt-install-icon" aria-hidden="true"><svg viewBox="0 0 48 48" fill="none"><rect x="8" y="14" width="32" height="20" rx="10" stroke="#4f4a42" stroke-width="2.6"/><path d="M18 24l4 4 8-8" stroke="#4f4a42" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span></li>
              </ol>
            </div>
            <div id="mtAndroidPanel" role="tabpanel" aria-labelledby="mtAndroidTab" hidden>
              <ol class="mt-install-steps">
                <li><span class="mt-install-num">1</span><span class="mt-install-step-text">Open <strong>momstouchph.com</strong> in Chrome.</span><span class="mt-install-icon" aria-hidden="true"><svg viewBox="0 0 48 48" fill="none"><rect x="8" y="10" width="32" height="28" rx="6" stroke="#4f4a42" stroke-width="2.6"/><rect x="12" y="14" width="24" height="18" rx="3" stroke="#9c9588" stroke-width="2"/><circle cx="18" cy="21" r="1.8" fill="#9c9588"/><path d="M22 26h10" stroke="#9c9588" stroke-width="2.2" stroke-linecap="round"/><path d="M16 36h16" stroke="#4f4a42" stroke-width="2.6" stroke-linecap="round"/></svg></span></li>
                <li><span class="mt-install-num">2</span><span class="mt-install-step-text">Tap the <strong>three-dot menu ⋮</strong> near the browser address bar.</span><span class="mt-install-icon" aria-hidden="true"><svg viewBox="0 0 48 48" fill="none"><rect x="12" y="10" width="24" height="28" rx="6" stroke="#4f4a42" stroke-width="2.6"/><circle cx="24" cy="17" r="2.2" fill="#4f4a42"/><circle cx="24" cy="24" r="2.2" fill="#4f4a42"/><circle cx="24" cy="31" r="2.2" fill="#4f4a42"/></svg></span></li>
                <li><span class="mt-install-num">3</span><span class="mt-install-step-text">Choose <strong>Add to Home screen</strong> or <strong>Install app</strong>.</span><span class="mt-install-icon" aria-hidden="true"><svg viewBox="0 0 48 48" fill="none"><rect x="14" y="9" width="20" height="30" rx="5" stroke="#4f4a42" stroke-width="2.6"/><path d="M24 18v12M18 24h12" stroke="#4f4a42" stroke-width="2.6" stroke-linecap="round"/><path d="M21 35h6" stroke="#9c9588" stroke-width="2" stroke-linecap="round"/></svg></span></li>
                <li><span class="mt-install-num">4</span><span class="mt-install-step-text">Tap <strong>Add</strong> or <strong>Install</strong> to confirm.</span><span class="mt-install-icon" aria-hidden="true"><svg viewBox="0 0 48 48" fill="none"><rect x="8" y="14" width="32" height="20" rx="10" stroke="#4f4a42" stroke-width="2.6"/><path d="M18 24l4 4 8-8" stroke="#4f4a42" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span></li>
              </ol>
            </div>
            <p class="mt-install-note">Tip: This creates a home-screen shortcut. Full-screen app behavior depends on the website’s PWA setup.</p>
            <button type="button" id="mtInstallDone" class="mt-install-primary">Got it, thanks!</button>
            <label class="mt-install-checkbox"><input id="mtInstallNever" type="checkbox"> Don’t show this again</label>
          </div>
        </section>
      </div>
    `);

    backdrop = document.getElementById('mtInstallBackdrop');
    dialog = backdrop.querySelector('.mt-install-dialog');
    document.getElementById('mtIosTab').addEventListener('click', () => switchTab('ios'));
    document.getElementById('mtAndroidTab').addEventListener('click', () => switchTab('android'));
    document.getElementById('mtInstallClose').addEventListener('click', close);
    document.getElementById('mtInstallDone').addEventListener('click', close);
    backdrop.addEventListener('click', event => {
      if (event.target === backdrop) close();
    });
    document.addEventListener('keydown', handleKeydown);
  }

  function switchTab(platform) {
    const ios = platform === 'ios';
    document.getElementById('mtIosTab').setAttribute('aria-selected', String(ios));
    document.getElementById('mtAndroidTab').setAttribute('aria-selected', String(!ios));
    document.getElementById('mtIosPanel').hidden = !ios;
    document.getElementById('mtAndroidPanel').hidden = ios;
  }

  function open(userId) {
    mount();
    if (backdrop.classList.contains('is-open')) return;

    lastFocused = document.activeElement;
    activeUserId = userId;
    previousOverflow = document.body.style.overflow;
    const app = document.getElementById('app');
    previousAppInert = app?.inert ?? false;
    if (app) app.inert = true;
    backdrop.inert = false;
    backdrop.setAttribute('aria-hidden', 'false');
    backdrop.classList.add('is-open');
    document.getElementById('mtInstallNever').checked = false;
    document.body.style.overflow = 'hidden';
    switchTab(/android/i.test(navigator.userAgent) ? 'android' : 'ios');
    dialog.focus();
  }

  function close() {
    if (!backdrop?.classList.contains('is-open')) return;
    const never = document.getElementById('mtInstallNever');
    if (never.checked && activeUserId) {
      try {
        localStorage.setItem(optOutKey(activeUserId), 'true');
      } catch {}
    }

    backdrop.classList.remove('is-open');
    backdrop.setAttribute('aria-hidden', 'true');
    backdrop.inert = true;
    document.body.style.overflow = previousOverflow;
    const app = document.getElementById('app');
    if (app) app.inert = previousAppInert;
    if (lastFocused?.isConnected) lastFocused.focus();
    activeUserId = null;
  }

  function handleKeydown(event) {
    if (!backdrop?.classList.contains('is-open')) return;
    if (event.key === 'Escape') {
      close();
      return;
    }
    if (event.key !== 'Tab') return;

    const focusable = [...dialog.querySelectorAll('button:not(:disabled), input:not(:disabled)')]
      .filter(element => element.getClientRects().length);
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || document.activeElement === dialog)) {
      event.preventDefault();
      first.focus();
    }
  }

  function localDay() {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  }

  function showForUser(userId) {
    if (userId === null || userId === undefined) return false;
    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) return false;
    const id = String(userId);
    const day = localDay();
    const key = `${id}:${day}`;
    if (shownToday.has(key)) return false;

    try {
      if (localStorage.getItem(optOutKey(id)) === 'true' || localStorage.getItem(dailyKey(id)) === day) return false;
    } catch {}

    shownToday.add(key);
    try {
      localStorage.setItem(dailyKey(id), day);
    } catch {}
    window.setTimeout(() => open(id), 350);
    return true;
  }

  window.MomsTouchInstall = { close, showForUser };
})();
