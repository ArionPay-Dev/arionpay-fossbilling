# ArionPay for FOSSBilling

Lets your clients pay FOSSBilling invoices with Bitcoin, USDT, Litecoin, Ethereum, TRON and more through [ArionPay](https://arionpay.com). The money goes straight to your own wallet: ArionPay never holds it.

Tested on a real FOSSBilling 0.8.9 install with PHP 8.3: payment, reuse of an open payment, late payment, payment tolerance, edited invoice, double payment and webhook checks.

## What you need

- FOSSBilling 0.8 or newer
- An ArionPay account with a store, and a wallet connected to it for real payments (test mode works without a wallet)
- FOSSBilling on a public `https://` address, so ArionPay can tell it when a payment arrives

## Install

1. Unzip `arionpay-fossbilling.zip` into your FOSSBilling folder. These files go in place:
   - `library/Payment/Adapter/ArionPay.php`
   - `library/Payment/Adapter/arionpay.png`
   - `library/Payment/Adapter/ArionPay/` (folder)
2. In the admin area, go to **System > Payment gateways**, open **New Payment Gateway** and install **ArionPay**.
3. Open ArionPay in the gateway list and fill in the keys (see below).
4. Tick **Enabled**, **Allow one time payments** and **Test mode**, then save.

## Where the keys come from

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |

Leave **ArionPay address** empty. You do not need to paste anything into ArionPay: every invoice tells ArionPay to send its payment updates to `https://your-fossbilling/ipn.php?gateway_id=N`.

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off.

## Try it first

1. Keep **Test mode** ticked. It makes ArionPay test invoices: no real money moves.
2. As a client, open an unpaid invoice, choose **ArionPay** and pay.
3. On the ArionPay page, choose a coin and use the test payment buttons.
4. The invoice should be **Paid**. Under **Invoices > Transactions** you see a transaction "[TEST - no real money] ArionPay invoice ... is paid".
5. Untick **Test mode** before you take real payments.

## How payment works

- When the client pays, the amount is added to the client's balance and the invoice is paid from it, the same way FOSSBilling's other gateways work.
- The invoice is paid only if the paid ArionPay invoice still matches it: same currency and same total. If the invoice was edited after the client went to pay, it is not paid and the transaction shows an error explaining why; add the payment by hand after checking.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard. A small difference inside your store's payment tolerance (default 1%) is accepted automatically. For anything else you decide; if you accept it, the invoice is paid here too.
- A late payment (after the ArionPay invoice expired) still pays the invoice, with a note on the transaction.
- If an invoice is paid twice, the second payment is recorded with a note asking you to check your wallet. It is not credited.
- Payment seen, partial payment, expired and "needs review" updates are recorded as transactions with a note, without paying the invoice.
- Opening the payment page again reuses the open ArionPay invoice. After it expires, a new one is made.
- Coming back from the payment page never marks an invoice paid.

## Good to know

- Your invoice currency must be one ArionPay accepts: USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK or ZAR.
- Subscriptions (automatic renewals) are not supported: crypto payments are one-off. Renewal invoices are paid like any other invoice.
- Refunds are made from your own wallet. The gateway does not send refunds.
- The gateway sends ArionPay the invoice number, amount and currency. It does not send your client's name or email.
- Keys and secrets are stored like FOSSBilling's other gateway secrets and are not shown again in the admin area.

## Build from source

The release zip is made with `python3 build.py`. It copies the [ArionPay PHP library](https://github.com/ArionPay-Dev/arionpay-php) into the plugin first, from a folder named `arionpay-php` next to this one:

```bash
git clone https://github.com/ArionPay-Dev/arionpay-php ../arionpay-php
python3 build.py
```
