<?php
declare(strict_types=1);

namespace RabbitCMS\Payments\Platon;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use RabbitCMS\Payments\Factory;

/**
 * Class ModuleProvider
 *
 * @package RabbitCMS\Payments\Platon
 */
class ModuleProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->extend('payments', function (Factory $payments) {
            return $payments->extend('platon', function (Factory $payments, array $config) {
                return new PlatonPaymentProvider($payments, $config);
            });
        });
    }
}