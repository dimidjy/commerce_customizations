<?php

namespace Drupal\app_core\EventSubscriber;

use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to order events.
 */
class CheckoutCompleteSubscriber implements EventSubscriberInterface {

  /**
   * This method is called whenever the commerce_order.place.post_transition event is dispatched.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event to handle.
   */
  public function orderCompleteHandler(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $user = $order->getCustomer();

    if (!$user->hasRole('administrator') && $user->isAuthenticated()) {
      foreach ($order->getItems() as $order_item) {
        $product = $order_item->getPurchasedEntity()->getProduct();
        if ($product) {
          if ($product->hasField('field_subscription_type') && !$product->get('field_subscription_type')->isEmpty()) {
            $term = $product->get('field_subscription_type')->entity;

            if ($term) {
              $term_machine_name = $term->get('machine_name')->value;

              if ($term_machine_name === 'general_member') {
                $user->addRole('general_member');
              }
              elseif ($term_machine_name === 'pro_member') {
                $user->addRole('pro_member');
              }

              $user->save();
            }
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events['commerce_order.place.post_transition'] = [
      'orderCompleteHandler',
       -200
    ];

    return $events;
  }

}
