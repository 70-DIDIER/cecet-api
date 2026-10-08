<?php

namespace App\Domain\Membership\Enums;

enum ActivationMode: string
{
    case Payment = 'payment';
    case DemoGatewayBypass = 'demo_gateway_bypass';
}
