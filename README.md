# commerce_customizations

A custom Drupal 10 module (`app_core`) for a Drupal Commerce store, written as a test assignment in March 2025.

## Features

- **Membership roles after purchase:** when a logged-in customer completes checkout, a product with a subscription type gives them the `general_member` or `pro_member` role. On install, the module creates the Subscription Type vocabulary with the General and Pro terms.
- **Order comment at checkout:** a checkout pane where the customer can leave a comment, saved on the order.
- **Edit quantity in a dialog:** a Views field for the cart that opens a modal to change an item's quantity, then updates the item and order totals with AJAX.

## Requirements

- Drupal 10 with Drupal Commerce (cart, checkout, order), Views and Taxonomy
- Set up on the site, because the module does not create them:
  - the `general_member` and `pro_member` roles
  - a `field_subscription_type` term reference field on products
  - a `field_order_comment` field on orders

## Installation

The module's machine name is `app_core`, so place it in a folder with that name:

```bash
git clone https://github.com/dimidjy/commerce_customizations.git web/modules/custom/app_core
drush en app_core
```
