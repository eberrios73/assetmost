import PasswordGate from '@/Components/ui/PasswordGate';

/**
 * PasswordGate as a modal overlay — for actions (reveal, copy, share) that hit a
 * 423 mid-flow, where swapping out the whole pane would lose the user's place.
 * Click outside or Escape cancels; the caller decides what a cancel means.
 */
export default function GateOverlay({ title = 'Reveal is protected', reason, onUnlocked, onCancel }) {
    return (
        <div className="fixed inset-0 z-[70] bg-black/40 flex items-center justify-center p-4"
            onClick={onCancel} onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
            <div className="w-full max-w-sm" onClick={(e) => e.stopPropagation()}>
                <PasswordGate title={title} reason={reason} onUnlocked={onUnlocked} />
            </div>
        </div>
    );
}
