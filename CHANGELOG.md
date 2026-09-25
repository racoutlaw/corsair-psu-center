# Changelog

All notable changes to **Corsair PSU Center**, newest first. This mirrors the in‑plugin
changelog shown in Unraid's Community Applications. Versions are date‑based (`YYYY.MM.DD`).

## 2026.09.26
- The **Custom Curve** preset now applies your last saved custom curve on a single
  click, like the other presets — no separate "Apply Curve" needed to get it going.
  Your hand‑drawn curve is remembered on its own, so switching to Quiet / Balanced /
  Performance and back to Custom Curve returns to *your* curve instead of theirs.

## 2026.09.25
- Fan control tidied up: every mode now lives in one **Cooling Presets** list —
  Default, Quiet, Balanced, Performance, Fixed and Custom Curve. The separate mode
  buttons that used to sit inside the fan card are gone; that card is now just the
  editor (the slider for Fixed, the draggable curve for Custom).
- The **Fan mode** readout now names the active preset (**Quiet / Balanced /
  Performance**) instead of always saying "Custom curve"; a hand‑drawn curve still
  shows "Custom curve".
- The old **"Max (100%)"** button is now **"Fixed"** — it applies the speed you pick on
  the slider instead of forcing 100%.
- **Fixed:** the fixed‑speed slider no longer snaps back to the stored value while
  you're dragging it, so you have time to set a duty and Apply.

## 2026.09.24
- **Changed:** removing the plugin no longer deletes your data. Uninstalling now keeps
  your settings and the full energy history (kWh + cost) on the flash, so pulling the
  plugin to troubleshoot and reinstalling picks everything right back up — only the
  downloaded package files are cleared. Delete the plugin's folder by hand if you want a
  full purge. Thanks to PaliKinG3 for the report (issue #7).

## 2026.09.12
- **New:** currency selection. Choose the currency shown for costs from the new Currency
  panel — USD, EUR, GBP, JPY and more, or a custom symbol — and pick whether it sits
  before the amount (`$1`) or after it (`1 €`). Every cost updates to match: the tiles,
  Last Month, the month/year pickers and the history lists.

## 2026.09.10
- **New:** energy history. The dashboard now keeps every past month and year instead of
  losing "This Month" when it rolls over. Added a **Last Month** tile and an **Energy
  History** section with a month picker and a year picker (jump to any month or year you
  have data for), 1 / 3 / 6 / 12 month and All filters, a month‑by‑month list and a
  by‑year summary. It reads the daily data the plugin was already recording, so past
  months appear right away. History is tiny (a few bytes per month) and days the server
  was off are not counted.
- **Changed:** on the energy tiles the cost is now shown at full size beside the kWh (it
  was small subtext before), and the small min/max numbers on every tile were enlarged to
  match, for readability.

## 2026.08.20
- **Fixed:** a fresh install or reinstall could fail with `'/bin/bash' returned 1`, and
  the plugin could vanish after a reboot. The pre‑install cleanup step returned an error
  when there was no previous version to remove — the normal state on a clean system and on
  every fresh boot — and Unraid treated that as fatal, aborting the install before the
  package was registered. Installs now complete reliably on a clean system and the plugin
  persists across reboots. In‑place upgrades were not affected.

## 2026.08.10
- **New:** energy usage + cost tracking. New dashboard tiles show power used and the cost
  for today / this week / month / year / lifetime, with a field for your electricity rate.
  A lightweight background collector integrates the PSU's input power into kWh; data
  persists on the flash across reboots and is kept crash‑safe with atomic checkpoints
  (kind to the USB stick — it writes only every few minutes, while the on‑screen number
  updates live from RAM).
- **New:** manual mains‑voltage control (Auto / 115V / 230V) in the header. Some units
  (e.g. the HX1000i 2022 revision) report input voltage at about double the real value
  over their USB interface; if yours reads ~230V on a 115V outlet (or the reverse), pin
  your real mains here so the input‑voltage readout and the efficiency / power‑in estimates
  stay correct. Auto (the default) trusts the PSU, so existing setups are unchanged.
- Tidied the energy Reset tile layout.

## 2026.08.09
- Fan control now works on Unraid systems with no Python installed. The plugin bundles its
  own Python 3.11 runtime, so liquidctl no longer needs a system `python3` (Unraid does not
  ship Python by default). Fixes "python3: command not found" on any fan action.
- Now fully self‑contained: bundled interpreter plus every module it needs.

## 2026.07.26
- First release.
- iCUE‑style dashboard for Corsair HXi / RMi digital power supplies.
- Live sensor tiles (per‑rail volts/amps/watts, temps, fan, efficiency, input/output power)
  read from the kernel `corsair-psu` hwmon driver, so polling costs no USB traffic and
  cannot collide with anything else.
- Fan control: default (PSU firmware), fixed duty, or a custom curve with a draggable
  editor, plus Quiet / Balanced / Performance / Max presets.
- +12V OCP single/multi rail toggle.
- Model is auto‑detected; 12 supported PSUs, each with its own efficiency coefficients.
- Self‑contained: bundles every python module it needs. No pip, no internet access required
  at install or boot.
