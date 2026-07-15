import { useEffect, useState } from 'react';

export default function Field({ label, value }) {
    return (
        <div className="border-b border-gray-100 dark:border-gray-800 py-1.5">
            <dt className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">{label}</dt>
            <dd className="text-sm text-gray-800 dark:text-gray-200">{value || <span className="text-gray-300 dark:text-gray-600">—</span>}</dd>
        </div>
    );
}

/** Tiny fetch hook for tab content. */
export function useJson(url) {
    const [state, setState] = useState({ loading: true, data: null });
    useEffect(() => {
        let live = true;
        setState({ loading: true, data: null });
        fetch(url, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => live && setState({ loading: false, data: d }));
        return () => { live = false; };
    }, [url]);
    return state;
}
