# Evening & Morning Lamps

Switch your lamps on in the evening and off in the morning, without writing a
single scenario.

The plugin drives no hardware of its own: it commands the lamps your other
plugins have created — Zigbee, Z-Wave, Hue, MQTT, Wi-Fi sockets, older modules.
Anything in Jeedom that can switch on and off can join a group.

## Before you start

Set the position of your installation in **Settings → System → Configuration →
General**. Without a latitude and a longitude, sunrise and sunset times are
wrong all year long, and nothing but this plugin will tell you.

It is the same position Jeedom already uses for `#sunrise#` and `#sunset#` in
scenarios, so the plugin's times and your scenarios' times cannot diverge.

## Creating a group

A **group** holds the lamps you switch on and off together: the living room, the
front of the house, the children's bedrooms. A group has two moments, evening
and morning, and that is all there is to set.

1. **Plugins → Automation → Evening & Morning Lamps → Add a group.** Name it
   after what it lights.
2. **Choose the lamps.** The button opens the selector.
3. **Schedule tab.** Set the evening, set the morning.
4. **Save.** There is nothing else to do.

## The lamp selector

The selector walks through your installation and shows only what can switch on
and off, grouped by room. Three families, two of them shown by default:

| Family | What it is |
|---|---|
| **Lamps** | The device carries the core's *Light* generic types. No doubt possible. |
| **Sockets** | A switched socket. It may well carry a lamp: that is for you to say. |
| **Others** | Neither of the above, but two action commands named "On" and "Off". Many older protocols leave the generic types empty; without this safety net their lamps would be unreachable. |
| **All devices** | Everything carrying an action command, including what the plugin cannot make sense of. Open it only when your lamp appears nowhere else: there, you name the command that switches on and the one that switches off. |

The **⚙** button on each line shows the two commands kept and lets you change
them: a socket whose "On" and "Off" are swapped, a module calling its switch-on
"Pulse", a three-relay device where only one carries the lamp — all of that is
fixed there, without leaving the selector.

Every line carries two buttons that **really switch the lamp on and off**, and a
dot showing its state when the device publishes one. It is the fastest way to
find out which of your three lamps is called "Module 3" — without leaving the
page.

You tick **devices**, not commands: the plugin keeps track of which command
switches on and which switches off. A device that only knows how to toggle —
many wall switches — is accepted, and its toggle serves both orders; that is the
one case where the plugin can get the state wrong, if the lamp was touched by
hand in between.

A room's **Tick all** button answers the most common gesture: "all the living
room lamps" is one intention, not six decisions.

## Setting a moment

Evening and morning are set in exactly the same way.

**Do** — switch on or switch off. The evening switches on and the morning
switches off by default, but nothing forces you to stay there: a "morning lamps"
group switches on at sunrise and off an hour later.

**When** — three possibilities:

- **At a fixed time**: 20:00, all year round.
- **Relative to sunset**: so many minutes before or after.
- **Relative to sunrise**: likewise.

**Days** — the weekdays concerned. No weekday ticked means *never*, not *every
day*: that is what one expects after unticking everything to suspend a moment.

**Time guards** — "not before" and "not after". In Brussels the sun sets at 4:40
pm on 21 December and at 10:00 pm on 21 June: an evening switch-on tied to sunset
would drift by five hours over the year. "Not before 17:30" brings winter
evenings back to 17:30 — a guard **brings back**, it does not cancel. Leave it
empty to follow the sun all year.

**Random offset** — presence simulation. The moment is moved earlier or later at
random, within this limit. The draw happens **once a day**: the time shown in the
preview is the one that will really be played, and two groups set the same way do
not fire on the same second.

**Next times** — under each moment, the next three occurrences, computed by the
very code that will decide the order when the time comes. A moment that is never
announced will never fire: disabled moment, no weekday ticked, or missing
position.

## The commands created

| Command | Type | Role |
|---|---|---|
| **Next change** | info | "Switch on today 20:42". Visible on the dashboard. |
| **Switch on** / **Switch off** | action | Acts on the whole group, by hand or from a scenario. |
| **State** | binary info | The last order sent by the plugin. Logged. |
| **Next evening** / **Next morning** | info | The two appointments separately. |
| **Sunrise** / **Sunset** | info | Today's times, useful in scenarios. |

Only the first three are visible on creation; the others are created hidden, to
be shown again if you have a use for them.

**"State" is the plugin's state, not the bulb's.** The plugin sends orders, it
does not watch what a wall switch does on its side.

## Catch-up

The cron runs every minute. If the box was off at the scheduled time, the moment
is still played during the **catch-up** window — 15 minutes by default,
adjustable in the plugin configuration. After that it is dropped: switching the
evening lamps on at three in the morning helps nobody.

A moment is played only once a day, even if the cron runs sixty times during the
catch-up window.

## Frequently asked questions

**A lamp no longer switches on.** Open the group: a lamp deleted from Jeedom
carries a "Device deleted" label, a disabled one carries its own. A failed order
also produces a message in Jeedom's message centre.

**I renamed a lamp, do I have to pick it again?** No. Lamps are stored by their
identifier; the displayed name is refreshed every time the page is opened.

**Can the same lamp be in two groups?** Yes, but both groups will send it their
orders: the last one wins.

**Can a group contain another group?** No. The selector does not offer itself: a
group containing itself would call itself endlessly.

**Where is the log?** Analysis → Logs → `lampesoirmatinbe`. Every order played
leaves a line with the scheduled time and the setting that produced it.
