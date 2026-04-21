<?php

class Am_Grid_Action_LiveDate extends Am_Grid_Action_LiveAbstract
{
    protected static $jsIsAlreadyAdded = [];

    public function __construct($fieldName, $placeholder = null)
    {
        $this->placeholder = $placeholder ?: ___('Click to Edit');
        $this->fieldName = $fieldName;
        $this->decorator = new Am_Grid_Field_Decorator_LiveDate($this);
        parent::__construct('live-date-' . $fieldName, ___("Live Edit %s", ___(ucfirst($fieldName)) ));
    }

    function renderStatic(& $out)
    {
        $out .= <<<'CUT'
<script>
jQuery(document).on('click',"td:has(span.live-date)", function(event)
{
    if (jQuery(this).data('mode') == 'edit') return;

    (function() {
        var txt = jQuery(this);
        txt.toggleClass('live-edit-placeholder', txt.text() == txt.data("placeholder"));
        var edit = txt.closest("td").find("input.live-date");
        if (!edit.length) {
            edit = jQuery(txt.data("template"));
            if (txt.text() != txt.data('placeholder')) {
                edit.val(txt.text());
            }
            txt.data("prev-val", edit.val());
            edit.attr("name", txt.attr("id"));
            txt.after(edit);

            initDatepicker('input.live-date-input', {onClose: function (dateText, inst) {
                var vars = txt.data("data") || {};
                vars[edit.attr("name")] = getDate(edit);

                if (dateText != txt.data('prev-val')) {
                    jQuery.post(txt.data("url"), vars, function(res){
                        if (res.ok && res.ok) {
                            stopEdit(txt, edit, dateText);
                        } else {
                            flashError(res.message ? res.message : 'Internal Error');
                            stopEdit(txt, edit, txt.data('prev-val'));
                        }
                    });
                } else {
                    stopEdit(txt, edit, dateText);
                }
            }});

            edit.focus();
        }
        txt.hide();
        txt.closest('td').data('mode', 'edit');
        txt.closest('td').find('.editable').hide();
        edit.show();

        function getDate(inpt)
        {
            const date = jQuery(inpt).datepicker("getDate")
            return date ?
                `${date.getFullYear()}-${(1 + date.getMonth()).toString().padStart(2, '0')}-${date.getDate().toString().padStart(2, '0')}` :
                '';
        }

        function stopEdit(txt, edit, val)
        {
            var text = val ? val : txt.data("placeholder");
            txt.text(text);
            txt.toggleClass('live-edit-placeholder', text == txt.data("placeholder"))
            edit.remove();
            txt.show();
            txt.closest('td').data('mode', 'display');
            txt.closest('td').find('.editable').show();
        }

    }).apply(jQuery(this).find("span.live-date").get(0));
});
</script>
CUT;
    }
}