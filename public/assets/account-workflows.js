'use strict';
function isPortalAdmin(){return native&&state.user?.is_admin===true;}
function approvalStatus(status){return ({pending:'Pending review',approved:'Approved',rejected:'Rejected',active:'Active',completed:'Term completed'})[status]||status;}
function statusBadge(status){return `<span class="workflow-status workflow-${esc(status)}">${esc(approvalStatus(status))}</span>`;}
function portalDate(date){return date?new Date(date).toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}):'—';}
function memberWalletRequests(){
 const rows=state.wallet_requests||[];
 return `<section class="panel member-requests"><div class="panel-head"><div><h2>Deposit & withdrawal requests</h2><p class="panel-sub">Track admin reviews and the notes for each request.</p></div><span class="neutral-tag">${rows.filter(r=>r.status==='pending').length} PENDING</span></div>${rows.length?`<div class="request-cards">${rows.map(r=>`<article class="member-request"><div><strong>${r.type==='deposit'?'Deposit':'Withdrawal'} · ${cash(r.amount)}</strong><small>${esc(r.reference)} · ${portalDate(r.created_at)}${r.payment_method?` · ${esc(window.PORTAL_BANK_LABELS?.[r.payment_method]||r.payment_method)}`:''}</small>${r.test_only?'<p class="demo-notice">TEST ONLY — this request cannot be approved or credited.</p>':''}${r.review_note?`<p>Review note: ${esc(r.review_note)}</p>`:''}${r.type==='withdraw'&&r.status==='pending'?'<p>This amount is reserved while the admin reviews your request.</p>':''}</div>${statusBadge(r.status)}</article>`).join('')}</div>`:'<p class="workflow-empty">Your requests will appear here after you submit a deposit or withdrawal.</p>'}</section>`;
}
function authEntryOptions(forgot){
 if(!native)return '<div class="production-setup-note" role="status"><strong>Your member space is getting ready.</strong><p>Sign-in and registration will open shortly. Please check back soon.</p></div>';
 return forgot?'':'<p class="auth-access-note">Members and administrators use this same secure sign-in form.</p>';
}
function finishPortalRender(page){
 state.wallet.held??=0;state.wallet_requests??=[];state.withdrawal_accounts??=[];
 if(!native||!window.PORTAL_CONFIG?.financial_operations_enabled){document.querySelectorAll('[data-action="deposit"],[data-action="withdraw"],[data-action="invest"]').forEach(button=>{button.disabled=true;button.title='Wallet operations are temporarily paused.';});}
 if(page==='register'){
  const referralCode=new URLSearchParams(location.search).get('ref');
  const form=document.getElementById('auth-form');
  if(referralCode&&form){const input=document.createElement('input');input.type='hidden';input.name='referral_code';input.value=referralCode;form.append(input);}
 }
 if(page==='login'&&native&&state.user){const form=document.getElementById('auth-form');if(form){form.hidden=true;form.insertAdjacentHTML('beforebegin',`<div class="existing-session"><p>You are signed in as <strong>${esc(state.user.name)}</strong>.</p><button class="btn btn-dark" data-action="${isPortalAdmin()?'admin-home':'dashboard'}">Continue to ${isPortalAdmin()?'admin':'your account'} ${I('arrow')}</button><button class="btn btn-outline" data-action="logout">Use another account</button></div>`);}}
}
document.addEventListener('click',e=>{if(e.target.closest('[data-action="logout"]'))clearAdminCache();},true);
document.addEventListener('click',async e=>{
 const button=e.target.closest('[data-action="copy-referral"],[data-action="share-referral"]');
 if(!button)return;
 const code=button.dataset.referralCode?.trim();
 const link=code?referralUrl(code):document.getElementById('referral-link')?.value;
 if(!link){toast('Your referral link is not available.');return;}
 try{
  if(button.dataset.action==='share-referral'&&navigator.share){
   try{await navigator.share({title:'Mom’s Touch member portal',text:'Join Mom’s Touch using my referral link.',url:link});return;}
   catch(error){if(error?.name==='AbortError')return;}
  }
  if(!navigator.clipboard?.writeText)throw new Error('Clipboard access is unavailable in this browser.');
  await navigator.clipboard.writeText(link);
  toast('Referral link copied.');
 }catch(error){toast(error instanceof Error?error.message:'Unable to share the referral link.');}
});
