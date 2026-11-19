<?php
use App\Http\Controllers\{AdminController, AuthController, FranchiseApplicationController, PortalController};
use App\Http\Middleware\EnsureAdministrator;
use Illuminate\Support\Facades\Route;

Route::view('/', 'portal')->name('home');
Route::get('/login', fn () => redirect('/#/login'))->name('login');
Route::get('/register', fn () => redirect('/#/register'))->name('register');
Route::get('/forgot-password', fn () => redirect('/#/forgot'))->name('password.request');
Route::get('/reset-password/{token}', fn (string $token) => view('reset-password', ['token' => $token]))->middleware('guest')->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'reset'])->middleware(['guest', 'throttle:6,1'])->name('password.update');

Route::prefix('portal')->group(function () {
    Route::get('/state', [PortalController::class, 'state']);
    Route::post('/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:portal-login']);
    Route::post('/register', [AuthController::class, 'register'])->middleware(['guest', 'throttle:5,1']);
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware(['guest', 'throttle:5,1']);
    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::patch('/profile', [PortalController::class, 'profile'])->middleware('throttle:20,1');
        Route::post('/franchise-applications', [FranchiseApplicationController::class, 'store'])->middleware('throttle:5,1');
        Route::post('/transactions', [PortalController::class, 'transact'])->middleware('throttle:30,1');
        Route::prefix('admin')->middleware([EnsureAdministrator::class, 'throttle:60,1'])->group(function () {
            Route::get('/overview', [AdminController::class, 'overview']);
            Route::get('/users', [AdminController::class, 'users']);
            Route::get('/users/{user}', [AdminController::class, 'user']);
            Route::get('/requests', [AdminController::class, 'requests']);
            Route::post('/requests/{walletRequest}/review', [AdminController::class, 'review']);
            Route::post('/users/{user}/funds', [AdminController::class, 'funds']);
            Route::post('/users/{user}/plans', [AdminController::class, 'plans']);
            Route::get('/audit', [AdminController::class, 'audit']);
        });
    });
});
