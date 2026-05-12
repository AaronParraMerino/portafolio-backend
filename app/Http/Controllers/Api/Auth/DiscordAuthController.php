<?php

namespace App\Http\Controllers\Api\Auth;

class DiscordAuthController extends ProviderOAuthController
{
    protected function provider(): string
    {
        return 'discord';
    }
}
