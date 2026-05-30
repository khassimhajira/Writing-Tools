# aMember Plugins

These are custom aMember Pro plugins maintained alongside the Hub. They live
on a separate domain (`app.scholargenie.org`, the aMember installation) but
are checked into this repo so they're version-controlled and easy to redeploy.

## Layout

```
amember-plugins/
└── misc/
    └── single-session.php
```

The `misc/` subfolder mirrors aMember's own plugin directory layout. Each
file's `@title` / `@desc` PHP-doc tags drive how aMember labels them in
Setup → Plugins.

## Production install path

```
/home/u124071091/domains/scholargenie.org/public_html/app/application/default/plugins/misc/
```

To deploy: SCP / SFTP the file and then enable it once in aMember admin
(Setup → Plugins → Misc tab → toggle ON → Save).

## Plugins

### single-session.php

Enforces one-active-session-per-user on aMember. When a user logs in, all
other active sessions for that same user are deleted from `am_session`.
Any browser holding an older session cookie is logged out on the next
request.

- Zero config — just enable it.
- Skips admin sessions (admins typically need multi-device).
- Errors are logged to aMember's `am_error_log` and never break login.
