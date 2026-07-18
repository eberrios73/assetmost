<?php

namespace App\Support\Provisioning;

use App\Models\IdentityProvider;
use App\Models\Vendor;
use Illuminate\Support\Facades\Http;

/**
 * Zoom Server-to-Server OAuth provisioning.
 *
 * Config (Settings > Identity & integrations > Zoom):
 *   tenant_id  = Zoom Account ID
 *   client_id / client_secret = the Server-to-Server OAuth app's credentials
 *   (create one at marketplace.zoom.us — needs the user:write:admin scope)
 *
 * Creates the user with a Licensed seat (type 2). Zoom emails the person an
 * activation link — their password is set there, so the registry credential
 * for Zoom records the account's existence and login id, not a usable secret.
 */
class ZoomProvisioner implements Provisioner
{
    public function configKey(): string
    {
        return 'zoom';
    }

    public function supports(Vendor $vendor): bool
    {
        return (bool) preg_match('/\bzoom\b/i', $vendor->name);
    }

    public function provision(IdentityProvider $config, array $person): string
    {
        $token = Http::asForm()
            ->withBasicAuth($config->client_id, $config->client_secret)
            ->timeout(8)
            ->post('https://zoom.us/oauth/token', [
                'grant_type' => 'account_credentials',
                'account_id' => $config->tenant_id,
            ]);
        if (! $token->ok() || ! $token->json('access_token')) {
            throw new \RuntimeException('Zoom auth failed: ' . ($token->json('reason') ?? $token->status()));
        }

        $res = Http::withToken($token->json('access_token'))
            ->timeout(8)
            ->post('https://api.zoom.us/v2/users', [
                'action' => 'create',
                'user_info' => [
                    'email' => $person['email'],
                    'first_name' => $person['first'],
                    'last_name' => $person['last'],
                    'type' => 2,   // Licensed
                ],
            ]);

        if ($res->status() === 409) {
            return 'account already existed in Zoom — verified present';
        }
        if (! $res->created() && ! $res->ok()) {
            throw new \RuntimeException('Zoom user create failed: ' . ($res->json('message') ?? $res->status()));
        }

        return 'created with a Licensed seat; activation email sent to ' . $person['email'];
    }
}
