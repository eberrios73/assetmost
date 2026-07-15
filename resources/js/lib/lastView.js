// Remembers the last-viewed record (and sub-tab) per section, so returning to a
// screen restores where you were. Client-only, survives navigation and reloads.
const KEY = 'assetmost:lastview';

const read = () => { try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch { return {}; } };
const write = (o) => { try { localStorage.setItem(KEY, JSON.stringify(o)); } catch { /* quota / private mode */ } };

export const getLast = (scope) => read()[scope] ?? null;

export const setLast = (scope, value) => {
    const o = read();
    if (value === null || value === undefined) delete o[scope];
    else o[scope] = value;
    write(o);
};
