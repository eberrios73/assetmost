import { PlusIcon } from '@/Components/Icons';

/**
 * THE add button. Every "add a thing" control in the app is this component —
 * same size, same color, same icon. If a screen needs to add something, it
 * renders <AddButton>, not its own styling.
 */
export default function AddButton({ label, onClick }) {
    return (
        <button type="button" onClick={onClick}
            className="inline-flex items-center gap-1 rounded-md bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700">
            <PlusIcon /> {label}
        </button>
    );
}
