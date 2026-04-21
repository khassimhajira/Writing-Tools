<?php

class Am_Form_Brick_Newsletter extends Am_Form_Brick
{
    protected static $bricksAdded = 0;
    protected $labels = [
        'Subscribe to Site Newsletters' => 'Subscribe to Site Newsletters',
    ];

    protected $hideIfLoggedInPossible = self::HIDE_DESIRED;

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Newsletter');
        parent::__construct($id, $config);
    }

    public function isMultiple()
    {
        return true;
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Signup;
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $el_name = "_newsletter[" . (self::$bricksAdded++) . "]";

        if ($this->getConfig('type') == 'checkboxes')
        {
            $options = Am_Di::getInstance()->newsletterListTable->getUserOptions();
            if ($enabled = $this->getConfig('lists')) {
                $_ = $options;
                $options = [];
                foreach ($enabled as $id) {
                    $options[$id] = $_[$id];
                }
            }
            $user = Am_Di::getInstance()->auth->getUser();
            if ($this->getConfig('hide_if_subscribed') && $user) {
                /** @var NewsletterUserSubscriptionTable $table */
                $subscribed_ids = Am_Di::getInstance()->newsletterUserSubscriptionTable->getSubscribedIds($user->pk());
                $options = array_filter($options, function($id) use ($subscribed_ids) { return !in_array($id, $subscribed_ids);}, ARRAY_FILTER_USE_KEY);
            }
            if (!$options) return; // no lists enabled
            $group = $form->addGroup($el_name)->setLabel($this->___('Subscribe to Site Newsletters'));
            if ($this->getConfig('required')) {
                $group->addClass('am-row-required');
            }
            $group->setSeparator("<br />\n");
            foreach ($options as $list_id => $title)
            {
                $c = $group->addAdvCheckbox($list_id)->setContent($title);
                if (!$this->getConfig('unchecked') && ($_SERVER['REQUEST_METHOD'] != 'POST')) {
                    $c->setAttribute('checked');
                }
                if ($this->getConfig('required')) {
                    $c->addRule('required');
                }
            }
        } else {
            $data = [];
            if ($this->getConfig('no_label')) {
                $data['content'] = $this->___('Subscribe to Site Newsletters');
            }
            $c = $form->addAdvCheckbox($el_name, [], $data);
            if (!$this->getConfig('no_label')) {
                $c->setLabel($this->___('Subscribe to Site Newsletters'));
            }
            if (!$this->getConfig('unchecked') && ($_SERVER['REQUEST_METHOD'] != 'POST')) {
                $c->setAttribute('checked');
            }
            if ($this->getConfig('required')) {
                $c->addRule('required');
            }
        }
    }

    public function initConfigForm(Am_Form $form)
    {
        $el = $form->addSelect('type', ['class'=>'newsletter-type-select'])->setLabel(___('Type'));
        $el->addOption(___('Single Checkbox'), 'checkbox');
        $el->addOption(___('Checkboxes for Selected Lists'), 'checkboxes');

        $form->addAdvCheckbox('no_label', ['class' => 'newsletter-am-no-label'])
            ->setLabel(___("Hide Label"));

        $lists = $form->addSortableMagicSelect('lists', ['class'=>'newsletter-lists-select'])
            ->setLabel(___("Lists\n" .
                'All List will be displayed if none selected'));
        $lists->loadOptions(Am_Di::getInstance()->newsletterListTable->getAdminOptions());

        $form->addAdvCheckbox('hide_if_subscribed', ['class' => 'hide-if-subscribed'])
            ->setLabel('Hide List if User is already subscribed');

        $form->addAdvCheckbox('unchecked')
            ->setLabel(___("Default unchecked\n" .
                'Leave unchecked if you want newsletter default to be checked'));

        $form->addAdvCheckbox('required')
            ->setLabel(___("Subscription is required?"));

        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    if (!window.brick_newsletter_init) {
        window.brick_newsletter_init = true;

        jQuery(document).on('change', '.newsletter-type-select', function(){
            const scope = jQuery(this).closest('form');
            const val = jQuery(this).val();

            jQuery(".newsletter-lists-select", scope).closest('.am-row').toggle(val == 'checkboxes');
            jQuery(".hide-if-subscribed", scope).closest('.am-row').toggle(val == 'checkboxes');
            jQuery('.newsletter-am-no-label', scope).closest('.am-row').toggle(jQuery(this).val() == 'checkbox')
        });
    }
    jQuery('.newsletter-type-select').change();
});
CUT
            );
    }
}