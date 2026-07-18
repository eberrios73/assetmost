<?php

namespace App\Support\Provisioning;

use App\Models\IdentityProvider;
use App\Models\Vendor;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Interprets a declarative plugin definition — a JSON field map:
 *
 * {
 *   "plugin_key": "okta", "name": "Okta", "matches": "okta",
 *   "auth": {
 *     "type": "header|bearer|basic|oauth2_client_credentials",
 *     ... per type: header {name,value}, oauth2 {token_url, params, basic}
 *   },
 *   "request": { "method": "POST", "url": "...", "body": { ...field map... } },
 *   "success": [200,201], "already_exists": [409],
 *   "error_path": "message"           // where the API hides its error text
 * }
 *
 * Placeholders anywhere in strings: {first} {last} {email} {username}
 * {config.domain} {config.tenant_id} {config.client_id} {config.client_secret}
 *
 * A definition can express exactly ONE request. That's the security model:
 * community plugins are readable at a glance and can't reach anything else.
 */
class JsonProvisioner implements Provisioner
{
    public function __construct(private array $def) {}

    public function configKey(): string
    {
        return $this->def['plugin_key'];
    }

    public function supports(Vendor $vendor): bool
    {
        $pattern = $this->def['matches'] ?? $this->def['plugin_key'];
        return (bool) @preg_match('/' . str_replace('/', '\/', $pattern) . '/i', $vendor->name);
    }

    public function provision(IdentityProvider $config, array $person): string
    {
        $sub = fn ($v) => is_string($v) ? strtr($v, [
            '{first}' => $person['first'] ?? '', '{last}' => $person['last'] ?? '',
            '{email}' => $person['email'] ?? '', '{username}' => $person['username'] ?? '',
            '{config.domain}' => $config->domain ?? '', '{config.tenant_id}' => $config->tenant_id ?? '',
            '{config.client_id}' => $config->client_id ?? '', '{config.client_secret}' => $config->client_secret ?? '',
        ]) : $v;
        $walk = function ($node) use (&$walk, $sub) {
            if (is_array($node)) return array_map($walk, $node);
            return $sub($node);
        };

        $http = $this->authed($config, $sub);
        $req = $this->def['request'] ?? [];
        $res = $http->timeout(8)->send(
            strtoupper($req['method'] ?? 'POST'),
            $sub($req['url'] ?? ''),
            ['json' => $walk($req['body'] ?? [])],
        );

        $status = $res->status();
        if (in_array($status, $this->def['already_exists'] ?? [], true)) {
            return 'account already existed — verified present';
        }
        if (in_array($status, $this->def['success'] ?? [200, 201], true)) {
            return $sub($this->def['success_message'] ?? 'created via API');
        }
        $err = $res->json($this->def['error_path'] ?? 'message') ?? $res->body();
        throw new \RuntimeException("API returned {$status}: " . mb_substr((string) $err, 0, 200));
    }

    private function authed(IdentityProvider $config, callable $sub): PendingRequest
    {
        $auth = $this->def['auth'] ?? ['type' => 'bearer'];
        return match ($auth['type'] ?? 'bearer') {
            'basic' => Http::withBasicAuth($config->client_id, $config->client_secret),
            'header' => Http::withHeaders([$auth['name'] => $sub($auth['value'])]),
            'oauth2_client_credentials' => (function () use ($auth, $config, $sub) {
                $tok = Http::asForm()
                    ->when($auth['basic'] ?? true, fn ($h) => $h->withBasicAuth($config->client_id, $config->client_secret))
                    ->timeout(8)
                    ->post($sub($auth['token_url']), array_map($sub, $auth['params'] ?? []));
                $access = $tok->json('access_token');
                if (! $access) {
                    throw new \RuntimeException('Auth failed: ' . ($tok->json('reason') ?? $tok->json('error_description') ?? $tok->status()));
                }
                return Http::withToken($access);
            })(),
            default => Http::withToken($config->client_secret),
        };
    }
}
