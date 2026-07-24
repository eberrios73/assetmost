// Passkey ceremonies, browser side. Uses the modern JSON bridge APIs
// (parse*OptionsFromJSON / toJSON) — supported everywhere passkeys are.
const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
const json = (url, method = 'GET', body) => fetch(url, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
    body: body ? JSON.stringify(body) : undefined,
}).then(async (r) => (r.ok ? r.json() : Promise.reject(await r.json().catch(() => ({})))));

export const passkeysSupported = () =>
    typeof PublicKeyCredential !== 'undefined' && !!PublicKeyCredential.parseCreationOptionsFromJSON;

/** Enroll this device's authenticator for the signed-in user. */
export async function registerPasskey(name) {
    const options = PublicKeyCredential.parseCreationOptionsFromJSON(await json('/webauthn/register/options'));
    const cred = await navigator.credentials.create({ publicKey: options });
    return json('/webauthn/register', 'POST', { name, credential: cred.toJSON() });
}

/** Sign in (gate=false) or unlock the accounts registry (gate=true). */
export async function assertPasskey(gate = false) {
    const options = PublicKeyCredential.parseRequestOptionsFromJSON(await json('/webauthn/login/options'));
    const cred = await navigator.credentials.get({ publicKey: options });
    return json('/webauthn/login', 'POST', { credential: cred.toJSON(), gate });
}
