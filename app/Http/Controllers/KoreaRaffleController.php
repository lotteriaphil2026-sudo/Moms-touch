<?php

namespace App\Http\Controllers;

use App\Services\KoreaRaffleService;
use Illuminate\Http\{JsonResponse, Request};

class KoreaRaffleController extends Controller
{
    public function status(Request $request, KoreaRaffleService $raffle): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            $today = now(KoreaRaffleService::TIMEZONE)->toDateString();

            return response()->json([
                'csrf' => csrf_token(),
                'authenticated' => false,
                'eligible' => false,
                'phase' => $raffle->phase($today),
                'today' => $today,
                'claimedToday' => false,
                'message' => 'Sign in to your member account to claim raffle tickets.',
                'tickets' => [],
            ]);
        }

        return response()->json(['csrf' => csrf_token(), ...$raffle->status($user)]);
    }

    public function claim(Request $request, KoreaRaffleService $raffle): JsonResponse
    {
        $user = $request->user();
        $ticket = $raffle->claim($user);

        return response()->json([
            'ticket' => [
                'id' => $ticket->ticket_code,
                'date' => $ticket->claim_date->format('Y-m-d'),
            ],
            ...$raffle->status($user),
        ], 201);
    }
}
