import { useState } from 'react';

/** Reusable tab bar. tabs = [{ key, label, count?, render: () => node }] */
export default function Tabs({ tabs, initial }) {
    const [active, setActive] = useState(initial || tabs[0]?.key);
    const current = tabs.find((t) => t.key === active) || tabs[0];
    return (
        <div className="flex flex-col min-h-0">
            <div className="flex gap-1 border-b border-gray-200 dark:border-gray-800 mb-4">
                {tabs.map((t) => {
                    const isActive = active === t.key;
                    return (
                        <button
                            key={t.key}
                            onClick={() => setActive(t.key)}
                            className={`flex items-center gap-1.5 px-4 py-2 text-sm font-medium border-b-2 -mb-px ${
                                isActive
                                    ? 'text-blue-600 dark:text-blue-400 border-blue-600 dark:border-blue-400'
                                    : 'text-gray-500 dark:text-gray-400 border-transparent hover:text-gray-700 dark:hover:text-gray-200'
                            }`}
                        >
                            {t.label}
                            {t.count != null && (
                                <span className={`text-xs rounded-full px-1.5 py-0.5 leading-none ${
                                    isActive
                                        ? 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300'
                                        : 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400'
                                }`}>{t.count}</span>
                            )}
                        </button>
                    );
                })}
            </div>
            <div className="flex-1 overflow-y-auto">{current?.render()}</div>
        </div>
    );
}
