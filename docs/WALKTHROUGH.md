# System walkthrough: demo setup and script

For recording the screen walkthrough of the HPOS PHP system. About 12 minutes. The script follows the topics from the
last meeting, and the last section lists what was discussed but is **not in the system yet**, so you can say so on the
recording rather than be asked.

## Run the demo

A demo factory is loaded in its own database (`hpos_demo`), separate from the clean working database.

```powershell
cd hpos-php
pwsh tools/demo.ps1          # http://127.0.0.1:8082   (Ctrl+C to stop)
```

Sign in as `admin@himalayaplast.com` (password in `hpos-php/config/dev-admin.txt`).

What is in it: 3 extruders; customers Alpha Auto Components and Sunrise Windows (Gujarat) and Delhi Door Systems (another
state, so its invoices take IGST); suppliers Polymer Supplies and ColourChem; 3 raw materials, 3 finished goods with
recipes, packaging and a scrap item; opening stock and one received purchase order; and four orders:

| Order | Customer | Stage |
|---|---|---|
| SO-2026-00001 | Sunrise Windows | Draft, ready to confirm on camera |
| SO-2026-00002 | Delhi Door Systems | Confirmed, work order waiting for a job card |
| SO-2026-00003 | Alpha Auto Components | In production, 1,200 of 3,000 m made, 6.5 kg scrap |
| SO-2026-00004 | Alpha Auto Components | Dispatched (4 cartons scanned), invoiced, 20,000 of 47,200 paid |

The alert bell is filled by the cron job, which has been run once on the demo (`php tools/cron.php`, with `HPOS_CONFIG`
set to the demo config). Run it again before recording if you change stock levels.

To rebuild it from scratch: recreate the database, load the twenty SQL files (see `database/mysql/README.md`), set the
administrator's password, then run `php tools/demo_seed.php` with `HPOS_CONFIG` pointing at `config/demo-config.php`.
The seeder refuses any database that is not named `*_demo`, `*_scratch` or `*_test`, or that already has customers.

## Script

Keep the browser at about 1280 px wide so the sidebar and tables both show.

**1. Sign in and the dashboard (1 min)**
- Sign in, land on the dashboard. Point at the factory status lines, the shortcut cards and the tiles: open orders,
  in production, machines running, low stock, receivables.
- Sidebar: it follows the order work moves through the factory: Sales, Buying, Stock, Production, Quality and dispatch,
  Accounts, then Masters and Admin. Each person only sees what their role may use.
- Use the search box: type `alpha` and show the live results; open one.
- **What to do today** (just under the shortcuts): the list HPOS builds for this person: orders that will miss their date,
  material to order, a machine nearly out of material, QC readings out of range or due, customers due to reorder, leads to
  follow up. Each line links to the screen where a person acts; nothing is ordered or sent by itself.

**2. Order tracking (2 min)**
- Sales, Orders. The status tabs and the produced / cartons-scanned columns show where every order is.
- Open SO-2026-00003: line items, the raw-material check (needed, in stock, reserved, short by), and the status.
- Open SO-2026-00001 (draft): show the material check, then **Confirm order**. Say what confirming does: it will not
  confirm if raw material is short (the hard gate), it reserves the material and creates the work order.
- Open SO-2026-00004: the delivery note, cartons and the invoice link.
- Deadlines: every open order with metres left, the date the machines will finish it and a health badge (on track, at risk,
  late). The order page says the same in one sentence with a suggested delivery date; the date is still typed by a person.
- Reorder watch: how often each customer orders, who is due now, who has gone quiet. **Create follow-up** is a confirm step; it
  adds the reminder to the customer. Customer leads: a simple list (new, contacted, quoted, won, lost) that converts into a customer.

**3. Inventory (2 min)**
- Stock: raw material and finished goods, with in stock, reserved and available. PVC and plasticiser are healthy;
  carbon black is close to its minimum (47 kg free against 50); the carton box is at zero against a minimum of 100,
  which is what the low-stock tile and the alert bell are showing.
- Stock ledger: every movement, append-only. Batches: each lot with its source.
- Adjust stock: add opening stock or write off damage; show that removing more than the free stock is refused.
- Stock, Receive material: no barcodes needed. Pick the raw material, the number of bags and the weight of one bag; add the
  supplier's lot or bill number if you want, otherwise HPOS numbers the lot.
- Machine stock: move 50 sacks next to a machine (**Issue to a machine**) and give back what was not used. The machine's own
  stock is used up as it makes metres; the card shows hours left and flags a machine that is nearly out.

**4. Suppliers, bills of material and buying (1.5 min)**
- Masters, Suppliers and Items. Masters, BOMs: open Gasket A101: material per 100 m and the scrap percentage.
- Buying, Purchase orders: one received, one draft. Open one: Submit, Receive everything, or draft a goods receipt.
- On an order short of material, **Create shortage PO** raises the purchase order for the gap.
- Buying advice: which raw materials to order now or soon, how many days of stock are left, and what is already on order. Tick
  the lines for one supplier and press **Create draft purchase order**; HPOS never orders by itself.

**5. Production and shift handling (2.5 min)**
- Production, Work orders: the plan against what is made. Open one and show the job cards and the raw material needed.
  The dispatched order's work order is Completed on its own: the last job card was completed with the full quantity made.
- Shop floor: the big-button tablet screen. Press **+500 m** on EXT-01: stock, work order and order progress move
  together. Add scrap in kg.
- Planning board: six machines against the next two weeks; each planned job is a bar. Click an unplanned job's **Plan** link, give
  dates, and see the bar appear; plan a second job over the same days to see the overlap warning.
- Checklist: on the shop-floor card press **Checklist**; tick material verification, record the air pressure, mark die change
  as not needed. The card shows "Checklist 3/8".
- Mixing check: choose the two raw-material lots from the list; a wrong lot is named in the message.
- Downtime: report a stop, then **Back in service**; the history keeps the minutes.
- Shift handovers: record a handover with scrap and notes; accept it as the next shift.

**6. Quality, cartons and dispatch (1.5 min)**
- QC machines: one row per machine with its running batch, its open sheet and whether a reading is due. **Start sheet** on a
  running machine; die, batch, product and colour fill in. Add a reading every half hour; a reading outside the product's limits
  turns red and alerts QC. Print it like the paper sheet or download the CSV. Limits and colour are set on the item page.
- QC sheet setup: the client's own sheet. Rename "Pin size", add a field such as thickness (a number with limits) or a choice such
  as Look, rename "Die no." to their word, switch on a spare header field. The sheet, its print and the CSV follow at once.
- Quality: record an inspection against a batch; pass, rework, reject or scrap.
- Dispatch, Pack an order: cartons with serial numbers; **Print labels** shows the readable code and barcode.
- Open a delivery note and tick the cartons as loaded (no scanner needed); the last one makes the order Dispatched and stock leaves.

**7. Accounts and the Book Keeper (2 min)**
- Sales invoices: open INV-2026-00001: GST split (CGST + SGST for Gujarat, IGST for other states), amount in words, print.
- Payments: record a payment against the invoice with the picker; show Part paid, then Paid.
- Receivables (ageing) and Payables.
- Exports: the Book Keeper CSV and the TallyPrime XML for an order.
- Credit and debit notes: from a posted invoice press **Credit note**, post it, and watch the invoice balance drop.
- Books: the overview (customers owe you, cash and bank), the day book, a ledger, then Reports: trial balance, profit and loss,
  balance sheet and the GST summary. Every invoice, bill, payment and note above posted to the books by itself.

**8. Notifications and push (1 min)**
- Open the bell. Each person sees only the alerts that fit their role; reading one marks it read for that person only.
- Turn on **Push notifications** (Notifications page, **Turn on for this browser**, then **Send me a test**). Then, in a second
  window, report a machine stop on the shop floor or record a payment: a pop-up appears within about 20 seconds, and a push
  notification arrives even if this tab is closed. Push needs HTTPS on a live site; localhost works for the demo.

**9. Close (30 s)**
- Users: roles and who sees what. My profile: name, password and the light / dark switch.

## Discussed in the meeting but not in the system yet

Say these on the recording as "next", so they are not mistaken for gaps you missed.

| Topic from the meeting | State today |
|---|---|
| Customer dashboard for order tracking (customers sign in and see their own orders, cartons and dispatch) | Not built. Order tracking is for the factory's own staff. The earlier Next.js version had a portal page; the PHP version does not. |
| WhatsApp and email integration | Not built. An order records that it was approved by WhatsApp or email, but nothing is sent or received. The system suggests who to call and what to buy; a person does it. |
| Machine details: size, weight, serial number | Not recorded. A workstation has a code, name, hourly rate and status only. |
| Manual entry of batch numbers and weights for 20 to 30 cartons per machine | Cartons carry a metre quantity and a code. There is no weight per carton and no manual batch entry per carton. |
| Material recovery | The stock ledger has a recovery movement, but no screen records recovered material going back into stock. |
| Transaction reports and entry limits | Lists, ageing, ledger and exports exist. There is no report builder, and "entry limits" was not defined in the notes, so nothing is built for it. |

Everything else in the notes (inventory, supplier management, bills of material, work orders, shift handling, bookkeeper
invoices and payments) is built and was exercised end to end.
