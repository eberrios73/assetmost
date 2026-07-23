<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Screenshot capture suite for the user guide and marketing site.
 *
 * Runs against the local dev server (:8000) and the Plutonic Games demo
 * dataset — never against real data. Read-only: navigates and shoots,
 * mutates nothing. Regenerate all doc images with:
 *
 *   php artisan dusk --filter=DocsScreenshotsTest
 *
 * PNGs land in tests/Browser/screenshots/.
 */
class DocsScreenshotsTest extends DuskTestCase
{
    protected function baseUrl(): string
    {
        return 'http://localhost:8000';
    }

    /** Click the leaf-most element whose trimmed text starts with $text. */
    private function clickText(Browser $browser, string $text): void
    {
        $browser->script(sprintf(
            '(() => {
                const txt = %s;
                const visible = e => e.getClientRects().length > 0;
                const depth = e => { let d = 0; while ((e = e.parentElement)) d++; return d; };
                let cands = [...document.querySelectorAll("body *")]
                    .filter(e => visible(e) && e.childElementCount === 0 && e.textContent.trim().startsWith(txt));
                if (!cands.length) {
                    cands = [...document.querySelectorAll("body *")]
                        .filter(e => visible(e) && e.textContent.trim().startsWith(txt))
                        .sort((a, b) => depth(b) - depth(a));
                }
                if (!cands.length) return 0;
                const el = cands[0];
                (el.closest("button, a, [role=button], [role=tab], li, tr") || el).click();
                return cands.length;
            })()',
            json_encode($text)
        ));
        $browser->pause(700);
    }

    public function test_capture_screens(): void
    {
        $this->browse(function (Browser $browser) {
            // Public login screen first, while still signed out.
            $browser->visit('/login')
                ->pause(1200)
                ->screenshot('01-login');

            $browser->loginAs(User::findOrFail(1));

            // People — Staff list with a person's detail + logins open.
            $browser->visit('/people')
                ->waitForText('Add Staff', 10)
                ->pause(800);
            $this->clickText($browser, 'Jensen Wattage');
            $browser->waitForText('jwattage@plutonicgames.com', 10)
                ->pause(500)
                ->screenshot('02-people-staff');

            // Same person, Licenses tab.
            $this->clickText($browser, 'Licenses');
            $browser->pause(600)->screenshot('03-people-licenses');

            // Accounts registry — locked (re-auth gate is the shot).
            $this->clickText($browser, 'Accounts');
            $browser->waitForText('This area is protected', 10)
                ->screenshot('04-accounts-locked');

            // Vendors with one selected.
            $this->clickText($browser, 'Vendors');
            $browser->waitForText('Adobe', 10);
            $this->clickText($browser, 'JetBrains');
            $browser->pause(600)->screenshot('05-vendors');

            // People > Onboarding tab.
            $this->clickText($browser, 'Onboarding');
            $browser->pause(1000)->screenshot('06-onboarding');

            // Assets — device list with one selected.
            $browser->visit('/assets')
                ->waitForText('Add Device', 10)
                ->pause(800);
            $this->clickText($browser, 'PG-LT-1007');
            $browser->pause(800)->screenshot('07-assets-devices');

            // Assets > Locations.
            $this->clickText($browser, 'Locations');
            $browser->pause(1000)->screenshot('08-assets-locations');

            // Tasks — weekly board.
            $browser->visit('/tasks')
                ->waitForText('CURRENT', 10)
                ->pause(800)
                ->screenshot('09-tasks');

            // Docs — SOP page open.
            $browser->visit('/docs')
                ->waitForText('Employee onboarding', 10)
                ->pause(500);
            $this->clickText($browser, 'Employee onboarding');
            $browser->pause(1200)->screenshot('10-docs-sop');

            // Companies.
            $browser->visit('/companies')
                ->pause(1200)
                ->screenshot('11-companies');

            // Settings.
            $browser->visit('/settings')
                ->pause(1200)
                ->screenshot('12-settings');

            // Add Staff modal (the one shared RecordModal). The People page
            // restores its last-active tab, so pin Staff first.
            $browser->visit('/people')->pause(800);
            $this->clickText($browser, 'Staff');
            $browser->waitForText('Add Staff', 10)->pause(500);
            $this->clickText($browser, 'Add Staff');
            $browser->pause(800)->screenshot('13-add-staff-modal');
            $browser->driver->getKeyboard()->sendKeys(\Facebook\WebDriver\WebDriverKeys::ESCAPE);
            $browser->pause(400);

            // Dark mode — People with detail open.
            $browser->script('document.documentElement.classList.add("dark"); localStorage.setItem("theme","dark");');
            $browser->visit('/people')->pause(800);
            $this->clickText($browser, 'Staff');
            $browser->waitForText('Add Staff', 10)->pause(600);
            $this->clickText($browser, 'Jensen Wattage');
            $browser->waitForText('jwattage@plutonicgames.com', 10)
                ->pause(500)
                ->screenshot('14-people-dark');
            $browser->script('document.documentElement.classList.remove("dark"); localStorage.setItem("theme","light");');

            $this->assertTrue(true);
        });
    }
}
