<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('default seeding is repeatable without creating demo login accounts', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(0);
});
