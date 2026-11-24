'use strict';
const investmentPlanDefinitions = [
  {id:'select',name:'Mom’s Select',min:500000,rateBps:60,days:160,description:'A thoughtful place to begin.',icon:'leaf'},
  {id:'prime',name:'Mom’s Prime',min:5000000,rateBps:70,days:130,description:'Your next chapter, with a little more.',icon:'trend'},
  {id:'signature',name:'Mom’s Signature',min:25000000,rateBps:90,days:100,description:'A distinctive choice, with your personal touch.',icon:'spark'},
];
const investmentPlanImages={select:'fillet.webp',prime:'burger.webp',signature:'chicken.webp'};
function dailyInterestCents(principal,rateBps){return Math.floor((principal*rateBps+5000)/10000);}
function rateLabel(bps){return (bps/100).toFixed(2)+'%';}
function investmentStatus(inv){return inv.status==='active'?'Active':inv.status==='completed'?'Term completed':'Legacy · terms unset';}
function freshInvestment(plan,amount,id,at=Date.now()){
  return {id,name:plan.name,plan:plan.id,amount,rate_bps:plan.rateBps,term_days:plan.days,credited_days:0,
    started_at:new Date(at).toISOString(),matures_at:new Date(at+plan.days*86400000).toISOString(),created_at:new Date(at).toISOString(),
    status:'active',daily_amount:dailyInterestCents(amount,plan.rateBps),earned:0};
}
function emptyPortalState(){return {
  user:null,wallet:{balance:0,held:0,invested:0,earnings:0,daily_earnings:0},
  investments:[],transactions:[],wallet_requests:[],withdrawal_accounts:[],franchise_applications:[],
};}
function planInvestmentPage(){return heading('YOUR NEXT CHAPTER, WITH CARE','A plan for your personal touch.','Choose your starting point. See every rate and term clearly.',`<button class="btn btn-primary" data-action="invest">${I('plus')}New investment</button>`)+`
<div class="rate-note">${I('shield')}<div><strong>Clear numbers. A simple calculation.</strong>Daily interest is calculated on your original invested amount, without compounding. Review your plan’s terms before allocating funds.</div></div>
<section class="earning-plans" aria-label="Investment plans">${plans.map(p=>{const daily=dailyInterestCents(p.min,p.rateBps);return `<article class="earning-plan earning-plan-${p.id} ${p.id==='prime'?'earning-plan-featured':''}"><div class="earning-art" aria-hidden="true"><span class="earning-art-spark">${I('spark')}</span><img src="/assets/${({select:'fillet.webp',prime:'burger.webp',signature:'chicken.webp'})[p.id]}" alt="" width="200" height="200" decoding="async"></div><div class="earning-plan-top"><span class="investment-icon">${I(p.icon)}</span><span class="earning-term">${p.days} DAYS</span></div><h2>${p.name}</h2><p class="earning-plan-description">${p.description}</p><div class="earning-rate">${rateLabel(p.rateBps)}<span>daily interest</span></div><div class="earning-start">Starts at <strong>${short(p.min)}</strong></div><div class="earning-breakdown"><div><span>Daily at the minimum</span><strong>${cash(daily)}</strong></div><div><span>Interest over ${p.days} days</span><strong>${cash(daily*p.days)}</strong></div></div><button class="btn ${p.id==='prime'?'btn-primary':'btn-outline'}" data-action="invest" data-id="${p.id}">Choose ${p.name.replace('Mom’s ','')} ${I('arrow')}</button><p class="earning-card-note">Simple interest · Illustrative calculation</p></article>`;}).join('')}</section>
<section class="earning-summary"><div>${I('leaf')}<span>Total interest credited<strong>${cash(state.wallet.earnings)}</strong></span></div><div>${I('clock')}<span>Scheduled daily earnings<strong>${cash(state.wallet.daily_earnings||0)}</strong></span></div><p>Credits begin after 24 hours and stop when the term is complete. Principal remains allocated until a release process is agreed.</p></section>
<section class="panel investment-ledger"><div class="panel-head"><h2>Your investments</h2><span class="neutral-tag">${state.investments.length} TOTAL</span></div><div class="table-wrap"><table><thead><tr><th>Plan / principal</th><th>Daily interest</th><th>Progress</th><th>Interest credited</th><th>Status</th></tr></thead><tbody>${state.investments.length?state.investments.map(inv=>`<tr><td><strong class="investment-name">${esc(inv.name)}</strong><small class="investment-sub">${cash(inv.amount)}</small></td><td>${inv.term_days?`${rateLabel(inv.rate_bps)}<small class="investment-sub">${cash(dailyInterestCents(inv.amount,inv.rate_bps))} / day</small>`:'Not set'}</td><td>${inv.term_days?`${inv.credited_days||0} / ${inv.term_days} days<small class="investment-sub">Ends ${new Date(inv.matures_at).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric',timeZone:'Asia/Manila'})}</small>`:'Legacy allocation'}</td><td>${cash(inv.earned||0)}</td><td><span class="status-pill ${inv.status==='active'?'':'pending'}">${investmentStatus(inv)}</span></td></tr>`).join(''):'<tr><td colspan="5" style="text-align:center;padding:30px">Your investments will appear here.</td></tr>'}</tbody></table></div></section>${walletNotice()}`;}
function openInvestmentModal(planId='select'){
  if(!signed())return;const plan=plans.find(p=>p.id===planId)||plans[0];
  openModal(`<div class="investment-modal-hero investment-modal-hero-${plan.id}" data-plan-id="${plan.id}"><div class="investment-modal-hero-copy"><h1 id="investment-hero-title">${plan.name}</h1><p id="investment-hero-description">${plan.description}</p></div><div class="investment-modal-art" aria-hidden="true"><span class="investment-modal-orb"></span><span class="investment-modal-spark">${I('spark')}</span><img id="investment-hero-image" src="/assets/${investmentPlanImages[plan.id]}" alt="" width="360" height="360" decoding="async"></div><div class="investment-modal-stats"><div><strong id="investment-hero-rate">${rateLabel(plan.rateBps)}</strong><span>daily interest</span></div><div><strong id="investment-hero-days">${plan.days} days</strong><span>plan duration</span></div></div></div><div class="investment-modal-body"><h2 id="dialog-title">Review your plan</h2><p>Review the amount and plan terms before you start.</p><form id="money-form" data-type="invest" data-request-id="${crypto.randomUUID()}"><div class="field"><label for="plan-choice">Choose your plan</label><select id="plan-choice" name="plan">${plans.map(p=>`<option value="${p.id}" ${p.id===plan.id?'selected':''}>${p.name} · from ${short(p.min)}</option>`).join('')}</select></div><div class="field"><label for="money-amount">Amount in Philippine Pesos</label><input id="money-amount" name="amount" inputmode="decimal" type="text" value="${plan.min/100}" required maxlength="12" autocomplete="off"></div><div id="investment-quote"></div><div class="detail-row investment-modal-balance"><span>Available balance</span><strong>${cash(state.wallet.balance)}</strong></div><div class="form-message" role="status"></div><button class="btn btn-primary" type="submit">Start plan ${I('arrow')}</button><p class="fineprint">Credits use simple interest on the original amount, rounded to the nearest cent per day. The first credit is after 24 hours. Principal is not automatically released at maturity. This records an internal allocation and does not process a bank payment.</p></form></div>`,true,'investment-modal-dialog');
  updateInvestmentQuote();
}
function resetInvestmentChoiceButton(button,original){
 button.innerHTML=original.html;button.className=original.className;button.disabled=original.disabled;
 button.removeAttribute('aria-busy');button.removeAttribute('aria-label');delete button.dataset.choiceBusy;
}
function animateInvestmentButtonToTitle(button,plan,original){
 const start=button.getBoundingClientRect();
 const ghost=button.cloneNode(false);
 ghost.classList.remove('is-loading','is-success','investment-choice-morph');
 ghost.classList.add('investment-choice-shared');ghost.removeAttribute('data-action');ghost.removeAttribute('data-id');
 ghost.disabled=true;ghost.tabIndex=-1;ghost.setAttribute('aria-hidden','true');
 const label=document.createElement('span');label.textContent=plan.name;ghost.append(label);
 const buttonStyle=getComputedStyle(button);
 ghost.style.left=`${start.left}px`;ghost.style.top=`${start.top}px`;ghost.style.width=`${start.width}px`;ghost.style.height=`${start.height}px`;
 ghost.style.background=buttonStyle.background;ghost.style.color=buttonStyle.color;ghost.style.border=buttonStyle.border;
 ghost.style.borderRadius=buttonStyle.borderRadius;ghost.style.font=buttonStyle.font;
 document.body.append(ghost);
 resetInvestmentChoiceButton(button,original);
 openInvestmentModal(plan.id);
 const target=$('#investment-hero-title');
 if(!target){ghost.remove();return;}
 target.style.opacity='0';
 const range=document.createRange();range.selectNodeContents(target);
 const end=range.getBoundingClientRect();const titleStyle=getComputedStyle(target);
 const finish=()=>{ghost.remove();target.style.opacity='';};
 if(typeof ghost.animate!=='function'){window.setTimeout(finish,420);return;}
 const motion=ghost.animate([
  {left:`${start.left}px`,top:`${start.top}px`,width:`${start.width}px`,height:`${start.height}px`,borderRadius:buttonStyle.borderRadius,backgroundColor:buttonStyle.backgroundColor,color:buttonStyle.color,fontSize:buttonStyle.fontSize,borderColor:buttonStyle.borderColor},
  {left:`${end.left}px`,top:`${end.top}px`,width:`${end.width}px`,height:`${end.height}px`,borderRadius:'3px',backgroundColor:'transparent',color:titleStyle.color,fontSize:titleStyle.fontSize,borderColor:'transparent'}
 ],{duration:420,easing:'cubic-bezier(.2,.75,.25,1)',fill:'forwards'});
 motion.onfinish=finish;
}
async function animateInvestmentChoice(button,planId){
 const plan=plans.find(item=>item.id===planId);if(!plan||button.disabled||button.dataset.choiceBusy==='true')return;
 const original={html:button.innerHTML,className:button.className,disabled:button.disabled};
 button.dataset.choiceBusy='true';button.disabled=true;button.setAttribute('aria-busy','true');
 button.setAttribute('aria-label',`Preparing ${plan.name}`);
 button.classList.add('investment-choice-morph','is-loading');
 button.innerHTML='<span class="investment-choice-spinner" aria-hidden="true"></span><span class="investment-choice-status" role="status">Preparing plan</span>';
 await new Promise(resolve=>window.setTimeout(resolve,250));
 button.classList.remove('is-loading');button.classList.add('is-success');
 button.setAttribute('aria-busy','false');button.setAttribute('aria-label',`${plan.name} selected`);
 button.innerHTML=`<span class="investment-choice-check" aria-hidden="true">${I('check')}</span><span class="investment-choice-status" role="status">Plan ready</span>`;
 await new Promise(resolve=>window.setTimeout(resolve,1200));
 if(window.matchMedia?.('(prefers-reduced-motion: reduce)').matches){resetInvestmentChoiceButton(button,original);openInvestmentModal(plan.id);return;}
 animateInvestmentButtonToTitle(button,plan,original);
}
function updateInvestmentQuote(){const box=$('#investment-quote');if(!box)return;const plan=plans.find(p=>p.id===$('#plan-choice').value)||plans[0];const amount=moneyCents($('#money-amount').value)||0;const daily=dailyInterestCents(amount,plan.rateBps);const hero=$('.investment-modal-hero');if(hero){hero.className=`investment-modal-hero investment-modal-hero-${plan.id}`;hero.dataset.planId=plan.id;$('#investment-hero-title').textContent=plan.name;$('#investment-hero-description').textContent=plan.description;$('#investment-hero-rate').textContent=rateLabel(plan.rateBps);$('#investment-hero-days').textContent=`${plan.days} days`;$('#investment-hero-image').src=`/assets/${investmentPlanImages[plan.id]}`;}box.innerHTML=`<div class="investment-quote investment-modal-quote"><div><span>Daily interest</span><strong>${cash(daily)}</strong></div><div><span>Term interest</span><strong>${cash(daily*plan.days)}</strong></div></div>`;}
async function refreshInvestmentEarnings(){
  if(document.visibilityState==='hidden')return;
  let changed=false;
  if(native&&state.user){try{const next=await api('state');changed=JSON.stringify(next.wallet)!==JSON.stringify(state.wallet)||JSON.stringify(next.wallet_requests)!==JSON.stringify(state.wallet_requests)||next.transactions.length!==state.transactions.length||next.user?.is_admin!==state.user?.is_admin||!next.user;if(next.user?.is_admin!==state.user?.is_admin)clearAdminCache();state=next;}catch{return;}}
  if(changed&&!currentModal&&['dashboard','wallet','investments','admin'].includes(route()))render();
}
document.addEventListener('input',event=>{if(event.target.id==='money-amount')updateInvestmentQuote();});
document.addEventListener('change',event=>{if(event.target.id==='plan-choice'&&$('#investment-quote')){const plan=plans.find(p=>p.id===event.target.value);if(moneyCents($('#money-amount').value)<plan.min)$('#money-amount').value=String(plan.min/100);updateInvestmentQuote();}});
