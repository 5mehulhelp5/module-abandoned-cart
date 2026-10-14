<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\Consent\Api\Data\WithdrawalContext;
use CommerceKings\Consent\Api\WithdrawalHandlerInterface;

/** Clear module-owned reminder data for both guest and customer carts after withdrawal. */
class WithdrawalHandler implements WithdrawalHandlerInterface
{
    public function __construct(private readonly ConsentWithdrawal $withdrawal)
    {
    }

    public function execute(WithdrawalContext $context): void
    {
        $this->withdrawal->forConsents($context->consentIds);
    }
}
