<?php

namespace App\Support;

/**
 * Maps the messy raw `devices.type` values onto a clean, curated category set
 * for the type filter (replaces the 63 junk/duplicate raw values).
 */
class DeviceCategory
{
    /** category => raw type values (lowercased, trimmed) that belong to it. */
    public const MAP = [
        'Workstation' => ['workstation'],
        'Laptop'      => ['laptop'],
        'Monitor'     => ['monitor'],
        'Server'      => ['server'],
        'Storage'     => ['storage', 'storage nas', 'external hard drive', 'external hard drive ssd',
            'hard drive', 'hard drive 1tb x 3', 'spare hard drives', 'ssd drive', 'hdd', 'g-drive 2tb',
            'startup disk', 'external dock', 'thunderbolt dock statioon', 'external optical drive',
            'external encrypted hd', 'external encrypted hd tm', 'external encrypted hd 1tb',
            'external time machine encypted'],
        'Network'     => ['switch', 'wifi', 'hot spot', 'airport extreme', 'airport express',
            'firewall / router', 'fiber sfp x 6', 'kvm'],
        'Printer'     => ['printer', 'scanner'],
        'Phone'       => ['iphone', 'phone', 'polycom', 'fax'],
        'Tablet'      => ['tablet', 'ipad', 'writing tablet', 'graphic tablet', 'cintiq', 'iwatch'],
        'UPS'         => ['ups', 'ups 006'],
        'Camera / AV' => ['camera', 'analog / digital capture', 'video card', 'speakers', 'mixer board',
            'microphone', 'lens', 'motion capture suit', 'motion capture gloves', 'calibration', 'apple tv'],
        'Peripheral'  => ['peripheral', 'dongle', 'keyboard', 'power supply', 'game console', 'thremostat'],
    ];

    /** Ordered clean category names. */
    public static function all(): array
    {
        return array_keys(self::MAP);
    }

    /** Raw type values for a category (empty if unknown). */
    public static function rawTypesFor(string $category): array
    {
        return self::MAP[$category] ?? [];
    }
}
