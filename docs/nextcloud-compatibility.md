# Nextcloud compatibility

## Policy

**AIquila is developed, built and tested against the current Nextcloud release.**

That version is what CI runs, what the Docker images ship, and what the development
stack installs. It is the version where a bug report is actionable as written.

| Nextcloud | Status | What this means |
|---|---|---|
| **34** | **Supported** | Developed and tested against. Bugs are fixed here first. |
| 33 | Permitted, untested | Installs and is expected to work. No targeted testing; issues get best-effort treatment. |
| 35 | Permitted, unreleased | Pre-declared so the app is not blocked on release day. Becomes the supported version once we move to it. |
| ≤ 32, ≥ 36 | Unsupported | Outside the declared window. The app store will not offer the app. |

## Why the declared window is wider than the supported version

`appinfo/info.xml` declares:

```xml
<nextcloud min-version="33" max-version="35"/>
```

Three versions, one of them supported. That gap is deliberate, and it is not a
contradiction — the two numbers answer different questions:

- **`min-version`** keeps existing installs on the previous major working. Dropping it the
  day a new Nextcloud ships would strand users mid-upgrade, for no benefit.
- **`max-version`** is pre-declared one major ahead. Nextcloud blocks installation above
  `max-version`, so an app that declares only the current release becomes uninstallable the
  moment the next one lands, until a release goes out purely to raise a number.

The Nextcloud developer manual suggests pinning both to the current major. We deviate
on purpose, for the two reasons above.

**Being inside the window is not a support promise.** It means the app will install and
we have no reason to expect breakage — not that the combination is exercised.

## How the window moves

When a new Nextcloud major is released, all of the following move forward together:

- `nextcloud/ocp` in `nextcloud-app/composer.json`, so static analysis resolves against
  the new API surface
- the `nextcloud:<major>-apache` base image in `docker/installation/Dockerfile` and the
  Hetzner `nextcloud` and `full` stacks
- `min-version` and `max-version` in `appinfo/info.xml`
- this page

Psalm runs at `errorLevel=3` with no baseline over the new `OCP\*` sources, which is what
catches removed or changed APIs before a release goes out.

## Reporting an issue

Include your Nextcloud version. If you are not on the supported release, say so — it is
the first thing that gets checked, and reproducing on the supported version is usually
the fastest route to a fix.
