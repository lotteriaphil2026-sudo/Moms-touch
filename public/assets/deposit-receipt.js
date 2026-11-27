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
            <div class="mt-r-brand">MOM’S TOUCH <span style="color:#c58e10">MEMBERS CLUB</span></div>
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
            <button type="button" id="mtRShare" class="mt-r-share"><svg viewBox="0 0 24 24"><path d="M12 16V3m0 0L7 8m5-5 5 5M4 15v5h16v-5"/></svg><span>Share receipt</span></button>
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

  function summary() {
    if (!activeReceipt) return '';
    const receiptType = activeReceipt.type === 'investment' ? 'Investment' : 'Add Funds';
    const methodLabel = activeReceipt.type === 'investment' ? 'Source' : 'Bank';
    return `MOM'S TOUCH - ${receiptType} Receipt\nStatus: ${activeReceipt.status}\nAmount: ${formatMoney(activeReceipt.amount)}\nFrom: ${activeReceipt.userName}\n${methodLabel}: ${activeReceipt.method}\nFee: ${formatMoney(activeReceipt.fee)}\nTotal: ${formatMoney(activeReceipt.amount + activeReceipt.fee)}\nReference No.: ${activeReceipt.reference}\nDate: ${activeReceipt.date}\n${document.getElementById('mtRNote').textContent}`;
  }

  async function share() {
    const text = summary();
    if (!text) return;
    try {
      if (navigator.share) {
        const title = activeReceipt.type === 'investment' ? "Mom's Touch Investment Receipt" : "Mom's Touch Add Funds Receipt";
        await navigator.share({ title, text });
        return;
      }
      if (navigator.clipboard) {
        await navigator.clipboard.writeText(text);
        const label = document.querySelector('#mtRShare span');
        label.textContent = 'Receipt copied';
        window.setTimeout(() => { label.textContent = 'Share receipt'; }, 2000);
        return;
      }
    } catch (error) {
      if (error.name === 'AbortError') return;
    }
    window.print();
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
