<?php

// replace to your access token
use Adrianovcar\Asaas\Adapter\GuzzleHttpAdapter;
use Adrianovcar\Asaas\Asaas;
use function Pest\Faker\fake;

global $asaas, $adapter, $customer;

test('avoid dd, dump, ray, ds')
    ->expect(['dd', 'dump', 'ray', 'ds'])
    ->not->toBeUsed();


$accessToken = '$aact_YTU5YTE0M2M2N2I4MTliNzk0YTI5N2U5MzdjNWZmNDQ6OjAwMDAwMDAwMDAwMDAwMDE2OTc6OiRhYWNoXzEzZmZmMDE0LWFhM2MtNDIwZS1iMmFmLTA4YzcwNjY4MDkxNA==';
$adapter = new GuzzleHttpAdapter($accessToken);
$asaas = new Asaas($adapter, 'sandbox');

test('init asaas class', function () use ($asaas, $adapter) {
    $null_user = $asaas->customer()->getByEmail('user@notexists.com');
    expect($null_user)->toBeNull();
});

test('list-users', function () use ($asaas, $adapter) {
    $customers = $asaas->customer()->getAll();
    $customer_id = $customers['data'][0]['id'] ?? false;

    if (!$customer_id) {
        $defaultData = [
            'name' => fake('pt_BR')->name(),
            'email' => fake('pt_BR')->email(),
            'cpfCnpj' => cpf::cpfRandom(),
            'company' => fake('pt_BR')->company(),
            'phone' => fake('pt_BR')->phoneNumber(),
            'mobilePhone' => fake('pt_BR')->phoneNumber(),
        ];

        $customer = $asaas->customer()->create($defaultData);
        $customer_id = $customer->id;
    }

    $customers = $asaas->customer()->getAll();
    expect($customers)->toBeArray();
});
