<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthAndToolsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/tools/blacklist')->assertRedirect('/login');
        $this->get('/tools/provider')->assertRedirect('/login');
    }

    #[Test]
    public function admin_can_view_tools_after_login(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@mailin.test',
        ]);

        $this->actingAs($user)
            ->get('/tools/blacklist')
            ->assertOk();
    }

    #[Test]
    public function guests_cannot_run_checks(): void
    {
        $this->postJson('/checks/single', [
            'type' => 'blacklist',
            'input' => 'example.com',
        ])->assertUnauthorized();
    }
}
