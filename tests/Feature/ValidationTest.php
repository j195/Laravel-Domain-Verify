<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ValidationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function login_requires_email_and_password(): void
    {
        $this->from('/login')
            ->post('/login', [])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email', 'password']);
    }

    #[Test]
    public function login_rejects_invalid_email_format(): void
    {
        $this->from('/login')
            ->post('/login', [
                'email' => 'not-an-email',
                'password' => 'secret',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email']);
    }

    #[Test]
    public function login_rejects_unknown_credentials(): void
    {
        User::factory()->create([
            'email' => 'admin@mailin.test',
            'password' => 'Mailin@Admin123',
        ]);

        $this->from('/login')
            ->post('/login', [
                'email' => 'admin@mailin.test',
                'password' => 'wrong-password',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email']);
    }

    #[Test]
    public function single_check_requires_a_valid_domain_or_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/checks/single', [
                'type' => 'blacklist',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['input']);

        $this->actingAs($user)
            ->postJson('/checks/single', [
                'type' => 'blacklist',
                'input' => 'not a domain',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['input']);
    }

    #[Test]
    public function single_check_rejects_unknown_type_and_bad_dkim(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/checks/single', [
                'type' => 'whois',
                'input' => 'example.com',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);

        $this->actingAs($user)
            ->postJson('/checks/single', [
                'type' => 'blacklist',
                'input' => 'example.com',
                'dkim_selector' => 'bad selector!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dkim_selector']);
    }

    #[Test]
    public function bulk_check_requires_a_csv_or_txt_file(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/checks/bulk', [
                'type' => 'provider',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->actingAs($user)
            ->post('/checks/bulk', [
                'type' => 'provider',
                'file' => UploadedFile::fake()->create('domains.pdf', 12, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    #[Test]
    public function bulk_check_rejects_an_empty_list(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('empty.csv', "domain\n\n# comment\n");

        $this->actingAs($user)
            ->post('/checks/bulk', [
                'type' => 'blacklist',
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonFragment([
                'message' => 'The file contains no domains or email addresses.',
            ]);
    }
}
