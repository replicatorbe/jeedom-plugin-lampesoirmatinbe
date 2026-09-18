# Changelog

## 1.3

- The "State" command now follows the lamps themselves rather than the last
  order sent: switching off at the wall shows on the tile within the minute, and
  a lost order no longer goes unnoticed. Groups whose lamps publish no state keep
  the previous behaviour, for want of anything better.

## 1.2

- Pause a group without disabling it: both moments fall silent, everything else
  keeps working. "Pause" and "Resume" commands for a scenario-driven holiday
  mode, and a "Schedule active" information command.
- The home page announces on each card what the group will do, and marks paused
  groups.
- New "Last change" command: what the plugin did, when, and on what grounds —
  schedule, command or test.
- New "Toggle" action command, for a wall switch.
- The Health page counts paused groups.

## 1.1

- Fix: the lamp selector spun forever. An `.htaccess` file denied access to the
  plugin's entry point, and Apache answered 403 without anything showing up on
  the Jeedom side.
- The plugin icon shows in the menu: `plugin_info/.htaccess` now allows images.
- New "All devices" family in the selector: everything carrying an action
  command, for lamps declared as modules or switches. The command that switches
  on and the one that switches off are named by hand there.
- Every line of the selector can show and change the two commands the plugin
  kept.

## 1.0

First release.

- Lamp groups: one group, one evening moment, one morning moment.
- Lamp selector: walks through the installation, groups by room, tells lamps
  from sockets and from devices matched by name, and switches a lamp on so you
  can identify it.
- Trigger at a fixed time, or relative to sunrise or sunset with an offset in
  minutes.
- Weekdays, "not before" and "not after" time guards, random offset for presence
  simulation.
- Preview of the next three occurrences of each moment.
- Commands: next change, switch on, switch off, state, next evening, next
  morning, sunrise and sunset.
- Catch-up for missed moments, adjustable.
- No daemon, no dependency, no network call.
