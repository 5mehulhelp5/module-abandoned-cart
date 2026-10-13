<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Block\Adminhtml\System\Config;

use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** Display the configured delivery policy; this field does not save a setting. */
class ReminderPolicy extends Field
{
    public function __construct(Context $context, private readonly ReminderPolicyInterface $policy, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element)
    {
        $element->unsCanUseWebsiteValue()->unsCanUseDefaultValue()->unsCanRestoreToDefault();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->escapeHtml($this->policy->getDescription());
    }
}
