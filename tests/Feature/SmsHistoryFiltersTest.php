<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function historyAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('searches by client name and by the phone format the interface shows', function () {
    $ali = Client::factory()->create(['name' => 'Али', 'phone' => '998901234567']);
    $bek = Client::factory()->create(['name' => 'Бек', 'phone' => '998911112233']);

    SmsMessage::factory()->create(['client_id' => $ali->id, 'phone' => $ali->phone, 'message' => 'Привет Али']);
    SmsMessage::factory()->create(['client_id' => $bek->id, 'phone' => $bek->phone, 'message' => 'Привет Бек']);

    $page = Livewire::actingAs(historyAdmin())->test('pages.admin.sms.history');

    $page->set('search', 'Али');
    expect($page->instance()->messages->pluck('message')->all())->toBe(['Привет Али']);

    $page->set('search', '+998 91 111 22 33');
    expect($page->instance()->messages->pluck('message')->all())->toBe(['Привет Бек']);
});

it('finds a message sent to a phone with no client card', function () {
    SmsMessage::factory()->create(['client_id' => null, 'phone' => '998901234567', 'message' => 'Разовая рассылка']);

    $page = Livewire::actingAs(historyAdmin())
        ->test('pages.admin.sms.history')
        ->set('search', '901234567');

    expect($page->instance()->messages->pluck('message')->all())->toBe(['Разовая рассылка']);
});

it('filters by a date range', function () {
    SmsMessage::factory()->create(['message' => 'Июльская', 'created_at' => '2026-07-15 10:00:00']);
    SmsMessage::factory()->create(['message' => 'Августовская', 'created_at' => '2026-08-04 10:00:00']);

    $page = Livewire::actingAs(historyAdmin())->test('pages.admin.sms.history');

    $page->set('from', '2026-08-01');
    expect($page->instance()->messages->pluck('message')->all())->toBe(['Августовская']);

    $page->set('from', '')->set('to', '2026-07-31');
    expect($page->instance()->messages->pluck('message')->all())->toBe(['Июльская']);

    // Границы включительны: сообщение самого дня «до» обязано попасть в выборку.
    $page->set('from', '2026-08-04')->set('to', '2026-08-04');
    expect($page->instance()->messages->pluck('message')->all())->toBe(['Августовская']);
});

it('ignores a malformed date instead of crashing the audit page', function () {
    SmsMessage::factory()->create(['message' => 'Живое сообщение']);

    $page = Livewire::actingAs(historyAdmin())
        ->test('pages.admin.sms.history')
        ->set('from', 'не-дата')
        ->assertOk();

    expect($page->instance()->messages->total())->toBe(1);
});

it('resets the page and the filters', function () {
    SmsMessage::factory()->count(60)->create();

    $page = Livewire::actingAs(historyAdmin())
        ->test('pages.admin.sms.history')
        ->call('gotoPage', 3)
        ->assertSet('paginators.page', 3);

    $page->set('search', 'кто-нибудь')
        ->assertSet('paginators.page', 1);

    $page->set('status', 'sent')
        ->set('from', '2026-08-01')
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertSet('status', '')
        ->assertSet('from', '');

    expect($page->instance()->messages->total())->toBe(60);
});

it('says nothing was found instead of claiming no SMS were ever sent', function () {
    SmsMessage::factory()->create(['message' => 'Живое сообщение']);

    Livewire::actingAs(historyAdmin())
        ->test('pages.admin.sms.history')
        ->set('search', 'Никого')
        ->assertSee(__('common.nothing_found'))
        ->assertDontSee(__('sms.empty_history'));
});

it('states that the metrics ignore the filters', function () {
    SmsMessage::factory()->create();

    Livewire::actingAs(historyAdmin())
        ->test('pages.admin.sms.history')
        ->assertSee(__('sms.metrics_scope'));
});
