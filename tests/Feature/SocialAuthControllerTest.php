<?php

namespace Tests\Feature;

use Tests\TestCase;

class SocialAuthControllerTest extends TestCase
{
    public function test_google_redirect_fails_locally_when_oauth_config_uses_placeholders(): void
    {
        config([
            'services.google.client_id' => 'your-client-id',
            'services.google.client_secret' => 'your-client-secret',
            'services.google.redirect' => 'http://localhost:8081/auth/google/callback',
        ]);

        $this->get('/auth/google')
            ->assertRedirect('/login')
            ->assertSessionHasErrors([
                'msg' => 'Google login is not configured. Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, and GOOGLE_REDIRECT_URL.',
            ]);
    }
}
