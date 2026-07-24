<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkeys: the whole app opens with a tap. Registration enrolls an
 * authenticator against the signed-in user; login verifies an assertion and
 * signs the user in (can_login and active still gate, exactly like the
 * password path). Passwords remain the fallback — this must never brick.
 *
 * The RP id is the request host, so localhost works in dev and the wildcard-
 * certified internal domain works in production.
 */
class WebAuthnController extends Controller
{
    private function rp(Request $request): PublicKeyCredentialRpEntity
    {
        return PublicKeyCredentialRpEntity::create(config('app.name', 'AssetMost'), $request->getHost());
    }

    private function serializer()
    {
        $manager = AttestationStatementSupportManager::create();
        $manager->add(NoneAttestationStatementSupport::create());
        return (new WebauthnSerializerFactory($manager))->create();
    }

    private function ceremony(Request $request)
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setSecuredRelyingPartyId([$request->getHost()]);   // allow http://localhost in dev
        return $factory;
    }

    /** Options for enrolling a new passkey — signed-in users only. */
    public function registerOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $options = PublicKeyCredentialCreationOptions::create(
            rp: $this->rp($request),
            user: PublicKeyCredentialUserEntity::create($user->email, (string) $user->id, trim("{$user->name} {$user->last}")),
            challenge: random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', -7),    // ES256
                PublicKeyCredentialParameters::create('public-key', -257),  // RS256
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
        );
        $json = $this->serializer()->serialize($options, 'json');
        $request->session()->put('webauthn.register', $json);
        return response()->json(json_decode($json, true));
    }

    /** Store the new credential after the browser ceremony. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'nullable|string|max:60', 'credential' => 'required|array']);
        $optionsJson = $request->session()->pull('webauthn.register');
        abort_unless($optionsJson, 422, 'No registration in progress.');

        $serializer = $this->serializer();
        $options = $serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
        $credential = $serializer->deserialize(json_encode($data['credential']), PublicKeyCredential::class, 'json');
        abort_unless($credential->response instanceof AuthenticatorAttestationResponse, 422);

        $validator = AuthenticatorAttestationResponseValidator::create(
            $this->ceremony($request)->creationCeremony()
        );
        $source = $validator->check($credential->response, $options, $request->getHost());

        $row = WebauthnCredential::create([
            'user_id' => $request->user()->id,
            'credential_id' => rtrim(strtr(base64_encode($source->publicKeyCredentialId), '+/', '-_'), '='),
            'public_key' => $serializer->serialize($source, 'json'),
            'name' => $data['name'] ?: 'Passkey '.Str::upper(Str::random(4)),
            'sign_count' => $source->counter,
        ]);
        return response()->json(['id' => $row->id, 'name' => $row->name], 201);
    }

    /** List + revoke, for the profile screen. */
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->hasMany(WebauthnCredential::class)->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'last_used_at' => $c->last_used_at?->toDateTimeString(), 'created_at' => $c->created_at->toDateString()]));
    }

    public function destroy(Request $request, WebauthnCredential $credential): JsonResponse
    {
        abort_unless($credential->user_id === $request->user()->id, 403);
        $credential->delete();
        return response()->json(['ok' => true]);
    }

    /** Options for login (or a sudo-gate re-auth): usernameless, discoverable. */
    public function loginOptions(Request $request): JsonResponse
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $request->getHost(),
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
        );
        $json = $this->serializer()->serialize($options, 'json');
        $request->session()->put('webauthn.login', $json);
        return response()->json(json_decode($json, true));
    }

    /** Verify the assertion; sign in (login) or unlock (sudo gate). */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['credential' => 'required|array', 'gate' => 'sometimes|boolean']);
        $optionsJson = $request->session()->pull('webauthn.login');
        abort_unless($optionsJson, 422, 'No login in progress.');

        $serializer = $this->serializer();
        $options = $serializer->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
        $credential = $serializer->deserialize(json_encode($data['credential']), PublicKeyCredential::class, 'json');
        abort_unless($credential->response instanceof AuthenticatorAssertionResponse, 422);

        $credId = rtrim(strtr(base64_encode($credential->rawId), '+/', '-_'), '=');
        $row = WebauthnCredential::query()->where('credential_id', $credId)->first();
        abort_unless($row, 404, 'Unknown passkey.');

        $source = $serializer->deserialize($row->public_key, PublicKeyCredentialSource::class, 'json');
        $validator = AuthenticatorAssertionResponseValidator::create(
            $this->ceremony($request)->requestCeremony()
        );
        $source = $validator->check($source, $credential->response, $options, $request->getHost(), null);

        $user = User::query()->where('id', $row->user_id)
            ->where('can_login', true)->where('active', true)->first();
        abort_unless($user, 403, 'This account cannot sign in.');

        $row->update(['sign_count' => $source->counter, 'last_used_at' => now()]);

        if ($request->boolean('gate')) {
            // The sudo gate: same session flag the password path sets.
            abort_unless(Auth::id() === $user->id, 403);
            $request->session()->put('auth.password_confirmed_at', time());
        } else {
            Auth::login($user, remember: true);
            $request->session()->regenerate();
        }
        return response()->json(['ok' => true, 'name' => $user->name]);
    }
}
