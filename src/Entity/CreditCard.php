<?php

namespace Adrianovcar\Asaas\Entity;

use function Pest\Faker\fake;

/**
 * Credit Card Entity
 *
 * Credit card information entity to be used in payments
 * @author Adriano Carrijo <adrianovieirac@gmail.com>
 */
final class CreditCard extends AbstractEntity
{
    public string $holderName;
    public string $number;
    public string $expiryMonth;
    /**
     * @var string Expiry year with 4 digits
     */
    public string $expiryYear;
    public string $ccv;

    /**
     * This method fill the entity with a wrong credit card, because when you are on sandbox environment
     * all credit card are approved, so this method is usefully to test the error flow
     *
     * @param  string  $type
     * @return void
     */
    public function fillWrong(string $type = 'Visa'): CreditCard
    {
        $this->number = $type === 'Visa' ? '4916561358240741' : '5184019740373151';
        $this->holderName = fake('pt_BR')->name();
        $this->expiryMonth = date('m');
        $this->expiryYear = date('Y');
        $this->ccv = '123';

        return $this;
    }

    /**
     * Use the follow credit card info to have your transaction approved. (only in Sandbox environment)
     * @return $this
     */
    public function fill(): CreditCard
    {
        $this->number = '4444 4444 4444 4444';
        $this->holderName = fake('pt_BR')->name();
        $this->expiryMonth = date('m');
        $this->expiryYear = date('Y', strtotime('+1 YEAR'));
        $this->ccv = '123';

        return $this;
    }
}
