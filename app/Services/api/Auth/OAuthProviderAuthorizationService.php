<?php

namespace App\Services\api\Auth;

class OAuthProviderAuthorizationService
{
    private const PROVIDERS = [
        'google' => [
            'url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'params' => [
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'access_type' => 'offline',
                'prompt' => 'select_account',
            ],
        ],
        'github' => [
            'url' => 'https://github.com/login/oauth/authorize',
            'params' => ['scope' => 'read:user user:email repo'],
        ],
        'gitlab' => [
            'url' => 'https://gitlab.com/oauth/authorize',
            'params' => ['response_type' => 'code', 'scope' => 'read_user'],
        ],
        'discord' => [
            'url' => 'https://discord.com/api/oauth2/authorize',
            'params' => ['response_type' => 'code', 'scope' => 'identify email'],
        ],
    ];

    public function isSupported(string $provider): bool
    {
        return array_key_exists($provider, self::PROVIDERS);
    }

    public function buildAuthorizationUrl(string $provider, ?string $state = null): ?string
    {
        if (! $this->isSupported($provider)) {
            return null;
        }

        $config = self::PROVIDERS[$provider];
        $params = array_merge($config['params'], [
            'client_id' => config("services.{$provider}.client_id"),
            'redirect_uri' => config("services.{$provider}.redirect"),
        ]);

        if ($state) {
            $params['state'] = $state;
        }

        return $config['url'] . '?' . http_build_query($params);
    }
}
