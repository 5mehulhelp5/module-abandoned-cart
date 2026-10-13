<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Block\Adminhtml\System\Config;

use CommerceKings\AbandonedCart\Model\QuoteLifetime as Lifetime;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** Display Magento's effective value without creating another editable retention setting. */
class QuoteLifetime extends Field
{
    public function __construct(Context $context, private readonly Lifetime $lifetime, array $data = [])
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
        $days = $this->lifetime->getScopeDays((string)$element->getScope(), (int)$element->getScopeId());
        return $this->escapeHtml($days > 0 ? __('%1 days', $days) : __('0 days — reminders are disabled'));
    }
}
