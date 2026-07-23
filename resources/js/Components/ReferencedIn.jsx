import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Backlinks: every doc that @-mentions this record. The reference graph's
 * read side — the pill created the edge, this is where it pays off.
 * Renders nothing while empty so unreferenced records stay uncluttered.
 */
export default function ReferencedIn({ type, id }) {
    const [refs, setRefs] = useState([]);
    useEffect(() => {
        if (!id) return;
        fetch(`/data/refs?type=${type}&id=${id}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => setRefs(d.refs || []))
            .catch(() => setRefs([]));
    }, [type, id]);

    if (!refs.length) return null;
    return (
        <div className="mt-4">
            <span className="block text-xs font-medium uppercase tracking-wide text-gray-400 mb-1.5">Referenced in</span>
            <div className="flex flex-wrap gap-1.5">
                {refs.map((r) => (
                    <button key={`${r.type}-${r.id}`}
                        onClick={() => r.type === 'doc' && router.visit(`/docs?page=${r.id}`)}
                        className="rounded-full border border-violet-200 dark:border-violet-900 bg-violet-50 dark:bg-violet-500/10 px-2.5 py-0.5 text-xs font-medium text-violet-700 dark:text-violet-300 hover:border-violet-400">
                        {r.label}
                    </button>
                ))}
            </div>
        </div>
    );
}
