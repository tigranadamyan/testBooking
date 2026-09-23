<?php

namespace Tests\Feature;

use App\Models\Master;
use App\Models\Referral;
use App\Models\ReferralEarning;
use App\Services\Referral\ReferralService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E2E-тесты трёх реферальных роутов.
 *
 * Опорные данные — сид DatabaseSeeder:
 *   Маша (MASHA10) привела Иру, Олю, Катю и Дашу.
 *   Ира:  card 3000 (квалифицируется, начисление created PaymentObserver'ом).
 *   Оля:  promo 0   — не денежный платёж, pending.
 *   Катя: trial 0   — не денежный платёж, pending.
 *   Даша: card 0, затем card 2000 — card-платёж на 0 уже занят
 *         счётчик monetary() (считает по типу!), поэтому НЕ квалифицируется.
 */
class ReferralApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function masha(): Master
    {
        return Master::where('referral_code', 'MASHA10')->firstOrFail();
    }

    private function masterByName(string $name): Master
    {
        return Master::where('name', $name)->firstOrFail();
    }

    private function newMaster(string $name = 'Света'): Master
    {
        return Master::create(['name' => $name, 'referral_code' => strtoupper($name).'77']);
    }

    // ---------------------------------------------------------------
    // POST /api/referrals/attach
    // ---------------------------------------------------------------

    public function test_attach_success(): void
    {
        $master = $this->newMaster();
        $masha = $this->masha();

        $response = $this->postJson('/api/referrals/attach', ['code' => 'MASHA10'], [
            'X-Master-Id' => (string) $master->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('already_attached', false)
            ->assertJsonPath('data.referrer_master_id', $masha->id)
            ->assertJsonPath('data.referred_master_id', $master->id)
            ->assertJsonPath('data.status', Referral::STATUS_PENDING);

        $this->assertDatabaseHas('referrals', [
            'referrer_master_id' => $masha->id,
            'referred_master_id' => $master->id,
        ]);
    }

    public function test_attach_repeat_does_not_create_duplicate(): void
    {
        $master = $this->newMaster();

        $headers = ['X-Master-Id' => (string) $master->id];

        $this->postJson('/api/referrals/attach', ['code' => 'MASHA10'], $headers)
            ->assertStatus(201);

        $second = $this->postJson('/api/referrals/attach', ['code' => 'MASHA10'], $headers);

        $second->assertStatus(200)
            ->assertJsonPath('already_attached', true);

        $this->assertSame(
            1,
            Referral::where('referred_master_id', $master->id)->count(),
            'Повторный attach не должен создавать вторую привязку'
        );
    }

    public function test_attach_repeat_with_different_code_keeps_original_referrer(): void
    {
        $master = $this->newMaster();
        $masha = $this->masha();
        $headers = ['X-Master-Id' => (string) $master->id];

        $this->postJson('/api/referrals/attach', ['code' => 'MASHA10'], $headers)->assertStatus(201);

        // Повторная попытка с чужим кодом не перепривязывает.
        $this->postJson('/api/referrals/attach', ['code' => 'LENA77'], $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.referrer_master_id', $masha->id);

        $this->assertSame(1, Referral::where('referred_master_id', $master->id)->count());
        $this->assertDatabaseHas('referrals', [
            'referrer_master_id' => $masha->id,
            'referred_master_id' => $master->id,
        ]);
    }

    public function test_attach_to_self_is_forbidden(): void
    {
        $masha = $this->masha();

        $response = $this->postJson('/api/referrals/attach', ['code' => 'MASHA10'], [
            'X-Master-Id' => (string) $masha->id,
        ]);

        $response->assertStatus(422);

        $this->assertSame(
            0,
            Referral::where('referred_master_id', $masha->id)->count(),
            'За себя закрепиться нельзя'
        );
    }

    public function test_attach_unknown_code_returns_404(): void
    {
        $master = $this->newMaster();

        $this->postJson('/api/referrals/attach', ['code' => 'NOPE99'], [
            'X-Master-Id' => (string) $master->id,
        ])->assertStatus(404);

        $this->assertSame(0, Referral::where('referred_master_id', $master->id)->count());
    }

    public function test_attach_missing_code_returns_422(): void
    {
        $master = $this->newMaster();

        $this->postJson('/api/referrals/attach', [], [
            'X-Master-Id' => (string) $master->id,
        ])->assertStatus(422);

        $this->postJson('/api/referrals/attach', ['code' => ''], [
            'X-Master-Id' => (string) $master->id,
        ])->assertStatus(422);
    }

    public function test_attach_without_master_header_returns_401(): void
    {
        $this->postJson('/api/referrals/attach', ['code' => 'MASHA10'])
            ->assertStatus(401);

        // Заголовок есть, но такого мастера нет.
        $this->postJson('/api/referrals/attach', ['code' => 'MASHA10'], ['X-Master-Id' => '99999'])
            ->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // GET /api/referrals/my
    // ---------------------------------------------------------------

    public function test_my_returns_referred_masters_with_status_and_earned(): void
    {
        $masha = $this->masha();

        $response = $this->getJson('/api/referrals/my', ['X-Master-Id' => (string) $masha->id]);

        $response->assertStatus(200);
        $this->assertCount(4, $response->json('data'));

        $byName = collect($response->json('data'))->keyBy('master.name');

        // Ира: первый денежный платёж 3000 → квалифицирована,
        // начисление = rewardAmount(3000) (пишет PaymentObserver).
        $ira = $byName['Ира'];
        $this->assertTrue($ira['qualified']);
        $this->assertSame(
            $this->app->make(ReferralService::class)->rewardAmount(3000),
            $ira['earned']
        );
        $this->assertSame(30000, $ira['earned'], 'Сумма берётся из referral_earnings (наблюдатель: 3000 × 10)');
        $this->assertNotNull($ira['attached_at']);

        // Оля (promo) и Катя (trial) — не денежные платежи.
        $this->assertFalse($byName['Оля']['qualified']);
        $this->assertSame(0, $byName['Оля']['earned']);

        $this->assertFalse($byName['Катя']['qualified']);
        $this->assertSame(0, $byName['Катя']['earned']);

        // Даша: card 0 «сжёг» счётчик monetary() (считает по типу card/sbp),
        // поэтому даже настоящая оплата 2000 НЕ квалифицирует реферал.
        $this->assertFalse(
            $byName['Даша']['qualified'],
            'Правило системы: 0-amount card-платёж занимает первую monetary-позицию'
        );
        $this->assertSame(0, $byName['Даша']['earned']);
    }

    public function test_my_returns_master_id_and_name(): void
    {
        $masha = $this->masha();
        $ira = $this->masterByName('Ира');

        $response = $this->getJson('/api/referrals/my', ['X-Master-Id' => (string) $masha->id]);

        $iraRow = collect($response->json('data'))->firstWhere('master.id', $ira->id);

        $this->assertNotNull($iraRow);
        $this->assertSame('Ира', $iraRow['master']['name']);
    }

    public function test_my_empty_when_no_referrals(): void
    {
        $nobody = $this->newMaster('НеРеферер');

        $this->getJson('/api/referrals/my', ['X-Master-Id' => (string) $nobody->id])
            ->assertStatus(200)
            ->assertExactJson(['data' => []]);
    }

    public function test_my_without_master_header_returns_401(): void
    {
        $this->getJson('/api/referrals/my')->assertStatus(401);
    }

    public function test_my_earned_zero_when_referral_has_no_payments(): void
    {
        // Новый реферер приводит мастера без платежей.
        $referrer = $this->newMaster('Ольга2');
        $referred = $this->newMaster('Новичок');

        $this->postJson('/api/referrals/attach', ['code' => $referrer->referral_code], [
            'X-Master-Id' => (string) $referred->id,
        ])->assertStatus(201);

        $response = $this->getJson('/api/referrals/my', ['X-Master-Id' => (string) $referrer->id]);

        $response->assertStatus(200);
        $row = $response->json('data.0');

        $this->assertFalse($row['qualified']);
        $this->assertSame(0, $row['earned']);
    }

    // ---------------------------------------------------------------
    // GET /api/referrals/earnings
    // ---------------------------------------------------------------

    public function test_earnings_summary_matches_seeded_system_state(): void
    {
        $masha = $this->masha();

        $response = $this->getJson('/api/referrals/earnings', [
            'X-Master-Id' => (string) $masha->id,
        ]);

        $response->assertStatus(200)->assertExactJson([
            // Единственное начисление: Ира, 3000 × 10, статус pending.
            'total_earned' => 30000,
            'pending' => 30000,
            'paid' => 0,
            // Засчитан ровно один реферал (Ира).
            'qualified_referrals' => 1,
        ]);
    }

    public function test_earnings_reflect_paid_and_pending_split(): void
    {
        $referrer = $this->newMaster('Бухгалтер');
        $referred = $this->newMaster('Плательщик');

        $this->postJson('/api/referrals/attach', ['code' => $referrer->referral_code], [
            'X-Master-Id' => (string) $referred->id,
        ])->assertStatus(201);

        // Денежный платёж → observer квалифицирует и создаёт начисление.
        \App\Models\Payment::create([
            'master_id' => $referred->id,
            'amount' => 500,
            'type' => \App\Models\Payment::TYPE_CARD,
        ]);

        $earning = ReferralEarning::where('referrer_master_id', $referrer->id)->firstOrFail();
        $this->assertSame(
            $this->app->make(ReferralService::class)->rewardAmount(500),
            $earning->amount
        );

        $expectedTotal = $earning->amount;

        // Часть начисления закрыта как выплата.
        $earning->update(['status' => ReferralEarning::STATUS_PAID]);
        $paidPart = (int) floor($expectedTotal / 3);
        // Пересчитаем вручную: одна строка pending на всё, разбиваем на две.
        $earning->update(['status' => ReferralEarning::STATUS_PENDING, 'amount' => $paidPart]);
        ReferralEarning::create([
            'referrer_master_id' => $referrer->id,
            'referred_master_id' => $referred->id,
            'referral_id' => $earning->referral_id,
            'payment_id' => $earning->payment_id,
            'payment_amount' => $earning->payment_amount,
            'amount' => $expectedTotal - $paidPart,
            'percent' => $earning->percent,
            'status' => ReferralEarning::STATUS_PAID,
        ]);

        $this->getJson('/api/referrals/earnings', [
            'X-Master-Id' => (string) $referrer->id,
        ])->assertStatus(200)->assertExactJson([
            'total_earned' => $expectedTotal,
            'pending' => $paidPart,
            'paid' => $expectedTotal - $paidPart,
            'qualified_referrals' => 1,
        ]);
    }

    public function test_earnings_zeros_without_referrals(): void
    {
        $nobody = $this->newMaster('Ноль');

        $this->getJson('/api/referrals/earnings', [
            'X-Master-Id' => (string) $nobody->id,
        ])->assertStatus(200)->assertExactJson([
            'total_earned' => 0,
            'pending' => 0,
            'paid' => 0,
            'qualified_referrals' => 0,
        ]);
    }

    public function test_earnings_without_master_header_returns_401(): void
    {
        $this->getJson('/api/referrals/earnings')->assertStatus(401);
    }
}
