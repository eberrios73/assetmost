<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Captures the three demo intake forms (site/forms-demo/) filled with
 * sample data, preview panel open, for the site's intake carousel.
 *
 * Needs the static site served on :8801:
 *   php -S localhost:8801 -t site
 * Then:
 *   php artisan dusk --filter=FormShotsTest
 */
class FormShotsTest extends DuskTestCase
{
    protected function baseUrl(): string
    {
        return 'http://localhost:8801';
    }

    public function test_capture_forms(): void
    {
        $variants = [
            'meridian' => [
                'first' => 'Jordan', 'last' => 'Reyes', 'position' => 'Paralegal',
                'start' => '2026-08-03', 'cell' => '(555) 014-2288', 'ext' => '412',
                'distro' => 'Staff LA; Litigation', 'attorneys' => 'T. Marlowe; D. Finch',
                'requestedby' => 'Office Manager',
            ],
            'plutonic' => [
                'first' => 'Ada', 'last' => 'LoveLDAP', 'title' => 'UI Artist',
                'start' => '2026-08-10', 'dept' => 'Art', 'workstation' => 'Mac laptop',
                'floating' => 'artist003; render-farm', 'requestedby' => 'Grace Hotspot',
            ],
            'northlake' => [
                'first' => 'Priya', 'last' => 'Raman', 'role' => 'Front desk',
                'start' => '2026-08-17', 'badge' => '0441', 'location' => 'Lakeview office',
                'ehr' => 'Scheduling only', 'requestedby' => 'Practice Manager',
            ],
        ];

        $this->browse(function (Browser $browser) use ($variants) {
            $browser->resize(940, 1500);
            foreach ($variants as $name => $fill) {
                $browser->visit('/forms-demo/' . $name . '.html')->pause(600);
                foreach ($fill as $id => $value) {
                    $browser->script(sprintf(
                        'document.getElementById("in-%s").value = %s;',
                        $id, json_encode($value)
                    ));
                }
                // open the structured-email preview, then shoot
                $browser->script('document.getElementById("copyBtn").click();');
                $browser->pause(500)->screenshot('form-' . $name);
            }
            $this->assertTrue(true);
        });
    }
}
