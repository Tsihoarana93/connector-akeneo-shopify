<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_user(): void
    {
        $this->artisan('fidelity:create-user', ['email' => 'ops@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'a-long-password')
            ->expectsOutput('User ops@example.com created.')
            ->assertSuccessful();

        $user = User::query()->where('email', 'ops@example.com')->sole();
        $this->assertSame('ops', $user->name);
        $this->assertTrue(Hash::check('a-long-password', $user->password));
    }

    public function test_it_resets_the_password_of_an_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.com', 'name' => 'Ops']);

        $this->artisan('fidelity:create-user', ['email' => 'ops@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'another-long-password')
            ->expectsOutput('Password of ops@example.com updated.')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame('Ops', $user->fresh()->name);
        $this->assertTrue(Hash::check('another-long-password', $user->fresh()->password));
    }

    public function test_it_rejects_a_short_password(): void
    {
        $this->artisan('fidelity:create-user', ['email' => 'ops@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'short')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
