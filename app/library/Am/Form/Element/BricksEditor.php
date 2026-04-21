<?php

/**
 * An admin UI element to handle visual bricks configuration
 *
 * @package Am_SavedForm
 */
class Am_Form_Element_BricksEditor extends HTML_QuickForm2_Element
{
    const ALL = 'all';
    const ENABLED = 'enabled';
    const DISABLED = 'disabled';

    protected $bricks = [];
    protected $value = [];
    /** @var Am_Form_Bricked */
    protected $brickedForm = null;

    public function __construct($name, $attributes, Am_Form_Bricked $form)
    {
        $attributes['class'] = 'am-no-label';
        parent::__construct($name, $attributes, []);
        $this->brickedForm = $form;
        class_exists('Am_Form_Brick', true);
        foreach ($this->brickedForm->getAvailableBricks() as $brick)
            $this->bricks[$brick->getClass()][$brick->getId()] = $brick;
    }

    public function getType()
    {
        return 'hidden'; // we will output the row HTML too
    }

    public function getRawValue()
    {
        $value = [];
        foreach ($this->value as $row) {
            if ($brick = $this->getBrick($row['class'], $row['id'])) {
                $value[] = $brick->getRecord();
            }
        }
        return json_encode($value);
    }

    public function setValue($value)
    {
        if (is_string($value))
            $value = json_decode($value, true);
        $this->value = (array)$value;
        foreach ($this->value as &$row) {
            if (empty($row['id']))
                continue;
            if (isset($row['config']) && is_string($row['config'])) {
                parse_str($row['config'], $c);
                $row['config'] = $c;
            }
            if ($brick = $this->getBrick($row['class'], $row['id'])) {
                $brick->setFromRecord($row);
            }
        }
        // handle special case - where there is a "multiple" brick and that is enabled
        // we have to insert additional brick to "disabled", so new bricks of same
        // type can be added in editor
        $disabled = $this->getBricks(self::DISABLED);
        foreach ($this->getBricks(self::ENABLED) as $brick) {
            if (!$brick->isMultiple()) continue;
            $found = false;
            foreach ($disabled as $dBrick) {
                if ($dBrick->getClass() == $brick->getClass()) {
                    $found = true;
                    break;
                };
            }
            // create new disabled brick of same class
            if (!$found) {
                $this->getBrick($brick->getClass(), null);
            }
        }
    }

    /**
     * Clones element if necessary (if id passed say as "id-1" and it is not found)
     * @return Am_Form_Brick|null
     */
    public function getBrick($class, $id)
    {
        if (
            !isset($this->bricks[$class][$id])
            && isset($this->bricks[$class])
            && current($this->bricks[$class])->isMultiple()
        ) {
            if ($id === null) {
                for ($i = 0; $i < 100; $i++) {
                    if (!array_key_exists($class . '-' . $i, $this->bricks[$class])) {
                        $id = $class . '-' . $i;
                        break;
                    }
                }
            }
            $this->bricks[$class][$id] = Am_Form_Brick::createFromRecord(['class' => $class, 'id' => $id]);
        }
        return empty($this->bricks[$class][$id]) ? null : $this->bricks[$class][$id];
    }

    public function getBricks($where = self::ALL)
    {
        $enabled = [];
        foreach ($this->value as $row) {
            if (!empty($row['id'])) {
                $enabled[] = $row['id'];
            }
        }

        $ret = [];
        foreach ($this->bricks as $bricks) {
            foreach ($bricks as $id => $b) {
                if ($where == self::ENABLED && !in_array($id, $enabled)) {
                    continue;
                }
                if ($where == self::DISABLED && in_array($id, $enabled)) {
                    continue;
                }
                $ret[$id] = $b;
            }
        }
        // if we need enabled element, we need to maintain order according to value
        if ($where == self::ENABLED) {
            $ret0 = $ret;
            $ret = [];
            foreach ($enabled as $id) {
                if (isset($ret0[$id])) {
                    $ret[$id] = $ret0[$id];
                }
            }
        }
        return $ret;
    }

    public function __toString()
    {
        $enabled = $disabled = "";
        $enabled_child = [];
        $enabled_root = [];
        foreach ($this->getBricks(self::ENABLED) as $brick) {
            if ($_ = $brick->getContainer()) {
                if (!isset($enabled_child[$_])) {
                    $enabled_child[$_] = [];
                }
                $enabled_child[$_][] = $brick;
            } else {
                $enabled_root[] = $brick;
            }
        }
        foreach ($enabled_root as $brick) {
            $enabled .= $this->renderBrick($brick, true, isset($enabled_child[$brick->getId()]) ? $enabled_child[$brick->getId()] : []) . "\n";
        }

        foreach ($this->getBricks(self::DISABLED) as $brick) {
            $disabled .= $this->renderBrick($brick, false) . "\n";
        }

        $hidden = is_string($this->value) ? $this->value : json_encode($this->value);
        $hidden = Am_Html::escape($hidden);

        $name = $this->getName();
        $formBricks = ___("Form Bricks (drag to right to remove)");
        $availableBricks = ___("Available Bricks (drag to left to add)");
        $comments = nl2br(
            ___("To add fields into the form, move item from 'Available Bricks' to 'Form Bricks'.\n".
            "To remove fields, move it back to 'Available Bricks'.\n".
            "To make form multi-page, insert 'Form Page Break' item into the place where you want page to be split.")
           );

        $filter = $this->renderFilter();
        return $this->getCss() . $this->getConditionalCss() . $this->getJs() . $this->getConditionalJs() . <<<CUT
<div class="brick-editor">
    <input type="hidden" name="$name" value="$hidden">
    <div class="brick-section">
        <div class='brick-header'><h3>$formBricks</h3> $filter</div>
        <div id='bricks-enabled' class='connectedSortable'>
        $enabled
        </div>
    </div>
    <div class="brick-section brick-section-available">
        <div class='brick-header'><h3>$availableBricks</h3> $filter</div>
        <div id='bricks-disabled' class='connectedSortable'>
        $disabled
        </div>
    </div>
<div style='clear: both'></div>
</div>
<div class='brick-comment'>$comments</div>
CUT;
    }

    public function renderConfigForms()
    {
        $out = "<!-- brick config forms -->";
        foreach ($this->getBricks(self::ALL) as $brick) {
            if (!$brick->haveConfigForm())
                continue;
            $form = new Am_Form_Admin(null,null,true);
            $brick->initConfigForm($form);
            $form->setDataSources([new Am_Mvc_Request($brick->getConfigArray())]);
            $out .= "<div id='brick-config-{$brick->getId()}' class='brick-config' style='display:none'>\n";
            $out .= (string) $form;
            $out .= "</div>\n\n";
        }

        $form = new Am_Form_Admin;
        $form->addTextarea('_tpl', ['rows' => 2, 'class' => 'am-el-wide'])->setLabel('-label-');
        $out .= "<div id='brick-labels' style='display:none'>\n";
        $out .= (string)$form;
        $out .= "</div>\n";
        $out .= "<!-- end of brick config forms -->";

        $form = new Am_Form_Admin;
        $form->addText('_tpl', ['class' => 'am-el-wide am-no-label']);
        $out .= "<div id='brick-alias' style='display:none'>\n";
        $out .= (string)$form;
        $out .= "</div>\n";
        $out .= "<!-- end of alias forms -->";
        return $out;
    }

    public function renderBrick(Am_Form_Brick $brick, $enabled, $childs = [])
    {
        $configure = $labels = null;
        $attr = [
            'id' => $brick->getId(),
            'class' => "brick {$brick->getClass()}",
            'data-class' => $brick->getClass(),
            'data-title' => strtolower($brick->getName()),
            'data-alias' => $brick->getAlias(),
        ];
        if ($brick->haveConfigForm()) {
            $attr['data-config'] = json_encode($brick->getConfigArray());
            $configure = "<a class='configure local' href='javascript:;' title='" .
                Am_Html::escape($brick->getName() . ' ' . ___('Configuration')) . "'>" . ___('configure') . "</a>";
        }
        if ($brick->getStdLabels()) {
            $attr['data-labels'] = json_encode($brick->getCustomLabels());
            $attr['data-stdlabels'] = json_encode($brick->getStdLabels());
            $class = $brick->getCustomLabels() ? 'labels custom-labels' : 'labels';
            $labels = "<a class='$class local' href='javascript:;' title='" . Am_Html::escape(___('Edit Brick Labels')) . "'>" . ___('labels') . "</a>";
        }

        if ($brick->isMultiple())
            $attr['data-multiple'] = "1";

        if ($brick->hideIfLoggedInPossible() == Am_Form_Brick::HIDE_DESIRED)
            $attr['data-hide'] = $brick->hideIfLoggedIn() ? 1 : 0;

        $attrString = "";
        foreach ($attr as $k => $v) {
            $attrString .= " $k=\"" . htmlentities($v ?? '', ENT_QUOTES, 'UTF-8', true) . "\"";
        }

        $checkbox = $this->renderHideIfLoggedInCheckbox($brick);

        $c = '';
        foreach($childs as $c_brick) {
            $c .= $this->renderBrick($c_brick, $enabled);
        }

        $class = $brick->getAlias() ? 'brick-has-alias' : '';
        $container = strpos($brick->getClass(), 'fieldset') === 0 ? "<div class=\"connectedSortable fieldset-fields\">{$c}</div>" : '';
        return "<div $attrString>
        <a class=\"brick-head $class\" href='javascript:;'>
            <span class='brick-title' title='" . Am_Html::escape($brick->getName()) . "'><span class='brick-title-title'>{$brick->getName()}</span></span>
            <span class='brick-alias' title='" . Am_Html::escape("{$brick->getAlias()} ({$brick->getName()})") . "'><span class='brick-alias-alias'>{$brick->getAlias()}</span> <small>{$brick->getName()}</small></span>
        </a>
        $configure
        $labels
        $checkbox
        $container
        </div>";
    }

    public function renderFilter(): string
    {
        $l_filter = Am_Html::escape(___('filter'));
        $l_placeholder = Am_Html::escape(___('type part of brick name to filter…'));
        $l_mode_title = Am_Html::escape(___('Toggle Filter Mode (Contain / Not Contain)'));
        $l_empty_title = Am_Html::escape(___('Clear Filter'));
        return <<<CUT
<span><a href="javascript:;" class="input-brick-filter-link local closed">$l_filter</a></span>
<div class="input-brick-filter-wrapper">
    <div class="input-brick-filter-inner-wrapper">
        <input class="input-brick-filter"
               type="text"
               name="q"
               autocomplete="off"
               placeholder="$l_placeholder" />
        <div class="input-brick-filter-mode input-brick-filter-mode__equal" title="$l_mode_title">&nbsp;</div>       
        <div class="input-brick-filter-empty" title="$l_empty_title">&nbsp;</div>
    </div>
</div>
CUT;
    }

    protected function renderHideIfLoggedInCheckbox(Am_Form_Brick $brick)
    {
        if (($this->brickedForm->isHideBricks())) {
            if ($brick->hideIfLoggedInPossible() != Am_Form_Brick::HIDE_DONT) {
                static $checkbox_id = 0;
                $checkbox_id++;
                if ($brick->hideIfLoggedInPossible() == Am_Form_Brick::HIDE_ALWAYS) {
                    $checked = "checked='checked'";
                    $disabled = "disabled='disabled'";
                } else {
                    $disabled = "";
                    $checked = $brick->hideIfLoggedIn() ? "checked='checked'" : '';
                }
                return "<span class='hide-if-logged-in'><input type='checkbox'"
                    . " id='chkbox-$checkbox_id' value=1 $checked $disabled />"
                    . " <label for='chkbox-$checkbox_id'>" . ___('hide if logged-in') . "</label></span>\n";
            }
        }
    }

    public function getJs()
    {
        return <<<'CUT'
<script>
jQuery(function($){
    $('.input-brick-filter-link').click(function(){
        $('.input-brick-filter-wrapper', $(this).closest('.brick-section')).toggle();
        if ($(this).hasClass('closed'))
            $('.input-brick-filter-wrapper input', $(this).closest('.brick-section')).focus();
        $(this).toggleClass('opened closed')
        $('.input-brick-filter', $(this).closest('.brick-section')).val('').change();
    });
    $(document).on('keyup change','.input-brick-filter', function(){
         let $context = jQuery(this).closest('.brick-section');
         let isContain = $(this).closest('.input-brick-filter-wrapper').find('.input-brick-filter-mode').hasClass('input-brick-filter-mode__equal');
         $('.input-brick-filter-empty', $context).toggle($(this).val().length != 0);

         if ($(this).val()) {
             $('.brick', $context).toggle(!isContain);
             $('.brick[data-title*="' + $(this).val().toLowerCase() + '"], .brick[id*="' + $(this).val().toLowerCase() + '"]', $context).toggle(isContain);
         } else {
             $('.brick', $context).show();
         }
    })

    $('.input-brick-filter-empty').click(function(){
        $(this).closest('.input-brick-filter-wrapper').find('.input-brick-filter').val('').change();
        $(this).hide();
    })
    $('.input-brick-filter-mode').click(function(){
        $(this).toggleClass('input-brick-filter-mode__equal input-brick-filter-mode__nequal')
        $(this).closest('.input-brick-filter-wrapper').find('.input-brick-filter').change();
    })
});
</script>
CUT;
    }

    public function getConditionalCss(): string
    {
        return <<<CUT
<style>
    .mod-editing .cond-rule .cond-i,
    .mod-add .cond-rule .cond-i {
        display: none;
    }

    .cond-rule-form,
    .cond-rule {
        margin-top:.4em;
        display: flex;
        gap: .2em;
    }
    .cond-rule {
        padding-left: .5em;
        border-radius: 3px 3px 0 0;
    }

    .cond-rule > div {
        line-height: 2em;
    }
    
    .cond-rule .cond-x,
    .cond-rule .cond-e {
        opacity: .5;
    }
    
    .cond-rule .cond-x:hover,
    .cond-rule .cond-e:hover {
        opacity: 1;
    }
    
    .cond-rule.cond-editing {
        background: #eee;
    }
    
    .cond_rules_container__rules .cond-rule-form {
        border: 1px solid #eee;
        margin-top: 0;
        padding: 1em .5em;
        border-radius: 0 0 3px 3px;
    }

    .edit-cond,
    .commit-cond,
    .cancel-form,
    .del-cond {
        display: inline-block;
        text-align:center;
        width: 1.2em;
        text-decoration:none;
        line-height: 2em;
    }
    
    .edit-cond {
        color:#488f37!important;
    }
    
    .commit-cond {
        color:#488f37!important;;
    }
    
    .del-cond {
        color:#ba2727!important;;
    }
    
    .cancel-form {
        color:#ba2727!important;;
    }
    
</style>    
CUT;

    }

    public function getConditionalJs(): ?string
    {
        [$fields, $allOp] = $this->getEnumFieldOptions();
        $fields['coupon'] = ___('Coupon');

        if ($fields) {
            $fields = json_encode(json_encode($fields));
            $allOp = json_encode(json_encode($allOp));

            return <<<CUT
<script>
jQuery(function(){
    let g_fOpt = JSON.parse($fields)
    let g_opt = JSON.parse($allOp)
    
    const ICON_EDIT = '<div class="cond-i cond-e"><a title="Edit Condition" href="javascript:;" class="edit-cond"><i class="fa-regular fa-pen-to-square"></i></a></div>'
    const ICON_COMMIT = '<div class="cond-i cond-c"><a title="Save Condition" href="javascript:;" class="commit-cond"><i class="fa-solid fa-check"></i></a></div>'
    const ICON_FORM_CANCEL = '<div class="cond-i cond-x"><a title="Cancel" href="javascript:;" class="cancel-form"><i class="fa-solid fa-xmark"></i></a></div>'
    const ICON_DELETE = '<div class="cond-i cond-x"><a title="Delete Condition" href="javascript:;" class="del-cond"><i class="fa-regular fa-circle-xmark"></i></a></div>'
    const ICON_EDIT_CANCEL = '<div class="cond-i cond-x"><a title="Cancel Edit" href="javascript:;" class="cancel-form"><i class="fa-solid fa-xmark"></i></a></div>'

    const truncateString = (str) => str.length > 15 ? str.substr(0, 12).trim() + '...' : str
       
    function render(ctx)
    {
        let rules = getAll(ctx)
        jQuery('.cond_rules_container__rules', ctx).empty()
        
        for (let i in rules) {
            renderRule(jQuery('.cond_rules_container__rules', ctx), i, rules[i])
        }
    }

    function remove(ctx, idx)
    {
        let rules = getAll(ctx)
        rules.splice(idx,1)
        sync(ctx, rules)
        render(ctx, rules)
    }

    function add(ctx, cond)
    {
        let rules = getAll(ctx)
        rules.push(cond)
        sync(ctx, rules)
        render(ctx, rules)
    }

    function update(ctx, idx, cond)
    {
        let rules = getAll(ctx)
        rules[idx] = cond
        sync(ctx, rules)
        render(ctx, rules)
    }

    function sync(ctx, rules)
    {
        jQuery("[name=cond_rules]", ctx).val(JSON.stringify(rules))
    }

    function getAll(ctx)
    {
        let v = jQuery("[name=cond_rules]", ctx).val()
        return JSON.parse(v ? v : '[]')
    }

    function getOne(ctx, idx)
    {
        return getAll(ctx)[idx]
    }
       
    function renderRule(c, idx, cond)
    {
        let [field = false, val = false, op = 'equal'] = cond 
        
        let ctx = jQuery(c).closest('.conditional-group')
        
        let fOpt = ctx.data('f-opt') ?? g_fOpt
        let opt = ctx.data('opt') ?? g_opt

        let crow = jQuery('<div>', {class: "cond-rule"})

        crow.data('cond', JSON.stringify([field, val, op]))
        crow.data('idx', idx)
        
        let op_map = {
            equal: 'is equal',
            'not-equal': 'is not equal',
            'is-empty': 'is empty',
            'is-not-empty': 'is not empty',  
        }
        
        if (Array.isArray(val)) {
            op_map.equal = 'is one of'
            op_map['not-equal'] = 'is not any of'
            val = val.map(v => opt[field].reduce((acc, curr) => curr[0] == v ? truncateString(curr[1]) : acc, v)).join(', ')
        }
        if (['is-empty', 'is-not-empty'].includes(op)) {
            val = ''
        }

        jQuery(c).append(crow)
        jQuery(crow).
            append(jQuery('<div>', {style: 'width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;', title: fOpt[field]}).text(fOpt[field])).
            append(jQuery('<div>').append(jQuery('<strong>').text(op_map[op]))).
            append(jQuery('<div>', {style: 'margin-right:.5em'}).text(val)).
            append(ICON_EDIT).
            append(ICON_DELETE)
    }
    
    function updateRuleForm(c, cond, idx)
    {
        let [field = false, val = false, op = 'equal'] = cond
        
        let ctx = jQuery(c).closest('.conditional-group')
        
        let fOpt = ctx.data('f-opt') ?? g_fOpt
        let opt = ctx.data('opt') ?? g_opt

        let sel_f = jQuery('<select>', {name: "_field[]", style: "max-width:160px"})
        let sel_v = jQuery('<select>', {name: "_field_val_select[]", multiple: true, style: "max-width:180px"})
        let text_v = jQuery('<input>', {type: "text", name: "_field_val_text[]"})
        let hidden_val_source = jQuery('<input>', {type: "hidden", name: "_field_val_source[]"})
        let sel_op = jQuery(
            '<select name="_field_op[]" style="max-width:110px">' +
                '<option value="equal">is Equal</option>' +
                '<option value="not-equal">is Not Equal</option>' +
                '<option value="is-empty">is Empty</option>' +
                '<option value="is-not-empty">is Not Empty</option>' +
            '</select>');
        jQuery(c).
            empty().
            append(jQuery('<div>', {class: "cond-sel-f"}).append(sel_f)).
            append(jQuery('<div>', {class: "cond-sel-op"}).append(sel_op)).
            append(jQuery('<div>', {class: "cond-sel-v cond-v"}).append(jQuery('<div>', {class: "cond-vv"}).append(sel_v))).
            append(jQuery('<div>', {class: "cond-text-v cond-v"}).append(jQuery('<div>', {class: "cond-vv"}).append(text_v))).
            append(hidden_val_source).
            append(ICON_COMMIT)

        jQuery(c).
            append(idx === -1 ? ICON_FORM_CANCEL : ICON_EDIT_CANCEL)

        for (let k in fOpt) {
            sel_f.append(jQuery('<option>', {value: k, text: fOpt[k]}))
            field && sel_f.val(field)
        }
        if (opt.hasOwnProperty(sel_f.val())) {
            const cOpt = opt[sel_f.val()]
            for (let k in cOpt) {
                sel_v.append(jQuery('<option>', {value: cOpt[k][0], text: cOpt[k][1]}))
            }
            val && sel_v.val(Array.isArray(val) ? val : [val])

            hidden_val_source.val('select')
            sel_v.closest('.cond-vv').show()
            text_v.closest('.cond-vv').hide()
            
            sel_op.find('[value=equal]').text('is one of')
            sel_op.find('[value=not-equal]').text('is not any of')
            
        } else {
            hidden_val_source.val('text')
            text_v.closest('.cond-vv').show()
            sel_v.closest('.cond-vv').hide()
            val && text_v.val(val)
            
            sel_op.find('[value=equal]').text('is equal')
            sel_op.find('[value=not-equal]').text('is not equal')
        }
        sel_op.val(op)

        jQuery(sel_v).magicSelect()
        jQuery('.cond-v', c).toggle(!(['is-empty', 'is-not-empty'].includes(op)))
        let p;
        jQuery(sel_v).select2(p = {
            minimumResultsForSearch: 10,
            width: "180px",
        }).data('select2-option', p)
    }
    
    function openRuleForm(c, cond, idx = -1)
    {
        let crow = jQuery('<div>', {class: "cond-rule-form"})
        jQuery(c).after(crow)
        crow.data('idx', idx)
        updateRuleForm(crow, cond, idx)
    }
    
    function getDraftRule(c)
    {
        let value

        switch (jQuery('[name="_field_val_source[]"]', c).val()) {
            case 'text':
                value = jQuery('[name="_field_val_text[]"]', c).val()
                break
            case 'select':
                value = jQuery('[name="_field_val_select[]"]', c).map((idx, del) => del.value).get()
                break
        }

        return [
            jQuery('[name="_field[]"]', c).val(),
            value,
            jQuery('[name="_field_op[]"]', c).val(),
        ]
    }

    //Add
    jQuery(document).on('click', '.conditional-group .add-cond', function(){
        let ctx = jQuery(this).closest('.conditional-group')
        openRuleForm(jQuery(".cond_rules_container__rules", ctx), [])
        jQuery(ctx).addClass('mod-add')
        jQuery(this).hide()
    });

    //Edit
    jQuery(document).on('click', '.conditional-group .edit-cond', function(){
        let ctx = jQuery(this).closest('.conditional-group')
        let idx = jQuery(this).closest('.cond-rule').data('idx')
        openRuleForm(jQuery(this).closest('.cond-rule'), getOne(ctx, idx), idx)
        jQuery(this).closest('.cond-rule').addClass('cond-editing')
        jQuery(ctx).addClass('mod-editing')
        jQuery('.add-cond', ctx).hide()
    });
    
    //Delete
    jQuery(document).on('click', '.conditional-group .del-cond', function(){
        let ctx = jQuery(this).closest('.conditional-group')
        let idx = jQuery(this).closest('.cond-rule').data('idx')
        if (idx === -1) {
            jQuery(this).closest('.cond-rule-form').remove()
            jQuery('.cond-editing', ctx).removeClass('cond-editing')
            jQuery(ctx).removeClass('mod-editing')
            jQuery(ctx).removeClass('mod-add')
            jQuery('.add-cond', ctx).show()
        } else {
            remove(ctx, idx)
        }
    });
    
    //Cancel Form
    jQuery(document).on('click', '.conditional-group .cancel-form', function(){
        let ctx = jQuery(this).closest('.conditional-group')
        jQuery(this).closest('.cond-rule-form').remove()
        jQuery('.cond-editing', ctx).removeClass('cond-editing')
        jQuery(ctx).removeClass('mod-editing')
        jQuery(ctx).removeClass('mod-add')
        jQuery('.add-cond', ctx).show()
    });

    //Commit
    jQuery(document).on('click', '.conditional-group .commit-cond', function(){
        let ctx = jQuery(this).closest('.conditional-group')
        let idx = jQuery(this).closest('.cond-rule-form').data('idx')
        let cond = getDraftRule(jQuery(this).closest('.cond-rule-form'))
        if (idx === -1) {
            add(ctx, cond)    
        } else {
            update(ctx, idx, cond)
        }
        jQuery(this).closest('.cond-rule-form').remove()
        jQuery('.cond-editing', ctx).removeClass('cond-editing')
        jQuery(ctx).removeClass('mod-editing')
        jQuery(ctx).removeClass('mod-add')
        jQuery('.add-cond', ctx).show()
    });

    //Form Update
    jQuery(document).on('change', '.conditional-group .cond_rules_container select, .conditional-group .cond_rules_container input', function(){
        const c = jQuery(this).closest('.cond-rule-form')
        updateRuleForm(c, getDraftRule(c))
    });

    //Inititalization
    jQuery("[name=cond_rules]").each(function(){
        if (jQuery(this).val()) {
            let ctx = jQuery(this).closest('.conditional-group')
            render(ctx)
        }
    });

    jQuery(document).on('change', "[name=cond_enabled]", function(){
        jQuery(this).closest('label').nextAll().toggle(this.checked)
    });

    jQuery("[name=cond_enabled]").change()
});
</script>
CUT;
        } else {
            return null;
        }
    }

    public function getCss()
    {
        $declined = Am_Di::getInstance()->view->_scriptImg('icons/decline-d.png');
        $decline = Am_Di::getInstance()->view->_scriptImg('icons/decline.png');
        $equal = Am_Di::getInstance()->view->_scriptImg('icons/equal.png');
        $nequal = Am_Di::getInstance()->view->_scriptImg('icons/nequal.png');
        $magnify = Am_Di::getInstance()->view->_scriptImg('icons/magnify.png');
        return <<<CUT
<style>
.brick {
    border: solid 1px #e7e7e7;
    margin: 4px;
    padding: 0.4em;
    background: #f1f1f1;
    cursor: move;
    -webkit-border-radius: 2px;
    -moz-border-radius: 2px;
    border-radius: 2px;
    box-sizing: content-box;
    overflow: hidden;
}

.brick::before {
    content: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAQCAYAAAAvf+5AAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH5AwJDgYokIhzyQAAACRJREFUKM9jYCAFHDhw4P+BAwf+4+IzMDAwMDEMLBh141B2IwCPCTP5nTc2QQAAAABJRU5ErkJggg==);
    float: left;
    margin-right: .5em;
}

.brick:hover {
    border-color: #777;
}

a.brick-head {
    font-weight: normal;
    color: black;
    text-decoration: none;
}
a.brick-head small {
    opacity: .5;
    text-transform: lowercase;
}

.page-separator {
    background: #ffffcf;
    border-color: #ffff9c;
}

.invoice-summary {
    background: #afccaf;
    border-color: #90b890;
}

.product {
    background: #d3dce3;
    border-color: #b4c3cf;
}

.paysystem {
    background: #ffd963;
    border-color: #ffcd30;
}

.brick.fieldset {
    background:#c5cae9;
    border-color: #a0a8db;
}

.brick.credit-card-token {
    background: #feb0a6;
    border-color: #fd8374;
}

.brick.h-t-m-l {
    background: #addbec;
    border-color: #84c9e2;
}

.manual-access,
.user-group {
    opacity: .5;
}

.brick-section {
    width: 40%;
    padding: 10px;
    float: left;
    position: sticky;
    top: 0;
}

.brick-section.brick-section-available {
    width: 55%;
}

.brick-comment {
    padding: 10px;
}

.hide-if-logged-in {
    margin-left: 20px;
    float: right;
    font-size: .8rem;
}

#bricks-enabled .page-separator {
    margin-bottom: 20px;
}

#bricks-enabled {
    min-height: 200px;
    padding-bottom:4em;
    border: 2px dashed #ddd;
}

#bricks-enabled .brick-head {
    max-width: 50%;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    float: left;
}

.brick-alias {
    display: none;
}

#bricks-enabled .brick-has-alias .brick-title {
    display: none;
}

#bricks-enabled .brick-has-alias .brick-alias {
    display: inline;
}

#bricks-enabled .brick::after {
    content: ' ';
    display: inline-block;
    clear: both;
}

#bricks-enabled .brick-alias-alias,
#bricks-enabled .brick-title-title {
    text-decoration: underline;
    text-decoration-style: dashed;
    text-decoration-color: #00000055;
}

#bricks-disabled {
    overflow: hidden;
    min-height: 50px;
}

#bricks-disabled a.configure,
#bricks-disabled a.labels,
#bricks-disabled .hide-if-logged-in,
#bricks-disabled .fieldset-fields {
    display: none;
}

#bricks-disabled .brick-head {
    cursor: move;
}

#bricks-disabled .brick {
    float: left;
    margin: 2px;
    width: 45%;
    overflow: hidden;
    white-space: nowrap
}

.fieldset-fields {
    padding-bottom: 1.5em;
    margin-top: 1em;
    border:1px dashed #82acb2;
}

a.configure,
a.labels {
    margin-left: 0.2em;
    cursor: pointer;
    color: #34536E;
}

a.labels.custom-labels {
    color: #360;
}

/* Filter */

.brick-header {
    margin-bottom:0.8em;
}
.brick-header h3 {
    display: inline;
}

.input-brick-filter-wrapper {
    overflow: hidden;
    padding: 0.4em;
    border: 1px solid #c2c2c2;
    margin-bottom: 1em;
    display: none;
}

.input-brick-filter-inner-wrapper {
    display: flex;
}
.input-brick-filter-mode {
    width: 20px;
    cursor: pointer;
    opacity: .2;
}

.input-brick-filter-mode:hover {
    opacity: 1;
}

.input-brick-filter-mode__equal {
    background: url("{$equal}") no-repeat center center transparent;
}

.input-brick-filter-mode__nequal {
    background: url("{$nequal}") no-repeat center center transparent;
}

.input-brick-filter-empty {
    width: 20px;
    cursor: pointer;
    background: url("{$declined}") no-repeat center center transparent;
}

.input-brick-filter-empty:hover {
   opacity: 1;
   background-image: url("{$decline}");
}

input[type=text].input-brick-filter {
    padding:0;
    margin:0;
    border: none;
    width:100%;
    padding-left: 24px;
    background: url("{$magnify}") no-repeat left center;
}
input[type=text].input-brick-filter:focus {
    border: none;
    box-shadow: none;
}
input[type=text].input-brick-filter:focus {
    border: none;
    outline: 0;
    background-color: unset;
}
#bricks-enabled .brick-editor-placeholder {
    border: 1px dashed #d3dce3;
    margin: 4px;
    height: 25px;
}
#bricks-disabled .brick-editor-placeholder {
    border: 1px dashed #d3dce3;
    margin: 2px;
    height: 25px;
    width: 45%;
    float: left;
}
</style>
CUT;
    }

    function getEnumFieldOptions()
    {
        $fields = [
            'country' => 'Country',
            'paysys_id' => 'Payment System',
            'product_id' => 'Product'
        ];
        $options = [
            'country' => $this->_tr(Am_Di::getInstance()->countryTable->getOptions()),
            'paysys_id' => $this->_tr(Am_Di::getInstance()->paysystemList->getOptionsPublic()),
            'product_id' => $this->_tr(Am_Di::getInstance()->billingPlanTable->getProductPlanOptions()),
        ];
        foreach (Am_Di::getInstance()->userTable->customFields()->getAll() as $fd) {
            if (in_array($fd->type, ['radio', 'select', 'checkbox', 'single_checkbox'])) {
               $fields[$fd->name] = $fd->title;
               $options[$fd->name] = $fd->type == 'single_checkbox' || ($fd->type == 'checkbox' && empty($fd->options)) ?
                   [[1, ___('Checked')], [0, ___('Unchecked')]] :
                   $this->_tr($fd->options);
            }
        }
        return [$fields, $options];
    }

    function _tr($a)
    {
        $out = [];
        foreach ($a as $k => $v) {
            $out[] = [$k, $v];
        }
        return $out;
    }
}
