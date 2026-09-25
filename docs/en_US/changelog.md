# Changelog

## 0.1

First release.

- Local network reading of HomeWizard Energy devices: P1 meter, energy socket,
  single and three phase kWh meters (SDM230 and SDM630 included), water meter
  and battery.
- Local API v1, or v2 after pairing with the device button. Commands use v2 names
  as their logical ID: switching from one to the other loses neither history nor
  scenario.
- Discovery by scanning the subnet, or adding by IP address. A device already
  known only gets its address updated.
- Commands created on the first reading, from what the device actually reports.
- Dependency-free PHP daemon for readings down to every second, with cron
  polling, once a minute, when it is stopped.
- Total and per-phase power, imported and exported; import and export counters
  per tariff; today's and yesterday's import, export and water.
- Capacity tariff: average demand, monthly peak, previous month's peak, highest
  peak and twelve-month peak average with the 2.5 kW floor, history read from the
  meter's telegram.
- Grid quality: voltages, currents, frequency, apparent and reactive power,
  power factor, power failures, voltage sags and swells.
- Gas, water and heat meters connected to the P1.
- Actions: socket, lock, identify, cloud, LED brightness, reboot and battery
  charging strategy.
- Diagnostics tab showing the device's latest raw answers.
