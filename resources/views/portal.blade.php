<!doctype html>
<html lang="en">
<head>
@php
    $referralCode = request()->query('ref');
    $referralCode = is_string($referralCode) ? substr(trim($referralCode), 0, 80) : '';
    $specialReferralCode = request()->query('special_ref');
    $specialReferralCode = is_string($specialReferralCode) ? substr(trim($specialReferralCode), 0, 80) : '';
    $isSpecialReferralShare = $specialReferralCode !== '';
    $isReferralShare = $referralCode !== '' || $isSpecialReferralShare;
    $shareCode = $isSpecialReferralShare ? $specialReferralCode : $referralCode;
    $shareParameter = $isSpecialReferralShare ? 'special_ref' : 'ref';
    $socialUrl = $isReferralShare ? url('/').'?'.$shareParameter.'='.rawurlencode($shareCode) : url('/');
    $socialTitle = $isSpecialReferralShare
        ? 'A special Mom’s Touch invitation'
        : ($isReferralShare ? 'You’re invited to join Mom’s Touch' : 'Mom’s Touch — Member Portal');
    $socialDescription = $isSpecialReferralShare
        ? 'Share a special invitation to the Mom’s Touch Members Club with a seminar image preview.'
        : ($isReferralShare
            ? 'Explore the Mom’s Touch Members Club, member updates, events, and more through this referral link.'
            : 'Explore the Mom’s Touch member portal, member updates, and more.');
    $socialImage = $isSpecialReferralShare
        ? url('/Seminar%20room%20with%20refreshed%20Mom%E2%80%99s%20Touch%20banners.png?v=20261125-special-preview')
        : asset('assets/social-preview.jpg');
    $socialImageType = $isSpecialReferralShare ? 'image/png' : 'image/jpeg';
    $socialImageWidth = $isSpecialReferralShare ? 1672 : 1200;
    $socialImageHeight = $isSpecialReferralShare ? 941 : 630;
    $socialImageAlt = $isSpecialReferralShare
        ? 'Mom’s Touch Philippine expansion seminar presentation'
        : 'Mom’s Touch member portal with a chicken burger';
@endphp
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#f4ad19"><meta name="description" content="{{ $socialDescription }}">
<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="Mom’s Touch"><meta name="apple-mobile-web-app-status-bar-style" content="default">
<title>{{ $socialTitle }}</title>
<link rel="canonical" href="{{ url('/') }}">
<meta property="og:type" content="website"><meta property="og:site_name" content="Mom’s Touch">
<meta property="og:title" content="{{ $socialTitle }}"><meta property="og:description" content="{{ $socialDescription }}">
<meta property="og:url" content="{{ $socialUrl }}"><meta property="og:image" content="{{ $socialImage }}">
<meta property="og:image:secure_url" content="{{ $socialImage }}"><meta property="og:image:type" content="{{ $socialImageType }}">
<meta property="og:image:width" content="{{ $socialImageWidth }}"><meta property="og:image:height" content="{{ $socialImageHeight }}">
<meta property="og:image:alt" content="{{ $socialImageAlt }}">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="{{ $socialTitle }}">
<meta name="twitter:description" content="{{ $socialDescription }}"><meta name="twitter:image" content="{{ $socialImage }}">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg"><link rel="apple-touch-icon" sizes="512x512" href="/unnamed.png"><link rel="manifest" href="/site.webmanifest"><link rel="stylesheet" href="/assets/fonts.css?v=20261117-production"><link rel="stylesheet" href="/assets/style.css?v=20261125-seminar-image-full"><link rel="stylesheet" href="/assets/admin.css?v=20261125-member-label"><link rel="stylesheet" href="/assets/install-popup.css?v=20261123-first-login"><link rel="stylesheet" href="/assets/deposit-receipt.css?v=20261123-investment-receipt">
</head>
<body><div id="portal-loader" class="portal-loader" role="status" aria-label="Loading Mom's Touch" aria-hidden="false"><div class="portal-loader-content"><video autoplay muted playsinline preload="auto" aria-hidden="true"><source src="/Moms%20touch.mp4" type="video/mp4"></video><img class="portal-loader-fallback" src="/assets/brand.webp" alt="Mom's Touch"></div><img class="portal-loader-brand" src="/assets/brand.webp" alt="" aria-hidden="true"></div><div id="app"></div><div id="modal-root"></div><div id="toast" role="status" aria-live="polite"></div><script nonce="{{ request()->attributes->get('csp_nonce') }}">window.PORTAL_CONFIG = {laravel: true, financial_operations_enabled: @json((bool) config('portal.financial_operations_enabled')), registration_enabled: @json((bool) config('portal.registration_enabled')), demo_deposit_qr: @json((bool) config('portal.deposit_qr_demo_mode')), web_push_public_key: @json(config('webpush.vapid.public_key')), csrf: @json(csrf_token())};</script><script src="/assets/investment-plans.js?v=20261124-invest-button-morph" defer></script><script src="/assets/franchising.js?v=20261117-production" defer></script><script src="/assets/account-workflows.js?v=20261125-referral-preview-assignment" defer></script><script src="/assets/admin.js?v=20261125-member-label" defer></script><script src="/assets/install-popup.js?v=20261123-first-login" defer></script><script src="/assets/deposit-receipt.js?v=20261123-investment-receipt" defer></script><script src="/assets/app.js?v=20261125-referral-preview-assignment" defer></script><script src="/assets/push-notifications.js?v=20261122-push" defer></script></body>
</html>
