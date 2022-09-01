# DropShipCSEngine

# Running System(SAMPLE ONLY):
- Please note that this is not a fully functioning stand alone module. This is to serve as a sample for my portfolio.
- This makes several calls to a function which returns a string from a MySQL database.
This function, the related database, and several other points of data have been REDACTED to protect proprietary data.

# Systems Use:
To provide semi-autonomous customer service for orders that have been drop shipped from a supplier directly to an end customer.

# CSZendeskEventAgent.php:
- Responsible for processing and following up on tickets in Zendesk.
- Reaches out to the supplier and processes their response by updating the order, replying to the customer and solving the ticket.
- Processes new cancellation, return, order status, product availability and quote requests from customers.
- Processes new emails from suppliers sent to support inbox containing order updates, cancellation notices, or return authorizations.

# CSMagentoEventAgent.php:
- Responsible for monitoring order events in Magento, and taking customer service related action when needed.
- alert_ship (contacts supplier if order has not shipped by promised ship date)
- alert_ack (contacts supplier if they have not acknowledged a new order by set deadline)
- order_owner (any tickets associated with order will be assigned to order owner in Zendesk)
- alert_submit (notifies internal customer service team that an order has not been submitted to the supplier on time)

# system requirements:
- Zendesk
- Zendesk Side conversations extension
- Zendesk Sandbox
- Magento 2
- Amasty custom attribute extension

