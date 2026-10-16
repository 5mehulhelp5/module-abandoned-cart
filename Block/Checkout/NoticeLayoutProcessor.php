<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Block\Checkout;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\Notification\Api\OptOutNoticeInterface;
use Magento\Checkout\Block\Checkout\LayoutProcessorInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The reminder notice under the guest email field: the shipping step, and the payment step of virtual carts. Shown
 * only while reminders are on and Notification's opt-out notice resolves to a text for this store.
 */
class NoticeLayoutProcessor implements LayoutProcessorInterface
{
    private const STEPS = ['shipping-step' => 'shippingAddress', 'billing-step' => 'payment'];

    public function __construct(
        private readonly Config $config,
        private readonly OptOutNoticeInterface $notice,
        private readonly StoreManagerInterface $stores
    ) {
    }

    /**
     * @param array<string, mixed> $jsLayout
     * @return array<string, mixed>
     */
    public function process($jsLayout)
    {
        $storeId = (int)$this->stores->getStore()->getId();
        if (!$this->config->isEnabled($storeId)) {
            return $jsLayout;
        }
        $text = $this->notice->getText(Config::NOTICE_GROUP, $storeId);
        if ($text === null) {
            return $jsLayout;
        }
        foreach (self::STEPS as $step => $parent) {
            if (!isset($jsLayout['components']['checkout']['children']['steps']['children'][$step]['children'][$parent]
                ['children']['customer-email'])) {
                continue;
            }
            $jsLayout['components']['checkout']['children']['steps']['children'][$step]['children'][$parent]['children']
                ['customer-email']['children']['before-login-form']['children']['cartreminder-notice'] = [
                    'component' => 'uiComponent',
                    'template' => 'CommerceKings_AbandonedCart/notice',
                    'noticeText' => $text,
                ];
        }
        return $jsLayout;
    }
}
