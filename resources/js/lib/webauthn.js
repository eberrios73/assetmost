// Passkey ceremonies, browser side. Prefers the modern JSON bridge APIs and
// falls back to manual base64url plumbing where they're missing (Safari got
// them late) — support is decided by PublicKeyCredential itself, so the
// feature never hides on a capable browser.
const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
const json = (url, method = 'GET', body) => fetch(url, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
    body: body ? JSON.stringify(body) : undefined,
}).then(async (r) => (r.ok ? r.json() : Promise.reject(await r.json().catch(() => ({})))));

export const passkeysSupported = () => typeof PublicKeyCredential !== 'undefined';

const toBuf = (s) => {
    const pad = s.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(s.length / 4) * 4, '=');
    return Uint8Array.from(atob(pad), (c) => c.charCodeAt(0)).buffer;
};
const toB64u = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

const parseCreate = (o) => PublicKeyCredential.parseCreationOptionsFromJSON
    ? PublicKeyCredential.parseCreationOptionsFromJSON(o)
    : { ...o, challenge: toBuf(o.challenge), user: { ...o.user, id: toBuf(o.user.id) },
        excludeCredentials: (o.excludeCredentials || []).map((c) => ({ ...c, id: toBuf(c.id) })) };
const parseGet = (o) => PublicKeyCredential.parseRequestOptionsFromJSON
    ? PublicKeyCredential.parseRequestOptionsFromJSON(o)
    : { ...o, challenge: toBuf(o.challenge),
        allowCredentials: (o.allowCredentials || []).map((c) => ({ ...c, id: toBuf(c.id) })) };
const credJson = (cred) => {
    if (cred.toJSON) return cred.toJSON();
    const r = cred.response;
    const out = {
        id: cred.id, rawId: toB64u(cred.rawId), type: cred.type,
        clientExtensionResults: cred.getClientExtensionResults ? cred.getClientExtensionResults() : {},
        authenticatorAttachment: cred.authenticatorAttachment ?? null,
        response: { clientDataJSON: toB64u(r.clientDataJSON) },
    };
    if (r.attestationObject) {
        out.response.attestationObject = toB64u(r.attestationObject);
        if (r.getTransports) out.response.transports = r.getTransports();
    }
    if (r.authenticatorData) {
        out.response.authenticatorData = toB64u(r.authenticatorData);
        out.response.signature = toB64u(r.signature);
        out.response.userHandle = r.userHandle ? toB64u(r.userHandle) : null;
    }
    return out;
};

/** Enroll this device's authenticator for the signed-in user. */
export async function registerPasskey(name) {
    const options = parseCreate(await json('/webauthn/register/options'));
    const cred = await navigator.credentials.create({ publicKey: options });
    return json('/webauthn/register', 'POST', { name, credential: credJson(cred) });
}

/** Sign in (gate=false) or unlock the accounts registry (gate=true). */
export async function assertPasskey(gate = false) {
    const options = parseGet(await json('/webauthn/login/options'));
    const cred = await navigator.credentials.get({ publicKey: options });
    return json('/webauthn/login', 'POST', { credential: credJson(cred), gate });
}
