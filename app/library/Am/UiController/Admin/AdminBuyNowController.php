<?php

class AdminBuyNowController extends Am_Mvc_Controller_Grid
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_FORM);
    }

    function init()
    {
        $this->view->headStyle()->appendStyle(<<<CUT
            .form_url .std-am-copy-to-clipboard-trigger {
                visibility: hidden;
            }

            .form_url:hover .std-am-copy-to-clipboard-trigger {
                visibility: visible;
            }
CUT
        );
        parent::init();
    }

    public function createGrid()
    {
        $ds = new Am_Query($this->getDi()->buttonTable);
        $grid = new Am_Grid_Editable('_button', ___('BuyNow Buttons'), $ds, $this->_request, $this->view);
        $grid->setPermissionId(Am_Auth_Admin::PERM_FORM);
        $grid->setRecordTitle(___('BuyNow Button'));
        $grid->setEventId('gridButton');

        $grid->addField('title', ___('Title'));
        $grid->addField('billing_plan_id', ___('Product'))
            ->setFormatFunction([$this, 'renderProductId']);
        $grid->addField('saved_form_id', ___('Form'))
            ->setFormatFunction([$this, 'renderFormId']);
        $grid->addField('paysys_id', ___('Payment System'));
        $grid->addField(new Am_Grid_Field_Expandable('hash', ___('Code'), true))
             ->setAjax($this->getDi()->url('admin-buy-now/code?hash={hash}', false));
        $grid->addField('_link', ___('Link'), false)
            ->setGetFunction(function($r, $g, $fn, $fo) {
                return $this->getDi()->surl("buy/$r->hash");
            })
            ->setRenderFunction(function($r, $fn, $g, $fo) {
                return sprintf('<td class="form_url">%s</td>', $fo->get($r, $g, $fn));
            })
            ->addDecorator(new Am_Grid_Field_Decorator_CopyToClipboard)
            ->addDecorator(new class extends Am_Grid_Field_Decorator_Abstract {
                //Truncate Root Url
                public function render(&$out, $obj, $grid)
                {
                    $out = preg_replace_callback(
                        '|(<span.*?>)(.+)(</span>)|s',
                        fn($m) => sprintf(
                            '%s<a target="_blank" href="%s" class="link">%s</a>%s',
                            $m[1],
                            $m[2],
                            str_replace(ROOT_URL . '/', '', $m[2]),
                            $m[3],
                        ),
                        $out
                    );
                }
            }, Am_Grid_Field::DEC_LAST);
        $grid->setForm([$this, 'createForm']);
        $grid->setFormValueCallback('paysys_id', ['RECORD', 'unserializeList'], ['RECORD', 'serializeList']);
        $grid->addCallback(Am_Grid_Editable::CB_VALUES_TO_FORM, function(& $v, $r) {
            if (empty($v['hash'])) {
                $v['hash'] = $this->getDi()->security->randomString(12);
            }
        });

        $grid->setFilter(new Am_Grid_Filter_Text('', ['title' => 'LIKE', 'hash' => '='], ['placeholder' => ___('Title/Path')]));

        return $grid;
    }

    public function codeAction()
    {
        $hash = $this->_request->getFiltered('hash');
        $u = $this->getDi()->surl(['buy/%s', $hash], false);
        $url = Am_Html::escape($u);
        $urlj = json_encode($u, JSON_UNESCAPED_SLASHES);

        $link = Am_Html::escape("<a href=\"$url\">Order Now</a>");
        $btn = Am_Html::escape("<button onclick='window.location=$urlj;'>Order Now</button>");

        echo <<<CUT
        <strong>Purchase Link</strong>
        <p><code>$url</code></p>
        <br />
        <strong>Use an HTML link to start purchase</strong>
        <p><code>$link</code></p>
        <br>
        <strong>Use an HTML button to start purchase</strong>
        <p><code>$btn</code></p>
CUT;
    }

    public function renderProductId($billing_plan_id = null)
    {
        static $opts;
        if (!$opts) $opts = $this->getDi()->billingPlanTable->getOptions();
        return $opts[$billing_plan_id] ?? '';
    }

    public function renderFormId($saved_form_id = null)
    {
        static $opts;
        if (!$saved_form_id) return '';
        if (!$opts) $opts = $this->getDi()->savedFormTable->getOptions(SavedForm::T_SIGNUP);
        return $opts[$saved_form_id] ?? '';
    }

    public function checkPaysystem($paysys_id, $el)
    {
        $frm = $el;
        while ($frm->getContainer()) $frm = $frm->getContainer();
        $vars = $frm->getValue();
        if (empty($vars['paysys_id'])) return;

        $bp = $this->getDi()->billingPlanTable->load($vars['billing_plan_id']);
        $pr = $bp->getProduct();

        foreach ($vars['paysys_id'] as $paysys_id) {
            $invoice = $this->getDi()->invoiceTable->createRecord();
            $invoice->paysys_id = $paysys_id;
            $invoice->user_id = $this->getDi()->db->selectCell("SELECT user_id FROM ?_user LIMIT 1");
            $invoice->add($pr);
            $invoice->calculate();

            $ps = $this->getDi()->plugins_payment->loadGet($invoice->paysys_id);
            if ($err = $ps->isNotAcceptableForInvoice($invoice)) {
                return "This payment system [{$paysys_id}] is not acceptable for invoice: " . implode(";", $err);
            }
        }
    }

    public function createForm()
    {
        $form = new Am_Form_Admin();

        $form->addText('title', 'class=am-el-wide')
            ->setLabel(___("Title"))
            ->addRule('required');
        $form->addText('comment', 'class=am-el-wide')
            ->setLabel(___("Comment"));

        $form->addText('hash', ['class' => 'am-el-wide'])
            ->setId('button-hash')
            ->setLabel(___("Path\n" .
                'will be used to construct user-friendly url'))
            ->addRule('required')
            ->addRule('regex', ___('Only a-zA-Z0-9_- is allowed'), '/^[a-zA-Z0-9_-]+$/')
            ->addRule('callback2', null, [$this, 'checkHash']);

        $button_url = $this->getDi()->rurl('buy/');

        $form->addStatic()
            ->setLabel(___('Permalink'))
            ->setContent(<<<CUT
<div data-button_url="$button_url" id="button-permalink"></div>
CUT
        );

        $form->addScript()
            ->setScript(<<<CUT
jQuery('#button-hash').bind('keyup', function(){
    jQuery('#button-permalink').html(jQuery('#button-permalink').data('button_url') + encodeURIComponent(jQuery(this).val()).replace(/%20/g, '+'))
}).trigger('keyup')
CUT
        );

        $form->addSelect('billing_plan_id', ['class' => 'am-combobox'])
            ->setLabel(___("Product"))
            ->loadOptions($this->getDi()->billingPlanTable->getOptions())
            ->addRule('required');

        $g = $form->addGroup()
            ->setLabel(___('Coupon'))
            ->setSeparator(' ');
        $g->addText('coupon');
        $g->addAdvCheckbox('use_coupons', null, ['content' => 'allow user to use any coupons']);

        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('[name=use_coupons][type=checkbox]').change(function(){
        if (this.checked) {
            jQuery('[name=coupon]').val('').prop('disabled', true);
        } else {
            jQuery('[name=coupon]').prop('disabled', false);
        }
    }).change();
});
CUT
            );

        $form->addSelect('saved_form_id')
            ->setLabel(___("Signup Form\nwill be used if user is not logged-in. " .
                "This form is used only for user registration, so all bricks " .
                "related to purchase (Product, Payment System) " .
                "will be automatically removed form it."))
            ->loadOptions([''=>'[default form]'] + $this->getDi()->savedFormTable->getOptions(SavedForm::T_SIGNUP));
        $sel = $form->addMagicSelect('paysys_id')
            ->setLabel(___("Payment System\nif none selected, all enabled will be displayed"))
            ->loadOptions(array_merge(['free'=>'Free'], $this->getDi()->paysystemList->getOptionsPublic()));
        $sel->addRule('callback2', 'err', [$this, 'checkPaysystem']);

        return $form;
    }

    function checkHash($v, $e)
    {
        $r = $this->grid->getRecord();
        $found = $this->getDi()->db->selectCell(
            'SELECT COUNT(*) FROM ?_button WHERE hash=? {AND button_id<>?}',
            $v,
            $r->isLoaded() ? $r->pk() : DBSIMPLE_SKIP
        );
        return $found ? ___('Path should be unique') : null;
    }
}