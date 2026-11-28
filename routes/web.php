<?php
use App\Http\Controllers\{AdminController, AuthController, FranchiseApplicationController, GoogleAuthController, PortalController, PromotionController, PushSubscriptionController, WithdrawalAccountController};
use App\Http\Middleware\EnsureAdministrator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::view('/', 'portal')->name('home');
Route::get('/login', fn () => redirect('/#/login'))->name('login');
Route::get('/register', function (\Illuminate\Http\Request $request) {
    $referralCode = trim((string) $request->query('ref'));
    $destination = '/#/register';
    if ($referralCode !== '') {
        $destination .= '?ref='.rawurlencode(Str::upper($referralCode));
    }
    return redirect($destination);
})->name('register');
Route::get('/certificates/{certificate}/view', [AdminController::class, 'viewCertificate'])
    ->middleware('signed')
    ->name('certificates.view');
Route::get('/forgot-password', fn () => redirect('/#/forgot'))->name('password.request');
Route::get('/reset-password/{token}', fn (string $token) => view('reset-password', ['token' => $token]))->middleware('guest')->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'reset'])->middleware(['guest', 'throttle:6,1'])->name('password.update');
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:10,1')->name('google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:10,1')->name('google.callback');

Route::prefix('portal')->group(function () {
    Route::get('/state', [PortalController::class, 'state']);
    Route::middleware('guest')->group(function () {
        Route::get('/google-onboarding', [GoogleAuthController::class, 'onboarding'])->middleware('throttle:10,1');
        Route::post('/google-register', [GoogleAuthController::class, 'register'])->middleware('throttle:5,1');
    });
    Route::post('/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:portal-login']);
    Route::post('/register', [AuthController::class, 'register'])->middleware(['guest', 'throttle:5,1']);
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware(['guest', 'throttle:5,1']);
    Route::middleware('auth')->group(function () {
        Route::post('/presence', [PortalController::class, 'presence'])->middleware('throttle:120,1');
        Route::post('/promotions/home-screen-bonus/claim', [PromotionController::class, 'claimHomeScreenBonus'])->middleware('throttle:3,1');
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::patch('/profile', [PortalController::class, 'profile'])->middleware('throttle:20,1');
        Route::post('/notifications/read-all', [PortalController::class, 'markNotificationsRead'])->middleware('throttle:10,1');
        Route::post('/withdrawal-accounts', [WithdrawalAccountController::class, 'store'])->middleware('throttle:10,1');
        Route::delete('/withdrawal-accounts/{withdrawalAccount}', [WithdrawalAccountController::class, 'destroy'])->middleware('throttle:10,1');
        Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:10,1');
        Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:10,1');
        Route::post('/franchise-applications', [FranchiseApplicationController::class, 'store'])->middleware('throttle:5,1');
        Route::post('/transactions', [PortalController::class, 'transact'])->middleware('throttle:30,1');
        Route::prefix('admin')->middleware([EnsureAdministrator::class, 'throttle:60,1'])->group(function () {
            Route::get('/overview', [AdminController::class, 'overview']);
            Route::get('/users', [AdminController::class, 'users']);
            Route::get('/users/{user}', [AdminController::class, 'user']);
            Route::post('/users/{user}/classification', [AdminController::class, 'classifyUser']);
            Route::post('/users/{user}/referral-preview', [AdminController::class, 'assignReferralPreview']);
            Route::post('/users/{user}/label', [AdminController::class, 'updateMemberLabel']);
            Route::post('/users/{user}/certificates', [AdminController::class, 'sendCertificate']);
            Route::get('/deposit-users', [AdminController::class, 'depositUsers']);
            Route::get('/receipt-logs', [AdminController::class, 'receiptLogs']);
            Route::get('/requests', [AdminController::class, 'requests']);
            Route::get('/requests/{walletRequest}/receipt', [AdminController::class, 'receipt']);
            Route::post('/requests/{walletRequest}/review', [AdminController::class, 'review']);
            Route::post('/users/{user}/funds', [AdminController::class, 'funds']);
            Route::post('/users/{user}/plans', [AdminController::class, 'plans']);
            Route::get('/audit', [AdminController::class, 'audit']);
        });
    });
});
