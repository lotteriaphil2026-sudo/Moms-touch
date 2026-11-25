<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#f4ad19"><meta name="description" content="Your Mom’s Touch member space. Explore your wallet, investments, events, and menu favorites.">
<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="Mom’s Touch"><meta name="apple-mobile-web-app-status-bar-style" content="default">
<title>Mom’s Touch — Member Portal</title>
<link rel="canonical" href="{{ url('/') }}">
<meta property="og:type" content="website"><meta property="og:site_name" content="Mom’s Touch">
<meta property="og:title" content="Mom’s Touch — Member Portal"><meta property="og:description" content="Explore the Mom’s Touch member portal, member updates, and more.">
<meta property="og:url" content="{{ url('/') }}"><meta property="og:image" content="{{ asset('assets/social-preview.jpg') }}">
<meta property="og:image:secure_url" content="{{ asset('assets/social-preview.jpg') }}"><meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Mom’s Touch member portal with a chicken burger">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="Mom’s Touch — Member Portal">
<meta name="twitter:description" content="Explore the Mom’s Touch member portal, member updates, and more."><meta name="twitter:image" content="{{ asset('assets/social-preview.jpg') }}">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg"><link rel="apple-touch-icon" sizes="512x512" href="/unnamed.png"><link rel="manifest" href="/site.webmanifest"><link rel="stylesheet" href="/assets/fonts.css?v=20261117-production"><link rel="stylesheet" href="/assets/style.css?v=20261125-member-pages-soft-shadows"><link rel="stylesheet" href="/assets/admin.css?v=20261125-member-profile-reference-layout-v3"><link rel="stylesheet" href="/assets/install-popup.css?v=20261123-first-login"><link rel="stylesheet" href="/assets/deposit-receipt.css?v=20261123-investment-receipt">
</head>
<body><div id="portal-loader" class="portal-loader" role="status" aria-label="Loading Mom's Touch" aria-hidden="false"><div class="portal-loader-content"><video autoplay muted playsinline preload="auto" aria-hidden="true"><source src="/Moms%20touch.mp4" type="video/mp4"></video><img class="portal-loader-fallback" src="/assets/brand.webp" alt="Mom's Touch"></div><img class="portal-loader-brand" src="/assets/brand.webp" alt="" aria-hidden="true"></div><div id="app"></div><div id="modal-root"></div><div id="toast" role="status" aria-live="polite"></div><script nonce="{{ request()->attributes->get('csp_nonce') }}">window.PORTAL_CONFIG = {laravel: true, financial_operations_enabled: @json((bool) config('portal.financial_operations_enabled')), registration_enabled: @json((bool) config('portal.registration_enabled')), demo_deposit_qr: @json((bool) config('portal.deposit_qr_demo_mode')), web_push_public_key: @json(config('webpush.vapid.public_key')), csrf: @json(csrf_token())};</script><script src="/assets/investment-plans.js?v=20261124-invest-button-morph" defer></script><script src="/assets/franchising.js?v=20261117-production" defer></script><script src="/assets/account-workflows.js?v=20261123-withdrawal-accounts" defer></script><script src="/assets/admin.js?v=20261125-member-profile-reference-layout-v3" defer></script><script src="/assets/install-popup.js?v=20261123-first-login" defer></script><script src="/assets/deposit-receipt.js?v=20261123-investment-receipt" defer></script><script src="/assets/app.js?v=20261125-member-pages-soft-shadows" defer></script><script src="/assets/push-notifications.js?v=20261122-push" defer></script></body>
</html>
