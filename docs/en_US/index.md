# HomeWizard plugin

Reads **HomeWizard Energy** devices over the local network — P1 meter, energy
socket, kWh meters, water meter, battery — without going through the
manufacturer's cloud, without an account, and without the app once the device
is set up.

The plugin polls each device's local API, v1 or v2, and creates the commands the
device actually reports. A PHP daemon allows polling below one minute; if it is
stopped, Jeedom's cron takes over, once a minute. Nothing to install: PHP and its
curl extension, already required by Jeedom, are enough.

## Supported devices

| Model | Name | API | What it reports |
|---|---|---|---|
| `HWE-P1` | P1 Meter | v1, v2 | Total and per-phase power, voltages, currents, import and export counters per tariff, active tariff, average demand and monthly peak, peak history (capacity tariff), connected gas, water and heat meters, power failures, voltage sags and swells |
| `HWE-SKT` | Energy Socket | v1 | Power, voltage, current, frequency, energy, socket state, lock, ring brightness |
| `HWE-KWH1` | kWh Meter 1 phase | v1, v2 | Power, voltage, current, frequency, apparent and reactive power, power factor, import and export counters |
| `HWE-KWH3` | kWh Meter 3 phases | v1, v2 | The same values, per phase |
| `SDM230-wifi` | kWh Meter 1 phase (SDM230) | v1 | Like the `HWE-KWH1` |
| `SDM630-wifi` | kWh Meter 3 phases (SDM630) | v1 | Like the `HWE-KWH3` |
| `HWE-WTR` | Watermeter | v1 | Instant flow, total counter, today's and yesterday's water |
| `HWE-BAT` | Plug-In Battery | v2 only | State of charge, cycles, power, voltage, current, charged and discharged energy |
| `HWE-DSP` | Energy Display | — | Recognised and identified; commands depend on what it reports |

The "What it reports" column describes what the device can publish. The plugin
assumes nothing: a single-phase P1 gets no phase 3, a meter without gas gets no
gas (see "Commands created on the first reading").

## Requirements

- One or more HomeWizard Energy devices on the same network as Jeedom.
- **The local API enabled** for each device used with v1, in the HomeWizard
  Energy app: **Settings → Meters → the device → Local API**. Without it the
  device answers "API not enabled" and the plugin reads nothing. The battery is
  the exception: it only speaks v2, which is enabled by pairing (see below).

Reserve each device's IP address in your router. If it changes, the plugin loses
the device; running the search again then updates the address without creating a
duplicate.

## Installation

1. Install the plugin, then enable it.
2. Leave the daemon in automatic mode: it starts as soon as an enabled device has
   an IP address.
3. Open **Plugins → Energy → HomeWizard**.

Nothing else to install: the plugin has no dependency.

## Discovering devices

### Search the network

The **Search the network** button asks for a subnet, for instance
`192.168.1.0/24`. Left empty, Jeedom's own subnet is scanned.

The plugin then asks `http://<address>/api` of the 254 addresses of the subnet,
in parallel: the whole scan takes two or three seconds. It does not use mDNS, on
purpose — multicast crosses neither Docker nor many VLANs, whereas an HTTP
question sent to each host gets through everywhere.

The devices that answered are listed with their model, address, serial number and
firmware. Tick those to create. A device already known — recognised by its
serial number — is not created again: only its address is updated.

Two limits to be aware of:

- only the last byte is scanned: `192.168.1.0/24` and `192.168.1.0/16` both query
  `192.168.1.1` to `192.168.1.254`;
- the search goes through API v1. A device whose v1 is disabled, and the battery,
  which only has v2, are not found this way: add them by IP address.

### Add by IP address

**Add by IP address** queries a single address. The plugin tries v1 first; if
nothing answers over HTTP but an HTTPS service answers "unauthorized", it knows
it is facing a v2-only HomeWizard device, without knowing which one. The device
is created under the name "HomeWizard (API v2)"; it gets its real model and
serial number when paired.

In both cases, Jeedom queries the device again at creation time: the plugin does
not trust what the browser sends.

## The device page

### Device

- **IP address**: the device's address on your network.
- **Read every**: from one second to five minutes, **ten seconds by default**.
  The P1 publishes a new value every second, but Jeedom only historizes a value
  when it changes, and power nearly always changes: at one second, history grows
  fast. Ten seconds follows consumption without filling it up.
- **Capacity tariff** (P1 only): reads the monthly peak history from the meter's
  telegram. Ticked by default. Untick it outside Belgium, or if you have no use
  for the computation: the plugin then stops requesting the telegram.

### API

The label shows the API in use: **v1** or **v2 (paired)**.

**v1** is plain HTTP, without authentication, and is enough to read everything.
**v2** is HTTPS with a token. It adds the LED setting on the P1 and kWh meters,
remote reboot and the battery charging strategy; it is mandatory for the
HomeWizard battery.

**Pair with v2**:

1. Save the device with its IP address.
2. Click **Pair with v2**. A window opens and the plugin asks the device every
   two seconds, for a minute and a half.
3. Meanwhile, press the device's button — on the P1, the button on top; on a kWh
   meter, the Wi-Fi button, one to three seconds.
4. The device then hands out a token, which the plugin saves. The page reloads in
   v2.

The device refuses to create a token until its button has been pressed: that is
what guarantees the request comes from someone with physical access. A new
pairing replaces the previous one.

The device's HTTPS certificate is signed by HomeWizard's own authority, which
nothing on the Jeedom machine knows; its verification is therefore disabled, as
the app itself does on the local network. The token is the protection.

**Back to v1** removes the token from the device, then from Jeedom. The v2-only
commands (LED, reboot, charging strategy) remain, but without effect.

**Switching from v1 to v2, or back, changes no command.** The two APIs do not
name fields the same way; the plugin maps everything to v2 names on arrival, and
commands are identified by those names. History and scenarios are kept.

### Identity

Model, serial number, firmware and API version, read again every hour. The
**Read now** button forces a full reading, at most once every three seconds.

## Daemon and cron

Jeedom's cron cannot go below one minute. For a P1 that publishes every second,
that is too slow: hence a daemon.

- **Daemon running**: it reads each device at the chosen interval. It knows
  neither fields nor commands: it receives from the plugin a list of URLs and a
  period for each, reads them and sends the raw answers back to Jeedom, which
  decodes them. Cron polling goes through exactly the same decoding: commands and
  values are the same either way. The plugin's API key is passed to it on
  standard input, never as an argument, since the process list is readable by
  every user on the machine.
- **Daemon stopped**: the plugin's cron takes over, **at most once a minute**,
  whatever the chosen interval. It allows itself forty seconds per run; beyond
  that, the remaining devices wait for the next minute.

The daemon reloads its configuration whenever a device is saved, and every five
minutes anyway. It restarts by itself when a device is saved if automatic mode is
on.

Not all resources are read at the same pace: only measurements follow the chosen
interval.

| Resource | Pace |
|---|---|
| Measurements (and socket state in v1) | the device's interval |
| System (Wi-Fi, cloud, LED, uptime) | 1 minute (5 minutes for a kWh meter in v1) |
| Controlled batteries (P1 and kWh meter in v2) | 1 minute |
| P1 telegram (capacity tariff) | 15 minutes |
| Device identity | 1 hour |

An unreachable device is retried less and less often, up to five minutes apart,
so as not to delay the others. The log keeps a single `warning` line for it, on
the third consecutive failure, then one `info` line when it comes back.

The **plugin configuration** has a single setting: the request timeout, three
seconds by default (1 to 15). On the local network, a HomeWizard answers within a
few tens of milliseconds, or not at all.

## Commands

### Commands created on the first reading

Commands are not guessed from the model: each one is created on the first reading
that contains the matching field, as Home Assistant does. A freshly created
device therefore only has **Refresh** and its actions; info commands arrive with
the first reading, a few seconds later.

An existing command is never rewritten: its name, display and historization are
yours. A null or missing field in an answer produces nothing; the command keeps
its last value rather than dropping to zero.

All info commands are listed below. Those marked *hidden* exist and can be used in
scenarios, but are not displayed by default.

### Power

| Command | Unit | What it is |
|---|---|---|
| Power | W | Signed on the P1: positive when importing, negative when exporting |
| Imported power | W | The positive part, *hidden* |
| Exported power | W | The negative part, as a positive value, *hidden* |
| Power phase 1…3 | W | One per phase |

The first three say the same thing in three forms: the signed one suits
scenarios (`< 0` means "exporting"), the other two suit Jeedom's energy widgets,
which expect separate positive values.

### Energy

| Command | Unit | What it is |
|---|---|---|
| Energy import | kWh | Total import counter |
| Energy export | kWh | Total export counter |
| Energy import tariff 1…4 | kWh | Import counter per tariff |
| Energy export tariff 1…4 | kWh | Export counter per tariff |
| Tariff | | Current tariff, as announced by the meter |
| Daily import / Daily export | kWh | Computed, see below |
| Yesterday's import / Yesterday's export | kWh | Computed, *hidden* |

A socket or a kWh meter repeats its total counter under the name "tariff 1".
Without a tariff 2, that duplicate tells nothing: the plugin then creates no
per-tariff command.

**Today and yesterday.** The device does not provide them; the plugin derives
them from the counters. On the first reading of the day, it stores the starting
counter; today's energy is the difference with the current counter. Yesterday is
only computed if yesterday's reading did take place: after an outage of several
days, the difference would cover the whole period and mislead. Energy used
between yesterday's last reading and midnight is counted in the new day — a few
seconds at the default interval.

### Capacity tariff (P1)

Since 2023, in Flanders, part of the grid fee depends on the **quarter-hour
peak**: the highest average power drawn over a quarter of an hour, month by
month. The basis is the **average of the monthly peaks over the last twelve
months**, each month counting for **at least 2.5 kW**. A quiet month at 1.2 kW
therefore counts as 2.5 kW: that floor is why an average computed by hand, on raw
peaks, comes out lower than the one on the bill.

| Command | Unit | What it is |
|---|---|---|
| Average demand | W | Average of the current quarter-hour, the one that will make the peak |
| Peak demand current month | W | The highest since the start of the month |
| Time of the monthly peak | | Its timestamp, *hidden* |
| Previous month's peak | W | *hidden* |
| Highest peak over 12 months | W | *hidden* |
| 12-month peak average (capacity tariff) | W | 2.5 kW floor applied to each month |
| Monthly peak history | | Text: `2026-09 : 2,728 kW (2026-09-19 08:30) \| …`, *hidden* |

The API only gives the current month's peak. Previous months come from the
meter's **telegram**, which the plugin reads every quarter of an hour when the
**Capacity tariff** option is ticked: the Belgian meter (e-MUCS protocol) keeps
the peaks of the last thirteen months there. The average covers the twelve most
recent, current month included. When a meter's clock was reset mid-month, it
produces two entries for the same month; the plugin keeps the higher one.

Outside Flanders these commands remain useful information, but the 2.5 kW floor
is the Flemish rule.

### Grid quality

| Command | Unit | |
|---|---|---|
| Voltage, Voltage phase 1…3 | V | *hidden* |
| Current, Current phase 1…3 | A | *hidden* |
| Frequency | Hz | *hidden* |
| Apparent power (and per phase) | VA | *hidden* |
| Reactive power (and per phase) | var | *hidden* |
| Power factor (and per phase) | % | As a percentage, like Home Assistant, *hidden* |
| Apparent / reactive current | A | *hidden* |
| Power failures detected | | Since the meter was commissioned, *hidden* |
| Long power failures detected | | *hidden* |
| Voltage sags detected phase 1…3 | | *hidden* |
| Voltage swells detected phase 1…3 | | *hidden* |

### Gas, water and heat meters connected to the P1

The P1 relays the meters connected to the electricity meter. Each gets a command
named after it — **Gas meter**, **Water meter**, **Heat meter**, **Hot water
meter**, **Heat meter (inlet)** — with the unit announced by the device (m³,
GJ…), and a **(timestamp)** command, *hidden*, carrying the time of its last
value. A gas meter usually sends a value only every five minutes, or even every
hour.

Two meters of the same type are numbered in the order the device presents them:
**Gas meter**, **Gas meter 2**. The numbering stays stable as long as none is
unplugged.

The old v1 fields, **Gas (legacy field)** and **Gas timestamp (legacy field)**,
are picked up when present; prefer the commands above.

### Water meter

| Command | Unit | |
|---|---|---|
| Water usage | L/min | Instant flow |
| Total water usage | m³ | Counter |
| Daily water | L | Computed, in litres: on a daily scale, m³ are hard to read |
| Yesterday's water | L | *hidden* |

### Socket

**Socket state** (0/1), **Switch lock** (*hidden*), **Status light brightness**
in % (*hidden*; the ring goes from 0 to 255 in v1, converted to a percentage).

### Battery

On the battery itself (v2): **State of charge** (%), **Battery cycles**, power,
voltage, current and energy counters.

On the P1 or kWh meter paired in v2, which controls the battery group: **Battery
charging strategy**, **Number of batteries**, **Battery group power**, **Battery
group target power**, **Maximum charging power**, **Maximum discharging power**,
all *hidden*.

### Diagnostics

**DSMR version**, **Smart meter model**, **Smart meter identifier** (the number
printed on the meter, decoded from hexadecimal), **Gas meter identifier**,
**Wi-Fi network**, **Wi-Fi strength** (%), **Wi-Fi RSSI** (dBm), **Uptime** (h),
**Cloud connection** (0/1) and **Online** (1 when the device answered the last
reading). All *hidden*.

## Actions

| Action | Devices | API |
|---|---|---|
| Refresh | all | v1, v2 |
| Turn on / Turn off | socket | v1 |
| Lock / Unlock | socket; once locked it can no longer be switched off, neither by its button nor remotely | v1 |
| Identify | all but kWh meters; the device blinks its LED | v1, v2 |
| Enable cloud / Disable cloud | all but the battery | v1, v2 |
| Set status light brightness | socket in v1; any device in v2 | v1, v2 |
| Reboot | all but the battery | v2 |
| Choose charging strategy | P1 and kWh meter | v2 |

**Disable cloud** cuts the device off from the HomeWizard Energy app and its
online services; local reading goes on. The opposite action reverses it.

**Charging strategy** — the values offered:

| Value | Label |
|---|---|
| `zero` | Net zero: the battery charges from surplus and discharges to cover consumption |
| `zero_charge_only` | Net zero, charge only |
| `zero_discharge_only` | Net zero, discharge only |
| `to_full` | One-off full charge |
| `standby` | Standby |
| `predictive` | Smart charging |

The labels shown in the list are in French in this version.

Actions only exist for devices that can perform them: they are chosen from the
model and the API in use. Pairing a device in v2 adds the v2 actions; going back
to v1 does not remove them, they remain without effect.

## Good to know

**Tariff 1 and tariff 2 do not mean the same thing everywhere.** The plugin
keeps the tariff number the meter reports, without reinterpreting it. Its
meaning (day or night, normal or reduced) depends on the country, the grid
operator and the contract: a Fluvius meter observed while writing this plugin
reported tariff 2 on a Friday late morning, for instance. Before renaming the
commands, compare the **Tariff** command with your contract at a known time, on
a weekday during the day and then at night.

**Commands use v2 names as their logical ID** (`power_w`,
`energy_import_t1_kwh`…). Changing API therefore changes no command, and loses
neither history nor scenario. The logical ID shows when hovering over each row of
the *Commands* tab.

**Commands arrive with the first reading**, not when the device is created. If a
gas meter is connected later, its command appears on the next reading, with
nothing to do.

**The device is identified by its serial number.** An IP address that changes
does not create a second one: run the search again.

## Troubleshooting

**"The local API v1 is disabled"** (HTTP 403 answer, "API not enabled"): enable
it in the HomeWizard Energy app, **Settings → Meters → the device → Local API**,
or pair the device with v2.

**"v2 token refused"** (HTTP 401 answer, `user:unauthorized`): the token is no
longer valid, for instance after a device reset or a pairing done elsewhere under
the same name. Pair again from the device page.

**"The P1 has not received a telegram from the meter yet"**: the P1 is reachable
but has read nothing on the P1 port. Check that it is plugged in, and that the P1
port is opened by your grid operator (in Flanders, it is activated on the Fluvius
portal).

**The search finds nothing.** It goes through API v1 and only covers the subnet
given. If Jeedom runs in a container or on another VLAN, give the devices'
subnet, or add them by IP address. From the Jeedom machine, a v1 device must
answer:

```bash
curl -s --max-time 3 http://192.168.0.150/api
```

**Saving the device does nothing**: the page reloads and the input is lost. Look
at `/var/www/html/log/http.error` first: a PHP error shows there, while the
plugin's log stays silent.

**A value looks wrong.** The device's **Diagnostics** tab shows the time of the
last successful reading, the API in use, the daemon's state, and **the device's
latest raw answers**, JSON and telegram, before any conversion. That is what to
attach to a report: it tells straight away whether the value comes from the
device or from the plugin.

Logs are under **Analysis → Logs**: `homewizardbe` for the plugin,
`homewizardbed` for the daemon. Set them to `debug` to see each failed reading.

## Privacy

Everything happens on your local network. The plugin contacts neither HomeWizard
nor any other online service, and asks for no account. The v2 token is kept in
the device configuration, on your Jeedom.

This plugin is not affiliated with HomeWizard, nor sponsored or endorsed by it.
HomeWizard and HomeWizard Energy are trademarks of their owner.
