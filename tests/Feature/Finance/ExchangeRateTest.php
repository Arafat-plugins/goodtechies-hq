<?php

use App\Models\Employee;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Models\Setting;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\SettingsService;
use App\Support\FinanceCategoryKind;
use App\Support\ProjectStatus;
use App\Support\RoleName;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The USD→BDT rate (D7)
|--------------------------------------------------------------------------
|
| Every USD figure in the finance reports is converted to BDT at the stored
| rate: fetched from the live source in `auto` mode, the Admin's own figure
| in `manual` mode. No test here touches the network.
|
| Helpers are prefixed exchangeRate*, because Pest declares them globally.
|
*/

function exchangeRateService(): ExchangeRateService
{
    return app(ExchangeRateService::class);
}

function exchangeRateStored(string $key): mixed
{
    return Setting::query()->where('key', $key)->value('value');
}

beforeEach(function () {
    Http::preventStrayRequests();

    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $settings = app(SettingsService::class);
    $settings->set('usd_bdt_rate_mode', 'manual', $this->admin);
    $settings->set('usd_bdt_rate', '122.00', $this->admin);
});

it('converts USD to BDT in integer cents at the stored rate', function () {
    expect(exchangeRateService()->toBdt('350.00', 'USD'))->toBe('42700.00')
        ->and(exchangeRateService()->toBdt('0.01', 'USD'))->toBe('1.22')
        ->and(exchangeRateService()->toBdt('-10.00', 'USD'))->toBe('-1220.00');
})->group('finance');

it('passes BDT (and an unknown currency) through, normalised to two decimals', function () {
    expect(exchangeRateService()->toBdt('100', 'BDT'))->toBe('100.00')
        ->and(exchangeRateService()->toBdt('12.5', 'BDT'))->toBe('12.50')
        ->and(exchangeRateService()->toBdt('7.00', 'EUR'))->toBe('7.00');
})->group('finance');

it('stores the live rate on refresh in auto mode', function () {
    app(SettingsService::class)->set('usd_bdt_rate_mode', 'auto', $this->admin);

    Http::fake([ExchangeRateService::SOURCE => Http::response(['result' => 'success', 'rates' => ['BDT' => 119.876]])]);

    expect(exchangeRateService()->refresh())->toBeTrue()
        ->and(exchangeRateService()->rate())->toBe('119.88')
        ->and(exchangeRateStored('usd_bdt_rate'))->toBe('119.88')
        ->and(exchangeRateService()->updatedAt())->not->toBeNull();
})->group('finance');

it('keeps the old rate when the live source sends nonsense or fails', function () {
    app(SettingsService::class)->set('usd_bdt_rate_mode', 'auto', $this->admin);

    Http::fakeSequence(ExchangeRateService::SOURCE)
        ->push(['rates' => ['BDT' => 5]])
        ->push('down', 500);

    expect(exchangeRateService()->refresh())->toBeFalse()
        ->and(exchangeRateService()->refresh())->toBeFalse()
        ->and(exchangeRateService()->rate())->toBe('122.00');
})->group('finance');

it('does nothing on refresh in manual mode', function () {
    Http::fake();

    expect(exchangeRateService()->refresh())->toBeFalse()
        ->and(exchangeRateService()->rate())->toBe('122.00');

    Http::assertNothingSent();
})->group('finance');

it('lets the Admin set their own rate from the report screen, audited', function () {
    $this->actingAs($this->admin)
        ->from('/finance/report')
        ->put('/finance/exchange-rate', ['mode' => 'manual', 'rate' => '120.50'])
        ->assertRedirect('/finance/report')
        ->assertSessionHas('success', 'Exchange rate updated: 1 USD = 120.50 BDT.');

    expect(exchangeRateStored('usd_bdt_rate'))->toBe('120.50')
        ->and(exchangeRateStored('usd_bdt_rate_mode'))->toBe('manual')
        ->and(exchangeRateStored('usd_bdt_rate_updated_at'))->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['target_type' => 'setting', 'target_id' => Setting::where('key', 'usd_bdt_rate')->value('id'), 'actor_id' => $this->admin->getKey()]);
})->group('finance');

it('lets the Accountant change the rate too (finance.manage)', function () {
    $this->actingAs($this->accountant)
        ->put('/finance/exchange-rate', ['mode' => 'manual', 'rate' => '121.00'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(exchangeRateStored('usd_bdt_rate'))->toBe('121.00');
})->group('finance');

it('switches back to the live rate and refreshes it', function () {
    Http::fake([ExchangeRateService::SOURCE => Http::response(['rates' => ['BDT' => 123.45]])]);

    $this->actingAs($this->admin)
        ->put('/finance/exchange-rate', ['mode' => 'auto'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(exchangeRateStored('usd_bdt_rate_mode'))->toBe('auto')
        ->and(exchangeRateStored('usd_bdt_rate'))->toBe('123.45');
})->group('finance');

it('refuses a Manager and an out-of-range rate', function () {
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->actingAs($manager)
        ->put('/finance/exchange-rate', ['mode' => 'manual', 'rate' => '120.50'])
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->put('/finance/exchange-rate', ['mode' => 'manual', 'rate' => '20'])
        ->assertSessionHasErrors('rate');

    expect(exchangeRateStored('usd_bdt_rate'))->toBe('122.00');
})->group('finance');

it('shows the report in BDT: USD income and a USD-priced project converted at the rate', function () {
    $category = (int) FinanceCategory::query()->ofKind(FinanceCategoryKind::Income)->where('name', 'Maintenance')->firstOrFail()->getKey();

    foreach ([['100.00', 'BDT'], ['10.00', 'USD']] as [$amount, $currency]) {
        Income::create([
            'category_id' => $category,
            'recorded_by' => $this->accountant->getKey(),
            'amount' => $amount,
            'currency' => $currency,
            'date' => '2027-03-10',
            'notes' => null,
            'project_id' => null,
        ]);
    }

    $project = Project::factory()->create(['status' => ProjectStatus::Active, 'start_date' => '2027-03-01']);
    ProjectFinance::factory()->for($project)->create([
        'price' => null,
        'recurring_amount' => '350.00',
        'billing_frequency' => 'monthly',
        'hourly_rate' => null,
        'currency' => 'USD',
    ]);

    $props = $this->actingAs($this->admin)->get('/finance/report?month=2027-03')->assertOk()->inertiaProps();
    $row = collect($props['earnings']['rows'])->firstWhere('project.id', $project->getKey());

    expect($props['rollup']['income']['total'])->toBe('1320.00')
        ->and($row['expected'])->toBe('350.00')
        ->and($row['expected_bdt'])->toBe('42700.00')
        ->and($row['currency'])->toBe('USD');
})->group('finance');

it('schedules the rate refresh', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn (Event $event): string => (string) $event->command);

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'hq:refresh-exchange-rate')))->toBeTrue();
})->group('finance');
