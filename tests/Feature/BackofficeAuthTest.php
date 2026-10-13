<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->staff = User::factory()->staff(PermissionCatalog::ADMIN)->create(['email' => 'admin@cect.example']);
});

function backofficeLogin(string $email = 'admin@cect.example', string $password = 'Password123'): TestResponse
{
    return test()->post('/backoffice/login', ['identifier' => $email, 'password' => $password]);
}

it('sends visitors to the sign-in page and never shows the dashboard to them', function () {
    $this->get('/backoffice')->assertRedirect(route('backoffice.login'));
    $this->get('/backoffice/login')->assertOk()->assertSee('Recevoir mon code de connexion');
});

it('signs a staff member in with the password and then the code received by e-mail', function () {
    backofficeLogin()->assertRedirect(route('backoffice.otp'));

    $this->get('/backoffice')->assertRedirect(route('backoffice.login'));
    $this->get('/backoffice/verification')->assertOk()->assertSee('a****@cect.example');

    $this->post('/backoffice/verification', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->get('/backoffice')->assertRedirect(route('backoffice.login'));

    $this->post('/backoffice/verification', ['code' => lastOtpCode()])->assertRedirect(route('backoffice.dashboard'));

    $this->get('/backoffice')->assertOk()->assertSee('Tableau de bord')->assertSee($this->staff->full_name);
    expect(SecurityEvent::query()->where('event_type', 'auth.login_succeeded')->where('user_id', $this->staff->id)->exists())->toBeTrue();

    $this->post('/backoffice/deconnexion')->assertRedirect(route('backoffice.login'));
    $this->get('/backoffice')->assertRedirect(route('backoffice.login'));
});

it('refuses a wrong password without revealing whether the account exists', function () {
    backofficeLogin(password: 'mauvais')->assertSessionHasErrors('identifier');
    $wrongPassword = session('errors')->first('identifier');

    backofficeLogin('inconnu@cect.example')->assertSessionHasErrors('identifier');
    $unknownAccount = session('errors')->first('identifier');

    expect($unknownAccount)->toBe($wrongPassword);
    Mail::assertNothingQueued();
});

it('keeps members out before any code is sent to them', function () {
    $member = User::factory()->create(['email' => 'membre@example.com']);

    backofficeLogin('membre@example.com')->assertSessionHasErrors('identifier');

    Mail::assertNothingQueued();
    expect(SecurityEvent::query()->where('event_type', 'backoffice.login_rejected')->exists())->toBeTrue();
    $this->get('/backoffice')->assertRedirect(route('backoffice.login'));
});

it('closes the session of an account that stops being active', function () {
    $this->actingAs($this->staff);
    $this->get('/backoffice')->assertOk();

    $this->staff->forceFill(['status' => UserStatus::Suspended])->save();

    $this->get('/backoffice')->assertRedirect(route('backoffice.login'));
});

it('does not let a member session open the back-office', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/backoffice')->assertRedirect(route('backoffice.login'));
});

it('sends strict security headers and no third-party origin', function () {
    $response = $this->actingAs($this->staff)->get('/backoffice')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toContain("script-src 'self'")->toContain("frame-ancestors 'none'")->not->toContain('http')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    expect($response->getContent())->not->toMatch('~<(script|link)[^>]+(src|href)="https?://(?!localhost)~')->not->toContain('googleapis');
});

it('asks for a step-up before a sensitive page and comes back to the page, only inside the back-office', function () {
    $this->actingAs($this->staff);

    $this->get('/backoffice/confirmation?return=https://evil.example')->assertOk()->assertSee('value="'.route('backoffice.dashboard').'"', false);
    $this->get('/backoffice/confirmation?return=//evil.example')->assertOk()->assertSee('value="'.route('backoffice.dashboard').'"', false);
    $this->get('/backoffice/confirmation?return=/backoffice/membres')->assertOk()->assertSee('value="/backoffice/membres"', false);

    $this->post('/backoffice/confirmation/envoyer', ['return' => '/backoffice/membres'])->assertRedirect();
    $this->post('/backoffice/confirmation', ['code' => '000000', 'return' => '/backoffice/membres'])->assertSessionHasErrors('code');
    $this->post('/backoffice/confirmation', ['code' => lastOtpCode(), 'return' => '/backoffice/membres'])->assertRedirect('/backoffice/membres');
});

it('shows each profile only what its permissions allow on the dashboard', function () {
    $this->actingAs(User::factory()->staff(PermissionCatalog::VALIDATOR)->create())
        ->get('/backoffice')
        ->assertOk()
        ->assertDontSee('Total collecté')
        ->assertDontSee('paiement(s) hors prestataire')
        ->assertSee('réception(s) / litige(s) YEM');

    $this->actingAs(User::factory()->staff(PermissionCatalog::SUPERADMIN)->create())
        ->get('/backoffice')
        ->assertOk()
        ->assertSee('Total collecté')
        ->assertSee('Santé de la plateforme');
});
