# Laravel Error Report

**Production errors your coding agent can actually fix.**

Something breaks in production and all you have is a log line and a stack trace a hundred frames deep. No values, no context, and every customer's email in it, so you can't paste it into an AI tool anyway.

Laravel Error Report turns each production error into one short, anonymised report: what broke, where, with which values, on which release, and how to reproduce it. Paste it into Claude Code, Cursor or any coding agent, and it has everything it needs to fix the bug on your local copy of the site.

![An error report mail](docs/mail.png)

## What you get

**The values, not just the trace.** Every call in your own code shows the arguments it received: `$coupon = 'BLACKFRIDAY'`, `$qty = 2`, `Order #48213 {status: 'pending', total: 1999}`. Most bugs are obvious once you see the value that caused them.

**Privacy built in.** The signed-in user, the request, and every argument, array key or model attribute named like a person's data (`email`, `phone`, `name`, `address`, `password`, `token`, `user_id`...) are masked. No IPs, cookies, sessions, headers, query strings or request bodies are ever recorded. You can send the report to an AI tool without sending your customers with it.

**Instructions for the agent.** Every report opens with steps built from that error: check out the exact release in a worktree, see if it is already fixed, reproduce it (the route to request, the job to dispatch or the command to run), start from the deepest cause, fix it with a failing test first, and report back whether production data needs a repair too.

**One entry per bug, not a thousand log lines.** The same error seen again bumps a count. `error-report:list` shows what is broken right now, newest first.

**Heard about at once, never flooded.** New errors go to your inbox, Slack or any log channel, at counts 1, 2, 4, 8, 16... You hear about something new immediately, and something that keeps breaking comes back ever less often.

**Stays out of the way.** It uses your app's own exception reporting, so `dontReport` still applies and your logs keep working exactly as before. If anything goes wrong inside the package, a full disk, a mail server down, it gives up quietly and your app carries on.

## Install

```bash
composer require geodro/laravel-error-report
```

That's it. Laravel discovers the package, and every exception your app reports is recorded from then on.

## Use

On the server:

```bash
php artisan error-report:list            # what is broken, newest first, with counts
php artisan error-report:show latest     # the newest one, ready to paste
php artisan error-report:show d7c1719763 # a specific one
php artisan error-report:list --clear    # start fresh
```

Paste the output of `error-report:show` into your agent. Copying the whole mail works just as well.

## Get errors by mail, Slack, or anywhere else

Set either or both in `.env`, comma separated:

```bash
ERROR_REPORT_MAIL=ops@example.com,dev@example.com
ERROR_REPORT_CHANNELS=slack
```

Mail arrives formatted, with the plain report as its text part, through your app's default mailer. It is only sent when that mailer really delivers: with Laravel's default `MAIL_MAILER=log`, with `array`, or with an smtp mailer that has no host, nothing goes out.

Channels are any in `config/logging.php`: Laravel's own `slack` driver, or any Monolog handler for WhatsApp, Telegram and the rest.

Nothing leaves the server unless you set one of these. Without them, a report exists only when you ask for it.

## What is never recorded

Left out entirely:

- the signed-in user, beyond `guest` or `signed in`
- IP addresses, cookies, session ids, headers, query strings and request bodies
- the real URL; the report shows the route pattern, `/password/reset/{token}`
- console command arguments and queue job payloads
- query bindings; the SQL keeps its `?` placeholders and loses inline string literals
- a model's `$hidden` attributes

Masked where they appear:

- the user model passed as an argument, and the request object
- any argument, array key or attribute named like a person's data
- in messages and string values: emails, IPs, UUIDs, JWTs, bearer tokens, query strings, ids that mix letters and digits, and numbers of four digits or more
- quoted and bracketed values in database driver messages

Messages are free text your own code writes, and a pattern cannot recognise a plain name, so keep people's names out of exception messages.

## Configuration

```bash
php artisan vendor:publish --tag=error-report-config
```

| Key | Default | |
|---|---|---|
| `enabled` | `ERROR_REPORT`, true | turn recording off without uninstalling |
| `path` | `storage/error-reports` | where entries are kept |
| `max_entries` | 200 | distinct errors kept, oldest dropped first |
| `mail` | `ERROR_REPORT_MAIL`, empty | addresses that get the report |
| `channels` | `ERROR_REPORT_CHANNELS`, empty | log channels that get the report |
| `release` | `APP_RELEASE` | deployed commit, falls back to `.git/HEAD` |

Set `APP_RELEASE` to the commit you deploy, so the agent works on the code that actually broke.

## Good to know

PHP-FPM and the account you run artisan as are often different users, and whichever hits an error first creates the folder. It is created writable for both, inside `storage/`, which the web server never serves. If `error-report:list` cannot read it, it says so rather than showing an empty list.

When the app runs in containers, `storage/` has to be a persistent volume, or the record goes with each deploy.

Argument values need `zend.exception_ignore_args` off, which is PHP's own default; `php.ini-production` turns it on. When it is on, the report says the values are not available.

## Requirements

PHP 8.2 or newer, Laravel 12 or 13.

## License

MIT
