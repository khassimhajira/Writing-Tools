<?php

/**
 * This decorator adds popup view with detail grid
 * Grid URL must be already exists and working
 */
class Am_Grid_Field_Decorator_DetailGrid extends Am_Grid_Field_Decorator_Link
{
    protected $title;
    static $initDone = false;

    public function __construct($tpl, $title)
    {
        $this->title = $title;
        parent::__construct($tpl);
    }

    public function render(&$out, $obj, $controller)
    {
        $url = $this->parseTpl($obj);
        $start = sprintf(
            '<a class="local grid-detail-link" data-title="%s" href="%s" target="_blank">',
            Am_Html::escape($this->title),
            Am_Html::escape($url)
        );
        $stop = '</a>';
        $out = preg_replace('|(<td.*?>)(.+)(</td>)|', '\1'.$start.'\2'.$stop.'\3', $out);
    }

    public function renderStatic(&$out)
    {
        if (self::$initDone) return;
        self::$initDone = true;

        $out .= <<<'CUT'
<script>
    jQuery(document).on("click", ".grid-detail-link", function(){
        const title = jQuery(this).data("title");
        const href = this.href;
        if (!jQuery(".grid-detail-dialog").length) {
            jQuery("body").append(jQuery('<div />', {class: "grid-detail-dialog"}));
        }
        let div = jQuery(".grid-detail-dialog");
        div.load(href, function(){
                div.dialog({
                    autoOpen: true,
                    width: 800,
                    closeOnEscape: true,
                    title,
                    modal: true,
                    open: function(){
                        div.ngrid()
                    }
                })
            }
        );
        return false;
    });
</script>                
CUT;
    }
}