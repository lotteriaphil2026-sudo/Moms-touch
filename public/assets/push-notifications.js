function pushSupported(){
 return window.isSecureContext&&'serviceWorker' in navigator&&'PushManager' in window&&'Notification' in window;
}
function needsInstalledIOSApp(){
 const isIOS=/iPhone|iPad|iPod/.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);
 return isIOS&&!window.matchMedia('(display-mode: standalone)').matches&&!navigator.standalone;
}
function pushStatus(message,action,disabled=false){
 const status=document.getElementById('phone-push-status');
 const button=document.getElementById('phone-push-button');
 if(!status||!button)return;
 status.textContent=message;
 button.dataset.pushAction=action;
 button.disabled=disabled;
}
async function updatePushPanel(){
 if(!document.getElementById('phone-push-panel'))return;
 if(!window.PORTAL_CONFIG?.web_push_public_key){
  pushStatus('Phone notifications are not configured yet. Please check back later.','enable',true);
  return;
 }
 if(!pushSupported()){
  pushStatus('This browser cannot receive phone notifications. On iPhone or iPad, use an installed Home Screen app on iOS/iPadOS 16.4 or newer.','enable',true);
  return;
 }
 if(needsInstalledIOSApp()){
  pushStatus('On iPhone or iPad, add this website to your Home Screen and open it there to enable notifications (iOS/iPadOS 16.4 or newer).','enable',true);
  return;
 }
 if(Notification.permission==='denied'){
  pushStatus('Notifications are blocked for this site. Allow them in your browser or device settings, then try again.','enable',true);
  return;
 }
 const registration=await navigator.serviceWorker.getRegistration('/');
 const subscription=registration?await registration.pushManager.getSubscription():null;
 pushStatus(subscription?'Phone notifications are on for this device.':'Phone notifications are off for this device.',subscription?'disable':'enable');
}
function decodePushKey(key){
 const padding='='.repeat((4-key.length%4)%4);
 const decoded=atob((key+padding).replace(/-/g,'+').replace(/_/g,'/'));
 return Uint8Array.from(decoded,character=>character.charCodeAt(0));
}
async function pushApi(method,body){
 const response=await fetch('/portal/push/subscriptions',{
  method,
  credentials:'same-origin',
  headers:{
   'Accept':'application/json',
   'Content-Type':'application/json',
   'X-CSRF-TOKEN':window.PORTAL_CONFIG?.csrf||''
  },
  ...(body?{body:JSON.stringify(body)}:{})
 });
 const data=await response.json();
 if(data.csrf)window.PORTAL_CONFIG.csrf=data.csrf;
 if(!response.ok)throw new Error(Object.values(data.errors||{}).flat()[0]||data.message||'Unable to update phone notifications.');
 return data;
}
async function enablePush(){
 if(!pushSupported())throw new Error('This browser does not support phone notifications.');
 if(needsInstalledIOSApp())throw new Error('On iPhone or iPad, add this website to your Home Screen and open it there first.');
 if(!window.PORTAL_CONFIG?.web_push_public_key)throw new Error('Phone notifications are not configured yet.');
 const permission=Notification.permission==='granted'?'granted':await Notification.requestPermission();
 if(permission!=='granted')throw new Error('Allow notifications in your browser or device settings to continue.');
 const registration=await navigator.serviceWorker.register('/service-worker.js',{scope:'/'});
 let subscription=await registration.pushManager.getSubscription();
 if(!subscription){
  subscription=await registration.pushManager.subscribe({
   userVisibleOnly:true,
   applicationServerKey:decodePushKey(window.PORTAL_CONFIG.web_push_public_key)
  });
 }
 await pushApi('POST',subscription.toJSON());
}
async function disablePush(){
 const registration=await navigator.serviceWorker.getRegistration('/');
 const subscription=registration?await registration.pushManager.getSubscription():null;
 if(subscription){
  await pushApi('DELETE',{endpoint:subscription.endpoint});
  await subscription.unsubscribe();
 }
}
document.addEventListener('click',async event=>{
 const button=event.target.closest('[data-push-action]');
 if(!button)return;
 button.disabled=true;
 try{
  if(button.dataset.pushAction==='enable'){
   await enablePush();
   pushStatus('Phone notifications are on for this device.','disable');
  }else{
   await disablePush();
   pushStatus('Phone notifications are off for this device.','enable');
  }
 }catch(error){
  pushStatus(error.message,button.dataset.pushAction);
 }finally{
  if(button.isConnected&&!button.disabled)button.disabled=false;
 }
});
let activePushPanel=null;
function refreshPushPanel(){
 const panel=document.getElementById('phone-push-panel');
 if(panel===activePushPanel)return;
 activePushPanel=panel;
 if(panel)updatePushPanel().catch(error=>pushStatus(error.message,'enable',true));
}
const pushObserver=new MutationObserver(refreshPushPanel);
pushObserver.observe(document.getElementById('app'),{childList:true,subtree:true});
refreshPushPanel();
