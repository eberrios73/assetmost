/** AssetMost brand glyph: a bold little monitor. Screen = Asset blue, stand = Most charcoal. */
export default function BrandMark({ className = 'h-6 w-6' }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <rect x="2" y="3" width="20" height="14" rx="2.5" fill="#2563eb" />
            <rect x="5" y="6" width="14" height="2.2" rx="1.1" fill="#ffffff" opacity="0.9" />
            <rect x="5" y="10" width="9" height="2.2" rx="1.1" fill="#ffffff" opacity="0.55" />
            <rect x="10.5" y="17" width="3" height="2.6" fill="#111827" />
            <rect x="7" y="19.4" width="10" height="2.4" rx="1.2" fill="#111827" />
        </svg>
    );
}
