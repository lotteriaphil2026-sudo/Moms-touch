<?php

namespace App\Http\Controllers;

use App\Services\HomeScreenBonusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function claimHomeScreenBonus(
        Request $request,
        HomeScreenBonusService $bonuses,
        PortalController $portal,
    ): JsonResponse {
        abort_unless(!$request->user()->is_admin && !$request->user()->is_dummy, 403, 'This promotion is only available to active members.');
        abort_unless($request->header('X-Home-Screen-Standalone') === 'true', 403, 'Open Mom’s Touch from your Home Screen to claim this bonus.');

        $bonuses->claim($request->user());

        return response()->json($portal->payloadForUser($request->user()));
    }
}
