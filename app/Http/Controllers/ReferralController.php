<?php

namespace App\Http\Controllers;

use App\Models\Master;
use App\Models\Referral;
use App\Models\ReferralEarning;
use App\Services\Referral\ReferralService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Реферальные роуты.
 *
 * Бизнес-правила не переопределяются: квалификация реферала и сумма
 * вознаграждения определяются существующим PaymentObserver'ом и
 * ReferralService, здесь только читаются их результаты.
 */
class ReferralController extends Controller
{
    /**
     * POST /api/referrals/attach
     *
     * Закрепляет текущего мастера (X-Master-Id) за владельцем
     * переданного referral-кода. Повторное создание привязки —
     * no-op (firstOrCreate в ReferralService + unique-индекс в БД).
     */
    public function attach(Request $request, ReferralService $referrals): JsonResponse
    {
        $current = $request->attributes->get('current_master');

        if (empty($current)) {
            return $this->noMaster();
        }

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $code = (string) $request->input('code');

        $referrer = Master::where('referral_code', $code)->first();

        if (empty($referrer)) {
            return response()->json([
                'message' => 'Referral code not found.',
            ], 404);
        }

        if ($referrer->id === $current->id) {
            return response()->json([
                'message' => 'Cannot attach to your own referral code.',
            ], 422);
        }

        try {
            $referral = $referrals->registerReferral($current, $code);
        } catch (QueryException $e) {
            // Гонка конкурентных запросов: unique(referred_master_id)
            // не дал вставить дубликат — берём уже созданную запись.
            $referral = Referral::where('referred_master_id', $current->id)->first();

            if (empty($referral)) {
                throw $e;
            }
        }

        $alreadyAttached = !$referral->wasRecentlyCreated;

        return response()->json([
            'data' => $this->referralPayload($referral),
            'already_attached' => $alreadyAttached,
        ], $alreadyAttached ? 200 : 201);
    }

    /**
     * GET /api/referrals/my
     *
     * Список мастеров, приведённых текущим мастером.
     * qualified — статус реферала (rewarded ставит PaymentObserver),
     * earned — сумма уже начисленных по нему referral_earnings.
     */
    public function my(Request $request): JsonResponse
    {
        $current = $request->attributes->get('current_master');

        if (empty($current)) {
            return $this->noMaster();
        }

        $referrals = Referral::where('referrer_master_id', $current->id)
            ->with('referredMaster:id,name')
            ->orderBy('created_at')
            ->get();

        $earnedByReferral = ReferralEarning::whereIn('referral_id', $referrals->pluck('id'))
            ->groupBy('referral_id')
            ->selectRaw('referral_id, SUM(amount) as earned_total')
            ->pluck('earned_total', 'referral_id');

        $data = $referrals->map(function (Referral $referral) use ($earnedByReferral) {
            $master = $referral->referredMaster;

            return [
                'master' => [
                    'id' => $master?->id,
                    'name' => $master?->name,
                ],
                'attached_at' => $referral->created_at,
                'qualified' => $referral->status === Referral::STATUS_REWARDED,
                'earned' => (int) ($earnedByReferral[$referral->id] ?? 0),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/referrals/earnings
     *
     * Финансовая сводка по начислениям текущего мастера.
     * Суммы читаются из referral_earnings — их писал PaymentObserver
     * через ReferralService::rewardAmount(), здесь ничего не считается.
     */
    public function earnings(Request $request): JsonResponse
    {
        $current = $request->attributes->get('current_master');

        if (empty($current)) {
            return $this->noMaster();
        }

        $earnings = ReferralEarning::where('referrer_master_id', $current->id);

        $total = (int) (clone $earnings)->sum('amount');
        $pending = (int) (clone $earnings)
            ->where('status', ReferralEarning::STATUS_PENDING)
            ->sum('amount');
        $paid = (int) (clone $earnings)
            ->where('status', ReferralEarning::STATUS_PAID)
            ->sum('amount');

        $qualified = Referral::where('referrer_master_id', $current->id)
            ->where('status', Referral::STATUS_REWARDED)
            ->count();

        return response()->json([
            'total_earned' => $total,
            'pending' => $pending,
            'paid' => $paid,
            'qualified_referrals' => $qualified,
        ]);
    }

    private function referralPayload(Referral $referral): array
    {
        return [
            'id' => $referral->id,
            'referrer_master_id' => $referral->referrer_master_id,
            'referred_master_id' => $referral->referred_master_id,
            'status' => $referral->status,
            'attached_at' => $referral->created_at,
        ];
    }

    private function noMaster(): JsonResponse
    {
        return response()->json([
            'message' => 'Current master not found (check X-Master-Id header).',
        ], 401);
    }
}
