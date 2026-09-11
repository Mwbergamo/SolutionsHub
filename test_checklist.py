import asyncio
from playwright.async_api import async_playwright

BASE = "http://localhost:8877/relationships/"

async def main():
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        page = await browser.new_page()
        page.on("pageerror", lambda err: print("PAGEERROR:", err))

        await page.goto(BASE + "index.html")
        await page.wait_for_url("**/login.html?next=index.html")
        await page.click('[data-tab="register"]')
        await page.fill("#regName", "Taylor CRC")
        await page.fill("#regEmail", "taylor.crc@codebluetechnology.com")
        await page.fill("#regPassword", "supersecret123")
        await page.click("#registerSubmit")
        await page.wait_for_url("**/index.html")
        await page.wait_for_selector(".topbar")

        # Riverbend Family Dental: IT Services active (managed-it,
        # cyber-security, provided-equipment -- all cross-sell eligible, and
        # already active, so nothing to cross-sell there). Missing & NOT
        # cross-sell eligible: vCIO, Help Desk, On-Site Support, Equipment
        # Sales. Missing & cross-sell eligible: Cloud Voice (VoIP), IP
        # Cameras + Access Control (Premise Security).
        await page.click("#customerSearchInput")
        await page.type("#customerSearchInput", "Riverbend", delay=30)
        await page.wait_for_selector(".search-result-row", timeout=3000)
        await page.click(".search-result-row")
        await page.wait_for_selector(".customer-name")

        # --- IT Services: missing services here (vCIO etc.) are NOT
        # cross-sell eligible -- confirm no marketing link / checklist shows.
        await page.click('.pillar-tile[data-pillar="it"]')
        await page.wait_for_selector(".drilldown")
        it_toggle_buttons = await page.query_selector_all(".checklist-toggle-btn")
        it_marketing_links = await page.query_selector_all("a.svc-action-btn.secondary")
        print("1) IT Services checklist toggles:", len(it_toggle_buttons), "| marketing links:", len(it_marketing_links))
        assert len(it_toggle_buttons) == 0, "IT Services' missing services (vCIO etc.) should have no checklist"
        assert len(it_marketing_links) == 0, "IT Services' missing services (vCIO etc.) should have no marketing link"
        it_hub_links = await page.query_selector_all("a.svc-action-btn.primary")
        print("   'Open in Solutions Hub' links still present:", len(it_hub_links))
        assert len(it_hub_links) >= 1, "missing services should still deep-link into Solutions Hub"
        await page.click('[data-action="close-drilldown"]')

        # --- Premise Security: IP Cameras + Access Control ARE eligible.
        await page.click('.pillar-tile[data-pillar="security"]')
        await page.wait_for_selector(".drilldown")
        sec_toggle_buttons = await page.query_selector_all(".checklist-toggle-btn")
        print("2) Premise Security checklist toggles:", len(sec_toggle_buttons))
        assert len(sec_toggle_buttons) == 2

        await sec_toggle_buttons[0].click()
        await page.wait_for_selector(".checklist-step", timeout=3000)
        steps = await page.query_selector_all(".checklist-step")
        print("3) checklist steps rendered:", len(steps))
        assert len(steps) == 7
        step_labels = [await s.inner_text() for s in steps]
        assert "1. Initial Marketing Outreach" in step_labels[0]

        checkboxes = await page.query_selector_all(".checklist-step input[type=checkbox]")
        await checkboxes[0].click()
        await page.wait_for_timeout(500)
        checkboxes2 = await page.query_selector_all(".checklist-step input[type=checkbox]")
        await checkboxes2[1].click()
        await page.wait_for_timeout(400)
        checkboxes3 = await page.query_selector_all(".checklist-step input[type=checkbox]")
        await checkboxes3[2].click()
        await page.wait_for_timeout(400)

        checkboxes4 = await page.query_selector_all(".checklist-step input[type=checkbox]")
        checked_states = [await cb.is_checked() for cb in checkboxes4]
        print("4) checked states after 1,2,3 checked:", checked_states)
        assert checked_states[:3] == [True, True, True]

        first_step_text = await (await page.query_selector_all(".checklist-step"))[0].inner_text()
        print("5) step 1 after check:", first_step_text.replace("\n", " | "))
        assert "✓ Taylor CRC" in first_step_text

        # --- Report should only ever show eligible services (no vCIO/Help
        # Desk/etc. rows at all).
        await page.click('[data-action="show-report"]')
        await page.wait_for_selector(".report-table", timeout=3000)
        report_text = await page.inner_text(".report-table")
        print("6) report table text:\n", report_text)
        assert "vCIO" not in report_text
        assert "Help Desk" not in report_text
        assert "On-Site Technical Support" not in report_text
        assert "Equipment Sales" not in report_text
        assert "IP Security Camera Systems" in report_text
        assert "Cloud Voice System" in report_text

        rows = await page.query_selector_all(".report-table tbody tr")
        cam_row = None
        for r in rows:
            t = await r.inner_text()
            if "IP Security Camera Systems" in t:
                cam_row = r
                break
        assert cam_row is not None
        cells = await cam_row.query_selector_all("td")
        step4_btn = await cells[4].query_selector("button.report-count")
        assert step4_btn is not None, "expected a clickable count in the step-4 cell"
        await step4_btn.click()
        await page.wait_for_selector(".queue-list", timeout=3000)
        queue_text = await page.inner_text(".queue-list")
        print("7) queue list text:\n", queue_text)
        assert "Riverbend Family Dental" in queue_text

        await page.click(".queue-item")
        # .checklist-panel appears immediately with a "Loading checklist…"
        # placeholder (checklistHtml() in app.js) BEFORE loadChecklist()'s
        # fetch resolves and re-renders it with the actual .checklist-step
        # rows -- waiting on the panel alone is a race that can catch the
        # DOM mid-swap (elements queried right as they're replaced). Wait
        # for the real step rows instead, same fix pattern already used in
        # test_prospect.py for an analogous race.
        await page.wait_for_selector(".checklist-step", timeout=5000)
        checked_after_jump = [await cb.is_checked() for cb in await page.query_selector_all(".checklist-step input[type=checkbox]")]
        print("8) checked states after jump-back:", checked_after_jump)
        assert checked_after_jump[:3] == [True, True, True]

        active_nav = await page.inner_text('.nav-btn.active')
        print("9) active nav label:", active_nav)
        assert active_nav.strip() == "Dashboard"

        await browser.close()
        print("\nALL CHECKLIST/REPORT/ELIGIBILITY CHECKS PASSED")

asyncio.run(main())
