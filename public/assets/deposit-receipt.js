'use strict';

(function () {
  const checkIcon = '<svg viewBox="0 0 48 48"><path d="M8 25l11 11L40 12"/></svg>';
  const pendingIcon = '<svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="18"/><path d="M24 13v12l8 5"/></svg>';
  const rejectedIcon = '<svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="18"/><path d="M17 17l14 14M31 17L17 31"/></svg>';
  let overlay;
  let paper;
  let activeReceipt;
  let lastFocus;
  let previousOverflow = '';
  let previousAppInert = false;
  let onHelp = null;
  let shareFeedbackTimer = null;

  function mount() {
    if (overlay) return;

    document.body.insertAdjacentHTML('beforeend', `
      <div id="mtReceiptOverlay" class="mt-r-overlay" aria-hidden="true">
        <section class="mt-r-paper" role="dialog" aria-modal="true" aria-labelledby="mtRTitle" tabindex="-1">
          <div class="mt-r-scroll">
            <button type="button" class="mt-r-close" id="mtRClose" aria-label="Close receipt">×</button>
            <div class="mt-r-top">
              <div><div class="mt-r-eyebrow" id="mtRTitle">Add funds request</div><div class="mt-r-amount" id="mtRAmount">₱0.00</div></div>
              <span class="mt-r-mark" id="mtRMark" aria-hidden="true"></span>
            </div>
            <div class="mt-r-brand"><img src="/assets/brand.webp" alt="Mom’s Touch" width="245" height="32"></div>
            <div class="mt-r-row"><span class="mt-r-label">To</span><span class="mt-r-value"><strong id="mtRToName">Mom’s Touch Members Club</strong><br><span class="mt-r-sub" id="mtRToSub">Add Funds</span></span></div>
            <div class="mt-r-row"><span class="mt-r-label">From</span><span class="mt-r-value"><strong id="mtRUserName">—</strong><br><span class="mt-r-sub" id="mtRMethod">Bank transfer</span></span></div>
            <div class="mt-r-row"><span class="mt-r-label">Fee</span><span class="mt-r-value" id="mtRFee">₱0.00</span></div>
            <div class="mt-r-row"><span class="mt-r-label">Total</span><strong class="mt-r-value mt-r-primary-value" id="mtRTotal">₱0.00</strong></div>
            <div class="mt-r-row"><span class="mt-r-label">Status</span><strong class="mt-r-value mt-r-primary-value" id="mtRStatus">Pending verification</strong></div>
            <div class="mt-r-row"><span class="mt-r-label">Reference No.</span><span class="mt-r-value" id="mtRReference">—</span></div>
            <div class="mt-r-row"><span class="mt-r-label">Date</span><span class="mt-r-value" id="mtRDate">—</span></div>
            <div class="mt-r-note" id="mtRNote">This receipt confirms that your add funds request was submitted, not that money has been received.</div>
            <button class="mt-r-help" id="mtRHelp" type="button"><svg viewBox="0 0 24 24"><path d="M4 13v-2a8 8 0 0 1 16 0v2"/><rect x="2.5" y="12" width="4" height="7" rx="1.5"/><rect x="17.5" y="12" width="4" height="7" rx="1.5"/><path d="M20 19a5 5 0 0 1-5 3h-3"/></svg><span>Need help?</span><span class="mt-r-chevron">›</span></button>
          </div>
          <div class="mt-r-footer">
            <button type="button" id="mtRShare" class="mt-r-share"><svg viewBox="0 0 24 24"><path d="M12 16V3m0 0L7 8m5-5 5 5M4 15v5h16v-5"/></svg><span aria-live="polite">Share receipt</span></button>
            <button type="button" id="mtRPrint" class="mt-r-secondary">Print / Save as PDF</button>
          </div>
        </section>
      </div>
    `);

    overlay = document.getElementById('mtReceiptOverlay');
    paper = overlay.querySelector('.mt-r-paper');
    document.getElementById('mtRClose').addEventListener('click', close);
    document.getElementById('mtRPrint').addEventListener('click', () => window.print());
    document.getElementById('mtRShare').addEventListener('click', share);
    document.getElementById('mtRHelp').addEventListener('click', () => onHelp?.(activeReceipt?.type));
    overlay.addEventListener('click', event => {
      if (event.target === overlay) close();
    });
    document.addEventListener('keydown', handleKeydown);
  }

  function formatMoney(value) {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value);
  }

  function formatDate(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return `${new Intl.DateTimeFormat('en-PH', {
      month: 'long',
      day: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: true,
      timeZone: 'Asia/Manila',
    }).format(date)} PHT`;
  }

  function show(data) {
    const amount = Number(data?.amount);
    if (!data || !Number.isFinite(amount) || amount <= 0 || !data.userName || !data.referenceNumber || !data.createdAt) {
      console.error('Receipt needs backend-saved amount, userName, referenceNumber and createdAt.');
      return false;
    }

    mount();
    const type = data.type === 'investment' ? 'investment' : 'deposit';
    const isInvestment = type === 'investment';
    const status = data.status === 'completed' ? 'completed' : data.status === 'rejected' ? 'rejected' : 'pending';
    const fee = Number.isFinite(Number(data.fee)) && Number(data.fee) >= 0 ? Number(data.fee) : 0;
    const total = amount + fee;
    const byId = id => document.getElementById(id);
    const statusText = {
      completed: isInvestment ? 'Completed' : 'Paid · Confirmed',
      pending: isInvestment ? 'Processing' : 'Pending verification',
      rejected: 'Not approved',
    };
    const noteText = {
      completed: isInvestment
        ? 'Your investment allocation was recorded successfully. View its plan terms and status in My Investments.'
        : 'Payment was confirmed in the transaction record. Keep this receipt for your records.',
      pending: isInvestment
        ? 'Your investment allocation is being processed. Check My Investments for its status.'
        : 'Your add funds request was submitted. Payment must be verified before the amount is credited. This is not proof of a successful transfer.',
      rejected: isInvestment
        ? 'This investment allocation was not approved. Please contact support if you need assistance.'
        : 'This add funds request was not approved. Please contact support if you need assistance.',
    };

    byId('mtRTitle').textContent = isInvestment
      ? 'Investment receipt'
      : status === 'completed' ? 'Paid' : status === 'rejected' ? 'Not approved' : 'Add funds request';
    byId('mtRAmount').textContent = formatMoney(amount);
    byId('mtRToName').textContent = data.toName || 'Mom’s Touch Members Club';
    byId('mtRToSub').textContent = data.toDescription || (isInvestment ? 'Investment allocation' : 'Add Funds');
    byId('mtRUserName').textContent = String(data.userName);
    const method = data.bank ? String(data.bank) : data.method || data.paymentMethod || 'Bank transfer';
    byId('mtRMethod').textContent = method;
    byId('mtRFee').textContent = formatMoney(fee);
    byId('mtRTotal').textContent = formatMoney(total);
    byId('mtRStatus').textContent = statusText[status];
    byId('mtRReference').textContent = String(data.referenceNumber);
    byId('mtRDate').textContent = formatDate(data.createdAt);
    byId('mtRNote').textContent = noteText[status];
    byId('mtRMark').innerHTML = status === 'completed' ? checkIcon : status === 'rejected' ? rejectedIcon : pendingIcon;
    activeReceipt = {
      type,
      amount,
      fee,
      userName: String(data.userName),
      method,
      reference: String(data.referenceNumber),
      date: byId('mtRDate').textContent,
      status: statusText[status],
    };

    lastFocus = document.activeElement;
    previousOverflow = document.body.style.overflow;
    const app = document.getElementById('app');
    previousAppInert = app?.inert ?? false;
    if (app) app.inert = true;
    overlay.setAttribute('aria-hidden', 'false');
    overlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    paper.focus();
    return true;
  }

  function close() {
    if (!overlay?.classList.contains('is-open')) return;
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = previousOverflow;
    const app = document.getElementById('app');
    if (app) app.inert = previousAppInert;
    if (lastFocus?.isConnected) lastFocus.focus();
  }

  function roundedRect(context, x, y, width, height, radius) {
    context.beginPath();
    context.moveTo(x + radius, y);
    context.arcTo(x + width, y, x + width, y + height, radius);
    context.arcTo(x + width, y + height, x, y + height, radius);
    context.arcTo(x, y + height, x, y, radius);
    context.arcTo(x, y, x + width, y, radius);
    context.closePath();
  }

  function wrapText(context, value, maxWidth) {
    const lines = [];
    let line = '';

    for (const word of String(value).split(/\s+/)) {
      const candidate = line ? `${line} ${word}` : word;
      if (context.measureText(candidate).width <= maxWidth) {
        line = candidate;
        continue;
      }

      if (line) lines.push(line);
      if (context.measureText(word).width <= maxWidth) {
        line = word;
        continue;
      }

      line = '';
      let fragment = '';
      for (const character of Array.from(word)) {
        if (fragment && context.measureText(fragment + character).width > maxWidth) {
          lines.push(fragment);
          fragment = character;
        } else {
          fragment += character;
        }
      }
      line = fragment;
    }

    if (line) lines.push(line);
    return lines.length ? lines : ['—'];
  }

  function loadBrandLogo() {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = () => reject(new Error('The receipt logo could not be loaded.'));
      image.src = '/assets/brand.webp';
    });
  }

  async function createReceiptCanvas() {
    if (!activeReceipt) throw new Error('There is no receipt to share.');

    const brandLogo = await loadBrandLogo();

    const width = 1080;
    const padding = 96;
    const innerWidth = width - padding * 2;
    const measureCanvas = document.createElement('canvas');
    const measureContext = measureCanvas.getContext('2d');
    if (!measureContext) throw new Error('Receipt image rendering is unavailable.');

    const rows = [
      ['To', document.getElementById('mtRToName').textContent],
      ['From', activeReceipt.userName],
      [activeReceipt.type === 'investment' ? 'Source' : 'Bank', activeReceipt.method],
      ['Fee', formatMoney(activeReceipt.fee)],
      ['Total', formatMoney(activeReceipt.amount + activeReceipt.fee)],
      ['Status', activeReceipt.status],
      ['Reference No.', activeReceipt.reference],
      ['Date', activeReceipt.date],
    ];
    const rowLayouts = rows.map(([label, value]) => {
      measureContext.font = '600 27px Arial, sans-serif';
      const lines = wrapText(measureContext, value, innerWidth);
      return { label, lines, height: Math.max(104, lines.length * 38 + 62) };
    });

    measureContext.font = '400 25px Arial, sans-serif';
    const noteLines = wrapText(measureContext, document.getElementById('mtRNote').textContent, innerWidth - 48);
    const rowTop = 520;
    const noteTop = rowTop + rowLayouts.reduce((height, row) => height + row.height, 0) + 22;
    const noteHeight = 62 + noteLines.length * 36 + 30;
    const height = noteTop + noteHeight + 64;
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    if (!context) throw new Error('Receipt image rendering is unavailable.');

    context.fillStyle = '#f5f2ea';
    context.fillRect(0, 0, width, height);
    context.shadowColor = 'rgba(48, 43, 36, .12)';
    context.shadowBlur = 28;
    context.shadowOffsetY = 10;
    roundedRect(context, 42, 40, width - 84, height - 80, 28);
    context.fillStyle = '#fff';
    context.fill();
    context.shadowColor = 'transparent';
    context.shadowBlur = 0;
    context.shadowOffsetY = 0;

    context.drawImage(brandLogo, padding, 98, 490, 64);
    context.strokeStyle = '#f0eee8';
    context.lineWidth = 2;
    context.beginPath();
    context.moveTo(padding, 202);
    context.lineTo(width - padding, 202);
    context.stroke();

    const title = activeReceipt.type === 'investment'
      ? 'Investment receipt'
      : activeReceipt.status === 'Paid · Confirmed' ? 'Payment confirmed' : 'Add funds request';
    context.fillStyle = '#302b24';
    context.font = '700 39px Arial, sans-serif';
    context.fillText(title, padding, 275);
    context.fillStyle = '#302b24';
    context.font = '800 76px Arial, sans-serif';
    context.fillText(formatMoney(activeReceipt.amount), padding, 372);

    const statusColors = activeReceipt.status === 'Paid · Confirmed'
      ? { background: '#edf4e8', text: '#4c7043' }
      : activeReceipt.status === 'Not approved'
        ? { background: '#faeae2', text: '#994d36' }
        : { background: '#fff3d6', text: '#8c621b' };
    context.font = '700 22px Arial, sans-serif';
    const statusWidth = context.measureText(activeReceipt.status).width + 42;
    roundedRect(context, padding, 405, statusWidth, 48, 24);
    context.fillStyle = statusColors.background;
    context.fill();
    context.fillStyle = statusColors.text;
    context.textBaseline = 'middle';
    context.fillText(activeReceipt.status, padding + 21, 429);
    context.textBaseline = 'alphabetic';

    let y = rowTop;
    for (const row of rowLayouts) {
      context.fillStyle = '#78746b';
      context.font = '500 22px Arial, sans-serif';
      context.fillText(row.label, padding, y + 28);
      context.fillStyle = '#2c2923';
      context.font = '600 27px Arial, sans-serif';
      context.textAlign = 'right';
      row.lines.forEach((line, index) => context.fillText(line, width - padding, y + 66 + index * 38));
      context.textAlign = 'left';
      y += row.height;
      context.strokeStyle = '#f0eee8';
      context.lineWidth = 2;
      context.beginPath();
      context.moveTo(padding, y - 1);
      context.lineTo(width - padding, y - 1);
      context.stroke();
    }

    roundedRect(context, padding, noteTop, innerWidth, noteHeight, 16);
    context.fillStyle = '#fcf6e9';
    context.fill();
    context.fillStyle = '#736850';
    context.font = '400 25px Arial, sans-serif';
    noteLines.forEach((line, index) => context.fillText(line, padding + 24, noteTop + 54 + index * 36));

    return canvas;
  }

  function canvasToBlob(canvas) {
    return new Promise((resolve, reject) => {
      if (typeof canvas.toBlob !== 'function') {
        reject(new Error('This browser cannot export receipt images.'));
        return;
      }
      canvas.toBlob(blob => {
        if (blob) resolve(blob);
        else reject(new Error('The receipt image could not be created.'));
      }, 'image/png');
    });
  }

  function receiptFileName() {
    const reference = activeReceipt.reference.replace(/[^a-zA-Z0-9_-]/g, '-').slice(0, 80);
    return `moms-touch-receipt-${reference || 'receipt'}.png`;
  }

  function downloadReceiptImage(file) {
    const url = URL.createObjectURL(file);
    const link = document.createElement('a');
    link.href = url;
    link.download = file.name;
    link.hidden = true;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function setShareLabel(message) {
    const label = document.querySelector('#mtRShare span');
    if (!label) return;
    window.clearTimeout(shareFeedbackTimer);
    label.textContent = message;
    shareFeedbackTimer = window.setTimeout(() => {
      if (label.isConnected) label.textContent = 'Share receipt';
    }, 2500);
  }

  async function share() {
    if (!activeReceipt) return;
    const button = document.getElementById('mtRShare');
    button.disabled = true;
    try {
      const canvas = await createReceiptCanvas();
      const image = await canvasToBlob(canvas);
      const file = new File([image], receiptFileName(), { type: 'image/png' });
      const title = activeReceipt.type === 'investment' ? "Mom's Touch Investment Receipt" : "Mom's Touch Add Funds Receipt";

      if (typeof navigator.share === 'function' && typeof navigator.canShare === 'function') {
        try {
          if (navigator.canShare({ files: [file] })) {
            await navigator.share({ title, files: [file] });
            return;
          }
        } catch (error) {
          if (error.name === 'AbortError') return;
          console.warn('Receipt image sharing failed; downloading the image instead.', error);
        }
      }

      downloadReceiptImage(file);
      setShareLabel('Receipt image saved');
    } catch (error) {
      console.error('Receipt image could not be prepared for sharing.', error);
      setShareLabel('Image unavailable');
    } finally {
      if (button?.isConnected) button.disabled = false;
    }
  }

  function handleKeydown(event) {
    if (!overlay?.classList.contains('is-open')) return;
    if (event.key === 'Escape') {
      close();
      return;
    }
    if (event.key !== 'Tab') return;

    const focusable = [...paper.querySelectorAll('button:not(:disabled)')].filter(element => element.getClientRects().length);
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && (document.activeElement === first || document.activeElement === paper)) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && (document.activeElement === last || document.activeElement === paper)) {
      event.preventDefault();
      first?.focus();
    }
  }

  window.MomsTouchReceipt = {
    show,
    close,
    set onHelp(callback) {
      onHelp = typeof callback === 'function' ? callback : null;
    },
  };
})();
