# Lotsofpixels_CmNotificationResync

Re-triggers the CM.com payment notification webhook for recent orders, one order at a time.

CM.com tells Magento about payment status changes by calling `/cmpayments/payment/notification?id=<increment_id>` (handled by the `CM_Payments` module). If those webhooks were missed, for example during downtime, a firewall/WAF block or a deploy, orders can stay in the wrong state. This module calls the same endpoint for each recent order, so `CM_Payments` processes the notification again.

## Features

- CLI command that finds orders created in the last X days (default: 7)
- By default only includes orders paid with a CM.com method (`sales_order_payment.method LIKE 'cm_payments%'`)
- Builds the webhook URL from each order's own store base URL, so multi-store setups work
- Calls the orders one by one, with a configurable delay between requests (default: 250 ms)
- `--dry-run` prints the URLs without calling them
- Prints the result per order and a summary at the end. Exits non-zero if any request failed.

## Requirements

- Magento 2.4.x
- PHP 8.1+ (the code uses constructor property promotion and `readonly` properties)
- `CM_Payments` module installed
- The server running the command must be able to reach the store's public base URL over HTTP(S)

## Installation

```bash
bin/magento module:enable Lotsofpixels_CmNotificationResync
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode only
bin/magento cache:flush
```

The module has no configuration, cron job or database tables. It only adds the CLI command.

## CLI

```bash
bin/magento lotsofpixels:cm:resync-notifications [options]
```

| Option | Default | Description |
|---|---|---|
| `-d`, `--days` | `7` | Look back this many days, measured from `created_at` (UTC). Minimum `1`. |
| `--all-methods` | off | Include orders that were not paid with a CM.com payment method |
| `--delay` | `250` | Milliseconds to wait between requests. `0` disables the delay. |
| `--dry-run` | off | Only print the URLs. Nothing is called. |

### Examples

```bash
# Preview: list the webhook URLs for CM.com orders from the last 7 days
bin/magento lotsofpixels:cm:resync-notifications --dry-run

# Resync CM.com orders from the last 2 days
bin/magento lotsofpixels:cm:resync-notifications --days 2

# Resync the last 30 days, waiting 1 second between requests
bin/magento lotsofpixels:cm:resync-notifications -d 30 --delay 1000
```

### Output

```
42 order(s) found in the last 7 day(s).
OK   #000012345 -> HTTP 200
FAIL #000012346 -> HTTP 500 <first 200 characters of the response body>
FAIL #000012347 -> cURL error 28: Operation timed out ...
Done: 41 OK, 1 failed.
```

Any HTTP 2xx response counts as **OK**. Any other status code, or an exception such as a timeout or DNS error, counts as **FAIL**. The command returns exit code `1` if at least one order failed, and `0` otherwise. You can use the exit code in scripts or cron wrappers.

## Recommended usage

1. Run with `--dry-run` and check the number of orders and the URLs.
2. Open one of the URLs in a browser or with `curl` to confirm the endpoint responds.
3. Run the command without `--dry-run`.
4. Check the orders marked **FAIL** by hand.

> ⚠️ Every call hits the live storefront. For large date ranges, keep the `--delay` high enough not to overload the site or trigger rate limiting/WAF rules. All matching orders are processed in a single run, with no batch limit.

## How it works

`Model\NotificationResyncer` does the work. `Console\Command\ResyncNotifications` wraps it as a CLI command.

1. **Select orders.** `getOrders()` loads `increment_id` and `store_id` from `sales_order` where `created_at >= now − days` (UTC), oldest first. Unless `--all-methods` is set, it joins `sales_order_payment` and keeps only `cm_payments%` methods.
2. **Build URL.** `buildUrl()` takes the order's store base URL (`URL_TYPE_LINK`) and appends `cmpayments/payment/notification?id=<increment_id>`.
3. **Call webhook.** `notify()` sends a `GET` request with Magento's cURL client (30 s timeout, redirects followed) and returns the HTTP status and body.

The command runs in the `crontab` area. `NotificationResyncer` is injected through a generated proxy (see `etc/di.xml`), so it is only built when the command actually runs, not every time `bin/magento` starts.

## Logging

The module writes nothing to `var/log`. All output goes to the console. To keep a record, redirect it:

```bash
bin/magento lotsofpixels:cm:resync-notifications --days 3 | tee var/log/cm_resync_$(date +%F).log
```

What the notification itself does is handled and logged by `CM_Payments`.

## Uninstall

```bash
bin/magento module:disable Lotsofpixels_CmNotificationResync
bin/magento setup:upgrade
rm -rf app/code/Lotsofpixels/CmNotificationResync
```
