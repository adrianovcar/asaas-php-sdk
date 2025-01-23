<?php

namespace Adrianovcar\Asaas\Api;

use Adrianovcar\Asaas\Entity\Subscription as SubscriptionEntity;
use Adrianovcar\Asaas\Entity\UpdatableSubscription;
use Adrianovcar\Asaas\Enums\PaymentStatus;
use Adrianovcar\Asaas\Enums\SubscriptionCycle;
use DateTime;
use Exception;
use stdClass;

/**
 * Subscription API Endpoint
 *
 * @author Adriano Carrijo <adrianovieirac@gmail.com>
 * @author Agência Softr <agencia.softr@gmail.com>
 */
class Subscription extends AbstractApi
{
    /**
     * Get all subscriptions
     *
     * @param  array  $filters  (optional) Filters Array
     * @return  array  Subscriptions Array
     */
    public function getAll(array $filters = []): array
    {
        $subscriptions = $this->adapter->get(sprintf('%s/subscriptions?%s', $this->endpoint, http_build_query($filters)));
        $subscriptions = json_decode($subscriptions);
        $this->extractMeta($subscriptions);

        return array_map(function ($subscription) {
            return new SubscriptionEntity($subscription);
        }, $subscriptions->data);
    }

    /**
     * Get Subscriptions By Customer Id
     *
     * @param  string  $customerId  Customer Id
     * @return  SubscriptionEntity[]
     */
    public function getByCustomer(string $customerId): array
    {
        $subscriptions = $this->adapter->get(sprintf('%s/customers/%s/subscriptions?%s', $this->endpoint, $customerId));
        $subscriptions = json_decode($subscriptions);
        $this->extractMeta($subscriptions);

        return array_map(function ($subscription) {
            return new SubscriptionEntity($subscription->subscription);
        }, $subscriptions->data);
    }

    /**
     * Change subscription plan
     *
     * @throws Exception
     */
    public function changePlan(SubscriptionEntity $current_subscription, UpdatableSubscription $new_subscription, $block_if_in_debt = false): SubscriptionEntity
    {
        if ($block_if_in_debt) {
            if ($this->inDebt($current_subscription->id)) {
                throw new Exception('Subscription has payment pending', 402);
            }
        }

        return $this->update($new_subscription);
    }

    /**
     * Check if the subscription is in debt
     *
     * @param  string  $subscription_id
     * @return bool
     */
    public function inDebt(string $subscription_id): bool
    {
        return (bool) self::getPaymentsInDebt($subscription_id);
    }

    /**
     * Get all payments considered "in debt"
     *
     * @param  string  $subscription_id
     * @return array
     */
    public function getPaymentsInDebt(string $subscription_id): array
    {
        return (self::getPayments($subscription_id, PaymentStatus::inDebt()))->data ?? [];
    }

    /**
     * Get a list of payments of a subscription
     *
     * @param  string  $subscription_id
     * @param  array  $status
     * @return stdClass Payments Array
     */
    public function getPayments(string $subscription_id, array $status = []): stdClass
    {
        $subscriptions = $this->adapter->get(sprintf('%s/subscriptions/%s/payments?status=%s', $this->endpoint, $subscription_id, implode(',', $status)));
        $subscriptions = json_decode($subscriptions);

        foreach ($subscriptions->data as $key => $payment) {
            $subscriptions->data[$key] = new SubscriptionEntity($payment);
        }

        return $subscriptions;
    }

    /**
     * Update Subscription By Id
     *
     * @param  UpdatableSubscription  $subscription
     * @return  SubscriptionEntity
     */
    public function update(UpdatableSubscription $subscription): SubscriptionEntity
    {
        $subscription = $this->adapter->post(sprintf('%s/subscriptions/%s', $this->endpoint, $subscription->id), $subscription->toArray());
        $subscription = json_decode($subscription);

        return new SubscriptionEntity($subscription);
    }

    /**
     * Change subscription plan considering the measure of a possible pro-rata given as next due date extension.
     *
     * @throws Exception
     */
    public function changePlanWithNextDueDate(SubscriptionEntity $current_subscription, UpdatableSubscription $new_subscription, bool $pretend = true, bool $block_if_in_debt = false): SubscriptionEntity
    {
        if ($block_if_in_debt) {
            if ($this->inDebt($current_subscription->id)) {
                throw new Exception('Subscription has payment pending', 402);
            }
        }

        $new_subscription = self::estimateNextDueDate($current_subscription, $new_subscription);

        if ($pretend) {
            return new SubscriptionEntity(array_merge($current_subscription->toArray(), $new_subscription->toArray()));
        } else {
            return $this->update($new_subscription);
        }
    }

    /**
     * Estimate the possible pro-rata value when pretend to change a plan (upgrade or downgrade)
     *
     * ASAAS doesn't have a "change plan" endpoint, so we have to create a new subscription
     * To do it, we have to delete the old one and create a new one
     * If there is a pro rata balance, will be firstly credited on a separate payment (new_price - pro_rata)
     *
     * @param  SubscriptionEntity  $current_subscription
     * @param  UpdatableSubscription  $new_subscription
     * @return UpdatableSubscription
     * @throws Exception
     */
    public static function estimateNextDueDate(SubscriptionEntity $current_subscription, UpdatableSubscription $new_subscription): UpdatableSubscription
    {
        // create the next due date object from asaas's payment schedule
        $next_due_date = (new DateTime($current_subscription->nextDueDate ?? 'now'))->setTime(0, 0);
        // need to reset time due to next calc (diff)
        $today = (new DateTime())->setTime(0, 0);
        // the difference between the next due date and today
        $days_left = $next_due_date->diff($today)->days;

        // avoid create pro-rata formula if the next due date is today
        if ($days_left !== 0) {
            // the daily value of the current plan
            $current_plan_daily_value = $current_subscription->value / SubscriptionCycle::getDays($current_subscription->cycle);
            // the remaining amount to be used on current cycle
            $positive_balance = $days_left * $current_plan_daily_value;
            // the daily value of the new plan
            $new_plan_daily_value = $new_subscription->value / SubscriptionCycle::getDays($new_subscription->cycle);
            // the number of days that can be 'bought' using the remaining balance
            $days_paid_with_balance = self::calcDaysToAdd($days_left, ceil($positive_balance / $new_plan_daily_value));
            // update the next due date considering the number of days paid with balance
            $next_due_date = $today->modify("+$days_paid_with_balance days");
        }

        // update the new subscription due date
        $new_subscription->nextDueDate = $next_due_date->format('Y-m-d');

        return $new_subscription;
    }

    /**
     * Calculate the number of days to add to the new subscription
     *
     * @param  int  $days_left
     * @param  int  $days_balance
     * @return int
     */
    public static function calcDaysToAdd(int $days_left, int $days_balance): int
    {
        return min($days_balance, $days_left);
    }

    /**
     * Change subscription plan updating the value of subscription.
     * This method allow the subscription to be updated with a new value, the remaining balance will be added as a credit/
     * This method pretend to charge the customer with the new value, just in time.
     *
     * Warning: This method requires you to update the next subscription payment cycle with the regular plan price, cause
     * the value sent to the API is the plan value minus the pro-rata value (positive balance).
     *
     * @param  string  $subscription_id
     * @param  UpdatableSubscription  $new_subscription
     * @param  bool  $pretend
     * @param  bool  $block_if_in_debt
     * @return SubscriptionEntity
     * @throws Exception
     */
    public function changePlanWithBalanceUpdate(string $subscription_id, UpdatableSubscription $new_subscription, bool $pretend = true, bool $block_if_in_debt = false): SubscriptionEntity
    {
        try {
            $current_subscription = $this->getById($subscription_id);
        } catch (Exception $e) {
            throw new Exception('Subscription not found', 404);
        }

        if ($block_if_in_debt) {
            if ($this->inDebt($current_subscription->id)) {
                throw new Exception('Subscription has payment pending', 402);
            }
        }

        if (!$current_subscription->creditCard->getCreditCardToken()) {
            throw new Exception('Credit card token is required', 400);
        } else {
            $current_subscription->creditCardToken = $current_subscription->creditCard->getCreditCardToken();
        }

        $new_subscription->value = self::estimateProRataValue($current_subscription, $new_subscription);
        $new_subscription->nextDueDate = date('Y-m-d');

        $subscription = self::mergeSubscriptions($current_subscription, $new_subscription);
        $subscription->creditCard = $current_subscription->creditCard ?? null;

        if (!$pretend) {
            try {
                // create a new subscription with the new conditions
                $subscription = $this->create($subscription);

                if ($subscription->id ?? false) {
                    $this->delete($current_subscription->id);
                } else {
                    throw new Exception('New subscription fail', 500);
                }
            } catch (Exception $e) {
                throw new Exception($e->getMessage(), $e->getCode());
            }

        }

        return $subscription;
    }

    /**
     * Get Subscription By Id
     *
     * @param  string  $subscription_id
     * @return  SubscriptionEntity
     */
    public function getById(string $subscription_id): SubscriptionEntity
    {
        $subscription = $this->adapter->get(sprintf('%s/subscriptions/%s', $this->endpoint, $subscription_id));
        $subscription = json_decode($subscription);

        return new SubscriptionEntity($subscription);
    }

    /**
     * Calculate the pro-rata balance to be used on the next subscription payment cycle
     *
     * @param  SubscriptionEntity  $current_subscription
     * @param  UpdatableSubscription  $new_subscription
     * @return float
     * @throws Exception
     */
    public static function estimateProRataValue(SubscriptionEntity $current_subscription, UpdatableSubscription $new_subscription): float
    {
        // create the next due date object from asaas's payment schedule
        $next_due_date = (new DateTime($current_subscription->nextDueDate ?? 'now'))->setTime(0, 0);
        // need to reset time due to next calc (diff)
        $today = (new DateTime())->setTime(0, 0);
        // the difference between the next due date and today
        $days_left = $next_due_date->diff($today)->days;

        // avoid create pro-rata formula if the next due date is today
        if ($days_left !== 0) {
            // the daily value of the current plan
            $current_plan_daily_value = $current_subscription->value / SubscriptionCycle::getDays($current_subscription->cycle);
            // the remaining amount to be used on current cycle
            return round($new_subscription->value - ($days_left * $current_plan_daily_value), 2);
        }

        return $new_subscription->value;
    }

    /**
     * Convert a subscription object to a subscription entity object
     *
     * @param  SubscriptionEntity  $older
     * @param  UpdatableSubscription  $newer
     * @param  bool  $preserve_id
     * @return SubscriptionEntity
     */
    public static function mergeSubscriptions(SubscriptionEntity $older, UpdatableSubscription $newer, bool $preserve_id = false): SubscriptionEntity
    {
        $subscription = clone $older;
        $subscription->id = $preserve_id ? $newer->id ?? $older->id : '';
        $subscription->billingType = $newer->billingType ?? $subscription->billingType ?? null;
        $subscription->value = $newer->value ?? $subscription->value ?? null;
        $subscription->status = $newer->status ?? $subscription->status ?? null;
        $subscription->nextDueDate = $newer->nextDueDate ?? $subscription->nextDueDate ?? null;
        $subscription->discount = $newer->discount ?? $subscription->discount ?? null;
        $subscription->interest = $newer->interest ?? $subscription->interest ?? null;
        $subscription->fine = $newer->fine ?? $subscription->fine ?? null;
        $subscription->cycle = $newer->cycle ?? $subscription->cycle ?? null;
        $subscription->description = $newer->description ?? $subscription->description ?? null;
        $subscription->endDate = $newer->endDate ?? $subscription->endDate ?? null;
        $subscription->externalReference = $newer->externalReference ?? $subscription->externalReference ?? null;
        $subscription->split = $newer->split ?? $subscription->split ?? null;

        return $subscription;
    }

    /**
     * Create new subscription
     *
     * @param  SubscriptionEntity  $subscription_entity
     * @return  SubscriptionEntity
     */
    public function create(SubscriptionEntity $subscription_entity): SubscriptionEntity
    {
        $subscription = $this->adapter->post(sprintf('%s/subscriptions', $this->endpoint), $subscription_entity->toArray());
        $subscription = json_decode($subscription);

        return new SubscriptionEntity($subscription);
    }

    /**
     * Delete Subscription By Id
     *
     * @param  string  $id  Subscription Id
     */
    public function delete(string $id)
    {
        $subscription = $this->adapter->delete(sprintf('%s/subscriptions/%s', $this->endpoint, $id));
        return json_decode($subscription);
    }
}
