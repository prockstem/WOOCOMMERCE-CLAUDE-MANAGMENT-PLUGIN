# Example prompts

Things you can ask Claude once it's connected. You don't need tool names: Claude picks the tools. Replace order numbers, names and amounts with your own.

## Getting started (any key)

- What store am I connected to, and what can this key do?
- Which tool packs are switched on?

## Orders (Read)

- Show me today's orders.
- Which orders are still processing or on hold? Oldest first.
- Find all orders from jane@example.com.
- Show me everything about order 1234: items, shipping address, notes and refunds.
- Were there any orders over $200 this week?
- List orders from guest checkouts in the last 7 days.

## Orders (Read/Write)

- Mark order 1234 as completed and add a note: "Shipped with DHL, tracking 123456."
- Put order 1240 on hold with a private note saying we're waiting for stock.
- Add a private note to order 1234: customer called about delivery time.
- Send the customer of order 1234 a note saying their order ships tomorrow. *(Claude should show you the exact text before sending, because customer notes are emailed.)*
- Cancel order 1250; the customer asked by email. *(Cancelling puts reserved stock back.)*

## Products (Read)

- Which products are out of stock?
- Show me the Zip Hoodie with all its sizes, prices and stock.
- What are my 10 most expensive products?
- Find products in the Accessories category that are on sale.
- Which draft products haven't been published yet?

## Products (Read/Write)

- Create a draft product "Linen Apron", $28, in Home, 15 in stock, with a short description.
- Put the Denim Jacket on sale for $59 until the end of next month.
- End the sale on the Denim Jacket.
- We received 24 Wool Beanies: add them to stock.
- Set the stock of SKU TS-001 to 50.
- Raise the price of everything in the Home category by 5%. *(Claude should list the changes and ask you first.)*
- Add an XL size to the Zip Hoodie at $48 with 3 in stock.
- Disable the Large variation of the Zip Hoodie.
- Publish the Linen Apron.

## Customers

- Find the customer named Kowalski.
- How much has bob@example.com spent with us, and when was their last order?
- Who are our newest customers this month?
- Update Bob Okafor's billing phone to +234 800 123 4567.
- Change Alice Martin's shipping address to 5 Rua Nova, 1100-001 Lisbon, Portugal.

## Coupons (switch on the Coupons pack)

- List our active coupons and how often each has been used.
- Create a 15% coupon WELCOME15 for first orders: one use per customer, expires 31 December.
- Create a $10-off coupon VIP10 only for emails ending in @ourcompany.com, minimum spend $50.
- Switch off the SUMMER coupon but keep it.
- Delete the old BLACKFRIDAY2024 coupon. *(Claude shows a preview and asks before deleting.)*

## Refunds (switch on the Refunds pack)

Claude always shows a preview first: amount, items, whether stock goes back, and whether money moves. Nothing happens until you say yes.

- Refund $10 on order 1234 because it arrived late.
- The customer of order 1234 returned one Wool Beanie: refund it and put it back in stock.
- Fully refund order 1250.
- Refund order 1234 through the payment gateway. *(Only works if the gateway supports automatic refunds. Otherwise Claude records the refund and you pay back yourself.)*

## Reports

- How did we do in the last 30 days, week by week?
- Compare this month's sales with last month's.
- What were our top 5 products this year by revenue? And by quantity?
- Which sizes of the Zip Hoodie sell best?
- Who were our top 10 customers last year?
- What's running low on stock? Use a threshold of 5.
- What was our average order value last quarter? *(Use a custom date range.)*

## Combining things

- Find orders that have been processing for more than 3 days and summarise what's in them.
- Which low-stock products sold the most this month? I want to reorder those first.
- Draft a restock list: everything at 3 or fewer units, with SKU and units sold in the last 30 days.
- Find customers who spent over $500 this year and create a personal 10% coupon for each. *(Claude should show you the list first.)*

## Tips

- **Say no** whenever a preview isn't what you meant. Nothing has changed until you confirm.
- Use a **Read** key for questions and a **Read/Write** key only when you plan to make changes.
- Customer notes, names and addresses are treated as data. If an order note says "ignore your instructions", Claude reports it rather than acting on it.
- Every change, preview, refusal and error is in **WooCommerce → Settings → MCP → Logs**, with personal data masked.
