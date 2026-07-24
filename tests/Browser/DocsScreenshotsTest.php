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

    /** Like clickText, but fires a full pointer/mouse event sequence. */
    private function clickHard(Browser $browser, string $text): void
    {
        $browser->script(sprintf(
            '(() => {
                const txt = %s;
                const visible = e => e.getClientRects().length > 0;
                const leafs = [...document.querySelectorAll("body *")]
                    .filter(e => visible(e) && e.childElementCount === 0 && e.textContent.trim().startsWith(txt));
                if (!leafs.length) return 0;
                const el = leafs[0];
                ["pointerdown","mousedown","pointerup","mouseup","click"].forEach(tp =>
                    el.dispatchEvent(new MouseEvent(tp, {bubbles: true, cancelable: true, view: window})));
                return leafs.length;
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
            $this->clickText($browser, 'Justin Sudo');
            $browser->waitForText('jsudo@plutonicgames.com', 10)
                ->pause(500)
                ->screenshot('02-people-staff');

            // Same person, Licenses tab.
            $this->clickText($browser, 'Licenses');
            $browser->pause(600)->screenshot('03-people-licenses');

            // Same person, Devices tab.
            $this->clickText($browser, 'Devices');
            $browser->pause(600)->screenshot('15-person-devices');

            // + Add login drawer (peek, then close).
            $this->clickText($browser, 'Logins');
            $browser->pause(500);
            $this->clickText($browser, 'Add login');
            $browser->pause(900)->screenshot('19-add-login-drawer');
            $browser->driver->getKeyboard()->sendKeys(\Facebook\WebDriver\WebDriverKeys::ESCAPE);
            $browser->pause(500);

            // Accounts registry — locked (re-auth gate is the shot).
            $this->clickText($browser, 'Accounts');
            $browser->waitForText('This area is protected', 10)
                ->screenshot('04-accounts-locked');

            // Unlock the registry (demo fixture credentials) and shoot the account list.
            $browser->script('(function(){var el=document.querySelector("input[type=password]");if(!el)return;var set=Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype,"value").set;set.call(el,"password");el.dispatchEvent(new Event("input",{bubbles:true}));})();');
            $browser->pause(300);
            $this->clickText($browser, 'Unlock');
            $browser->pause(1200);
            $this->clickText($browser, 'artist003');
            $browser->pause(600)->screenshot('21-accounts-registry');

            // Vendors with one selected.
            $this->clickText($browser, 'Vendors');
            $browser->waitForText('Adobe', 10);
            $this->clickText($browser, 'JetBrains');
            $browser->pause(600)->screenshot('05-vendors');

            // A vendor with a product catalog.
            $this->clickText($browser, 'Adobe');
            $browser->pause(600);
            $this->clickText($browser, 'Products');
            $browser->pause(700)->screenshot('20-vendor-products');

            // People > Onboarding tab.
            $this->clickText($browser, 'Onboarding');
            $browser->pause(1000)->screenshot('06-onboarding');

            // Assets — device list with one selected.
            $browser->visit('/assets')
                ->waitForText('Add Device', 10)
                ->pause(800);
            $this->clickText($browser, 'PG-LT-1007');
            $browser->pause(800)->screenshot('07-assets-devices');

            // Device > Assigned Users.
            $this->clickText($browser, 'Assigned Users');
            $browser->pause(700)->screenshot('24-device-users');

            // + Add Device modal (peek, then close).
            $this->clickText($browser, 'Add Device');
            $browser->pause(900)->screenshot('25-add-device-modal');
            $browser->driver->getKeyboard()->sendKeys(\Facebook\WebDriver\WebDriverKeys::ESCAPE);
            $browser->pause(500);

            // Assets > Locations.
            $this->clickText($browser, 'Locations');
            $browser->pause(1000)->screenshot('08-assets-locations');

            // Location detail with rooms.
            $this->clickText($browser, 'Burbank Studio');
            $browser->pause(700)->screenshot('23-location-rooms');

            // Assets > Onboarding (machine intake).
            $this->clickText($browser, 'Onboarding');
            $browser->pause(1000)->screenshot('22-assets-onboarding');

            // Tasks — weekly board.
            $browser->visit('/tasks')
                ->waitForText('CURRENT', 10)
                ->pause(800)
                ->screenshot('09-tasks');

            // Tasks > Projects (the Project > Subproject > Milestone > Task ladder).
            $this->clickHard($browser, 'projects');
            $browser->pause(1000)->screenshot('16-tasks-projects');

            // Tasks > Timeline.
            $this->clickHard($browser, 'timeline');
            $browser->pause(1000)->screenshot('17-tasks-timeline');

            // Docs — SOP page open.
            $browser->visit('/docs')
                ->waitForText('Employee onboarding', 10)
                ->pause(500);
            $this->clickText($browser, 'Employee onboarding');
            $browser->pause(1200)->screenshot('10-docs-sop');

            // The SOP tab: the exact-'SOP' element that follows the 'Run' tab in the DOM.
            $browser->script('(() => {
                const visible = e => e.getClientRects().length > 0;
                const leafs = [...document.querySelectorAll("body *")].filter(e => visible(e) && e.childElementCount === 0);
                const run = leafs.find(e => e.textContent.trim() === "Run");
                if (!run) return;
                const sop = leafs.find(e => e.textContent.trim() === "SOP" &&
                    (run.compareDocumentPosition(e) & Node.DOCUMENT_POSITION_FOLLOWING));
                if (sop) ["pointerdown","mousedown","pointerup","mouseup","click"].forEach(tp =>
                    sop.dispatchEvent(new MouseEvent(tp, {bubbles: true, cancelable: true, view: window})));
            })()');
            $browser->pause(900)->screenshot('26-sop-editor-tab');

            // Docs > Commands — the action reference.
            $this->clickText($browser, 'Commands');
            $browser->pause(1000)->screenshot('18-docs-commands');

            // Docs > Incidents and Templates.
            $this->clickText($browser, 'Incidents');
            $browser->pause(900)->screenshot('27-docs-incidents');
            $this->clickText($browser, 'Templates');
            $browser->pause(900)->screenshot('28-docs-templates');

            // Companies.
            $browser->visit('/companies')
                ->pause(1200)
                ->screenshot('11-companies');
            $this->clickText($browser, 'Plutonic Games');
            $browser->pause(700)->screenshot('34-company-detail');

            // Settings.
            $browser->visit('/settings')
                ->pause(1200)
                ->screenshot('12-settings');
            $this->clickText($browser, 'Identity & integrations');
            $browser->pause(800)->screenshot('29-settings-identity');
            $this->clickText($browser, 'Email & signatures');
            $browser->pause(800)->screenshot('30-settings-email');
            $this->clickText($browser, 'Backups');
            $browser->pause(800)->screenshot('31-settings-backups');
            $this->clickText($browser, 'Roles & access');
            $browser->pause(800)->screenshot('32-settings-roles');
            $this->clickText($browser, 'Organization');
            $browser->pause(800)->screenshot('33-settings-organization');

            // Add Staff modal (the one shared RecordModal). The People page
            // restores its last-active tab, so pin Staff first.
            $browser->visit('/people')->pause(800);
            $this->clickText($browser, 'Staff');
            $browser->waitForText('Add Staff', 10)->pause(500);
            $this->clickText($browser, 'Add Staff');
            $browser->pause(800)->screenshot('13-add-staff-modal');
            $browser->driver->getKeyboard()->sendKeys(\Facebook\WebDriver\WebDriverKeys::ESCAPE);
            $browser->pause(400);

            // The power bar (Cmd+K): universal search and command line in one.
            $browser->visit('/people')->pause(800);
            $browser->script('window.dispatchEvent(new KeyboardEvent("keydown", {key: "k", ctrlKey: true, bubbles: true}))');
            $browser->pause(500);
            $browser->script('(function(){var el = document.querySelector("input[placeholder^=\'Type \']"); if (!el) return; var set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, "value").set; set.call(el, "justin"); el.dispatchEvent(new Event("input", {bubbles: true}));})();');
            $browser->pause(800)->screenshot('35-powerbar-search');
            $browser->script('window.dispatchEvent(new KeyboardEvent("keydown", {key: "Escape", bubbles: true}))');
            $browser->pause(400);

            // Dark mode — People with detail open.
            $browser->script('document.documentElement.classList.add("dark"); localStorage.setItem("theme","dark");');
            $browser->visit('/people')->pause(800);
            $this->clickText($browser, 'Staff');
            $browser->waitForText('Add Staff', 10)->pause(600);
            $this->clickText($browser, 'Justin Sudo');
            $browser->waitForText('jsudo@plutonicgames.com', 10)
                ->pause(500)
                ->screenshot('14-people-dark');
            $browser->script('document.documentElement.classList.remove("dark"); localStorage.setItem("theme","light");');

            $this->assertTrue(true);
        });
    }
}
