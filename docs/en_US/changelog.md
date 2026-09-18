# Changelog

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
