<?php

trait Am_Form_Brick_Conditional
{
    function addCondConfig($form, $opt = false)
    {
        $gr = $form->addGroup(null, ['class' => 'am-row-wide']);
        $gr->setSeparator(' ');
        $gr->addHtml()->setHtml(sprintf(
            '<div class="conditional-group" %s>',
            $opt ? sprintf('data-f-opt="%s" data-opt="%s"', Am_Html::escape(json_encode($opt[0])), Am_Html::escape(json_encode($opt[1]))) : ''
        ));
        $gr->addText('cond_rules', ['style' => 'display:none']);
        $gr->addAdvCheckbox('cond_enabled', [], ['content' => ___('Enable Conditional Display for This Block')]);
        $gr->addHtml()->setHtml('<br /><br />');
        $gr->addSelect('cond_type')
            ->loadOptions([
                '1' => ___('Show this block'),
                '-1' => ___('Hide this block')
            ]);
        $l_if = ___('if');
        $gr->addHtml()
            ->setHtml("<span> $l_if </span>");
        $gr->addSelect('cond_concat')
            ->loadOptions([
                '&&' => ___('All Conditions Match (AND)'),
                '||' => ___('Any Condition Match (OR)')
            ]);
        $gr->addHtml()
            ->setHtml("<div class='cond_rules_container'><div class='cond_rules_container__rules'></div>");
        $gr->addHtml()
            ->setHtml('</div><a href="javascript:;" class="local add-cond" style="line-height:2em; display:inline-block;margin-top:.4em">&plus; Add Condition</a></div>');
    }

    function addConditionalIfNecessary($form, $sel, $hide_row = false)
    {
        if ($this->getConfig('cond_enabled') && ($_ = json_decode($this->getConfig('cond_rules')))) {
            $c_cond = $this->getConfig('cond_type') > 0 ? '' : '!';
            $c_type = $this->getConfig('cond_concat') ?: '&&';
            $c_initial = $c_type == '&&' ? 'true' : 'false';

            $c_fields = [];
            foreach ($_ as & $cond) {
                $c_fields[] = $cond[0];
                if ($cond[0] == 'coupon') {
                    $cond[1] = md5($cond[1]);
                }
                if (empty($cond[2])) {
                    $cond[2] = 'equal';
                }
            }
            $c_fields = array_unique($c_fields);
            $selector = [];
            foreach ($c_fields as $fn) {
                if ($fn == 'product_id') {
                    $selector[] = "[name^={$fn}]";
                } else {
                    $selector[] = "[name={$fn}], [name=\"{$fn}[]\"]";
                }
            }
            $selector = implode(",\\\n", $selector);
            $conds = json_encode($_);

            $toggle_code = $hide_row ?
                "jQuery('{$sel}').closest('.am-row').toggle($c_cond v);" :
                "jQuery('{$sel}').toggle($c_cond v);";

            $form->addScript()
                ->setScript(<<<CUT
jQuery(function(){
    const conds = {$conds};
    const isEmpty = value => (!value && value !== 0)
    const isIntersect = (a, b) => a.filter(x => b.includes(x)).length > 0
    
    function checkConds()
    {
        let res = {$c_initial};
        for (let i in conds) {
            res = res {$c_type} checkCond(...conds[i]);
        }
        return res;
    }
    function checkCond(field, value, op)
    {
        let el;
        let val;
        if (field == 'product_id') {
            val = [];
            jQuery('select[name^=product_id] option:checked,' +
                '[type=radio][name^=product_id]:checked,' +
                '[type=checkbox][name^=product_id]:checked,' +
                '[type=hidden][name^=product_id]').each(function(){
                    val.push(jQuery(this).val());
                });
            switch(op) {
                case 'equal':
                    return Array.isArray(value) ? isIntersect(val, value) : val.includes(value);
                    break;
                case 'not-equal':
                    return Array.isArray(value) ? !isIntersect(val, value) : !val.includes(value);
                    break;
                case 'is-empty':
                    return val.length == 0;
                    break;
                case 'is-not-empty':
                    return val.length != 0;
                    break;
            }    
        } else {
            el = jQuery('select[name=' + field + '],' +
                '[type=radio][name=' + field + '],' +
                '[type=hidden][name=' + field + '],' +
                '[type=checkbox][name="' + field + '[]"],' +
                '[type=checkbox][name="' + field + '"],' +
                '[type=text][name="' + field + '"]').get(0);
            switch (el.type) {
                case 'radio':
                    val = jQuery("[name='" + el.name + "']:checked").val();
                    break;
                case 'hidden':
                case 'select':
                case 'select-one':
                case 'text':
                    val = jQuery("[name='" + el.name + "']").val();
                    break;
                case 'checkbox':
                    let elm = jQuery("[name='" + el.name + "']:checked");
                    val = elm.length > 1 ?
                        elm.filter("[value='" + value + "']").val() :
                        elm.val();
                    break;
            }
            if (field == 'coupon') {
                val = md5(val);
            }

            switch(op) {
                case 'equal':
                    return Array.isArray(value) ? value.includes(val) : value == val;
                    break;
                case 'not-equal':
                    return Array.isArray(value) ? !value.includes(val) : value != val;
                    break;
                case 'is-empty':
                    return isEmpty(val);
                    break;
                case 'is-not-empty':
                    return !isEmpty(val);
                    break;
            }
        }
    }
    const update = function(){
        let v = checkConds();
        {$toggle_code}
    }
    update();
    jQuery('$selector').change(update);
});
CUT
                );
        }
    }

    function _setConfigArray(& $config)
    {
        // Deal with old style Conditional
        if (!empty($config['cond_enabled']) && !isset($config['cond_rules'])) {
            $config['cond_rules'] = json_encode([[$config['cond_field'], $config['cond_field_val']]]);
            unset($config['cond_field']);
            unset($config['cond_field_val']);
        }
    }
}