import BrandMark from '@/Components/BrandMark';

export default function ApplicationLogo({ className = '' }) {
    return (
        <span className={`inline-flex items-center gap-2.5 font-bold text-3xl tracking-tight ${className}`}>
            <BrandMark className="h-8 w-8" />
            <span className="inline-flex items-baseline">
                <span className="text-blue-600">Asset</span><span className="text-gray-900">Most</span>
            </span>
        </span>
    );
}
