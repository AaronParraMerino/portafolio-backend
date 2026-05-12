<?php

namespace App\Http\Controllers\Api\Auth;

class GitlabAuthController extends ProviderOAuthController
{
    protected function provider(): string
    {
        return 'gitlab';
    }
}
