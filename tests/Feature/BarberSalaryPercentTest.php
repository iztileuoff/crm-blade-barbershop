<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function salaryAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

function completedAppointment(Barber $barber, int $price, Carbon $startsAt): Appointment
{
    $client = Client::factory()->create();

    return Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'status' => AppointmentStatus::Completed,
        'price' => $price,
    ]);
}

it('snapshots the barber percent at the moment an appointment is completed', function () {
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(10, 0),
        'ends_at' => now()->setTime(11, 0),
        'status' => AppointmentStatus::Confirmed,
        'price' => 100000,
    ]);

    expect($appointment->salary_percent)->toBeNull();

    $appointment->update(['status' => AppointmentStatus::Completed]);

    expect($appointment->fresh()->salary_percent)->toBe(50);

    // Изменение процента мастера не пересчитывает уже завершённую запись.
    $barber->update(['salary_percent' => 90]);

    expect($appointment->fresh()->salary_percent)->toBe(50);
});

it('captures the percent when an appointment is created already completed', function () {
    $barber = Barber::factory()->create(['salary_percent' => 60, 'price' => 100000]);

    $appointment = completedAppointment($barber, 100000, now()->setTime(10, 0));

    expect($appointment->fresh()->salary_percent)->toBe(60);
});

it('uses the per-appointment percent in monthly stats when the percent changed mid-month', function () {
    $admin = salaryAdmin();
    $month = Carbon::now('Asia/Tashkent')->startOfMonth();
    $inMonth = $month->copy()->addDays(2)->setTime(12, 0);

    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);

    // Первая запись под старым процентом (50%).
    completedAppointment($barber, 100000, $inMonth->copy());

    // Мастеру меняют процент в середине месяца.
    $barber->update(['salary_percent' => 70]);

    // Вторая запись фиксирует новый процент (70%).
    completedAppointment($barber, 100000, $inMonth->copy()->addDays(1));

    $component = Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('month', $month->format('Y-m'));

    $instance = $component->instance();
    $stat = $instance->monthlyBarberStats()->firstWhere('id', $barber->id);

    // 100000 * 50% + 100000 * 70% = 120000, а не 200000 * 70% = 140000.
    expect($stat->salary)->toBe(120000)
        ->and($instance->monthlyTotalSalary())->toBe(120000);

    // Дальнейшее изменение процента не меняет прошлые расчёты.
    $barber->update(['salary_percent' => 90]);

    $fresh = Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('month', $month->format('Y-m'))
        ->instance();

    expect($fresh->monthlyTotalSalary())->toBe(120000);
});
