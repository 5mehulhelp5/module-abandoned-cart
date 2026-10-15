<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Plugin;

use CommerceKings\AbandonedCart\Api\GuestEmailCaptureInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Filter\FilterManager;
use Magento\Framework\Validator\EmailAddress;
use Magento\Framework\Validator\StringLength;

/** Validate primitive API input before the capture service can change a cart's reminder state. */
class ValidateGuestEmailCapture
{
    public function __construct(
        private readonly EmailAddress $emailValidator,
        private readonly StringLength $emailLengthValidator,
        private readonly FilterManager $filterManager
    ) {
    }

    /**
     * @param GuestEmailCaptureInterface $subject
     * @param string $cartId
     * @param mixed $email
     * @return array
     * @throws InputException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeCapture(GuestEmailCaptureInterface $subject, string $cartId, mixed $email): array
    {
        $email = $this->filterManager->get('stringTrim')->filter($email);
        // Only the explicit empty string means clear; native validators reject false and other raw types.
        if ($email === '') {
            return [$cartId, $email];
        }
        $exception = new InputException();
        if (!$this->emailLengthValidator->isValid($email)) {
            $exception->addError(__('The email must be a string within the allowed length.'));
        }
        if (!$this->emailValidator->isValid($email)) {
            $exception->addError(__('The email address has an invalid format.'));
        }
        if ($exception->wasErrorAdded()) {
            throw $exception;
        }
        return [$cartId, $email];
    }
}
