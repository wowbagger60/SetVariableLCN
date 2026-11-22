# SetVariableLCN

[Deutsch](README.de.md) | English

Module for IP-Symcon by Ueli Schweizer / Cassanis Automation. Prefix: `SVLCN`

Writes Symcon values such as sun azimuth, sun elevation or outdoor temperature
reliably into LCN variables, so LCN modules can react to them with thresholds.

> LCN can only change variables relatively (or set them to 0), and telegrams
> get lost now and then. The module therefore works as a control loop: it sends
> the difference goal − actual only when it knows the actual value for sure and
> queries it otherwise.

## Table of contents

1. [Requirements](#1-requirements)
2. [Setup](#2-setup)
3. [Conversion and the value 0](#3-conversion-and-the-value-0)
4. [How it works](#4-how-it-works)
5. [Fault](#5-fault)
6. [Settings](#6-settings)
7. [PHP commands](#7-php-commands)
8. [Support](#8-support)
9. [Changelog](#9-changelog)

## 1. Requirements

- IP-Symcon 8.0 or later with Symcon's LCN module.
- One «LCN Value» instance per LCN module with the used variables enabled as
  value type **counter** (A1–A12 for firmware from Feb. 2013, T/R1/R2 =
  variable 1/2/3).
- **LCN itself must never change these variables** (no key commands, sensors
  or regulators on them). The module would reset every change.

## 2. Setup

Create a «SetVariableLCN» instance and add one row per value to the list
«Values»: source (Symcon variable), LCN variable (variable below LCN Value),
factor, offset, deadband, maximum source age and firmware.

## 3. Conversion and the value 0

Raw value = source × factor + offset, rounded and limited to 0 … 30000.

After a restart an LCN variable is 0. Choose factor and offset so that valid
values never give 0; 0 then means «invalid», and the thresholds in the LCN
module can handle it. Examples:

| Value | Range | Factor | Offset | Raw values |
|---|---|---|---|---|
| Outdoor temperature | −40 … 60 °C | 10 | 1000 | 600 … 1600 (LCN temperature format) |
| Azimuth | 0 … 360° | 10 | 1 | 1 … 3601 |
| Elevation | −90 … 90° | 10 | 1000 | 100 … 1900 |

With «Source max. age» the module writes 0 when the source has not been
updated for that long (e.g. weather station down).

## 4. How it works

Per value:

1. If the actual value is within the deadband around the goal, nothing happens.
2. The actual value is **fresh** when it was updated after the last write
   (timestamp «updated», not «changed») and is not older than «Query LCN value
   when older than».
3. If it is fresh and differs, the module sends the difference (`Z+`/`Z-`, at
   most 4000 per telegram; large steps down go via 0), then waits for the grace
   period.
4. If it is not fresh, the module queries the value (`MWTn`; for T/R1/R2 with
   `LCN_RequestRead` of the LCN Value instance) and waits for the answer.

5. If the queries stay unanswered, the module sets the LCN variable to 0 once
   and queries again. After power-up an LCN module does not answer queries for
   a variable that has never been set since; setting it to 0 makes it
   answerable.

A lost telegram thus costs at most one extra query, never a double addition.
The periodic query also finds an LCN variable that is 0 after the LCN module
restarted.

## 5. Fault

After «Rounds without effect until fault» writes without success (or as many
unanswered queries) the module stops sending, writes a log message and sets the
variable «Fault». A new goal, the button «Reset faults» or a matching actual
value clear the fault.

## 6. Settings

| Setting | Default | Meaning |
|---|---|---|
| Grace period after writing | 3 s | No new attempt within this time after sending |
| Rounds without effect until fault | 3 | See [Fault](#5-fault) |
| Query LCN value when older than | 900 s | Periodic check; 0 = only after writing |

Firmware «up to Nov. 2010» uses `ZA`/`ZS` and can only write T (Tvar).

## 7. PHP commands

```php
SVLCN_Check(int $InstanceID);        // run the control loop now
SVLCN_ResetFaults(int $InstanceID);  // clear faults and round counters
SVLCN_GetStatus(int $InstanceID);    // array with goal, actual, updated, rounds, fault per value
```

## 8. Support

This module is free of charge. Donations to support the author are gratefully
accepted: PayPal to ueli.schweizer@cassanis.fr

## 9. Changelog

**1.0 Build 4:**

- If an LCN module does not answer the query of a variable, the module sets
  it to 0 once and queries again. After power-up an LCN module does not answer
  for variables that were never set, so these were never written before.
  Existing «no answer» faults clear themselves after the update.

**1.0 Build 3:** First release.
