<?php

namespace App\Domain\Membership\Enums;

enum ActivationMode: string
{
    case Payment = 'payment';
    case SimulatedPayment = 'simulated_payment';
    case DemoGatewayBypass = 'demo_gateway_bypass';
}
