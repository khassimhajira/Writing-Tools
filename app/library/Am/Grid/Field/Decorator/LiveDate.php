<?php

/**
 * This decorator will be automatically added by live-date action
 */
class Am_Grid_Field_Decorator_LiveDate extends Am_Grid_Field_Decorator_Abstract
{
    /** @var Am_Grid_Action_LiveDate */
    protected $action;

    public function __construct(Am_Grid_Action_LiveDate $action)
    {
        $this->action = $action;
        parent::__construct();
    }

    public function render(&$out, $obj, $grid)
    {
        if (!$this->action->isAvailable($obj)) return;

        $wrap = $this->getWrapper($obj, $grid);
        preg_match('{(<td.*>)(.*)(</td>)}is', $out, $match);
        $out = $match[1] . '<div class="editable"></div>'. $wrap[0]
                . ($match[2] ? $grid->escape(amDate($match[2])) : $grid->escape($this->action->getPlaceholder()))
                . $wrap[1] . $match[3];
    }

    protected function divideUrlAndParams($url)
    {
        $ret = explode('?', $url, 2);
        if (count($ret)<=1) return [$ret[0], null];
        parse_str($ret[1], $params);
        return [$ret[0], $params];
    }

    protected function getWrapper($obj, $grid)
    {
        $id = $this->action->getIdForRecord($obj);
        $val = $this->field->get($obj, $grid);
        list($url, $params) = $this->divideUrlAndParams($this->action->getUrl($obj, $id));
        $start = sprintf('<span class="live-date%s" id="%s" data-url="%s" data-data="%s" data-placeholder="%s" data-template="%s">',
            $val ? '' : ' live-edit-placeholder',
            $grid->getId() . '_' . $this->field->getFieldName() . '-' . $grid->escape($id),
            $url,
            $grid->escape(json_encode($params)),
            $grid->escape($this->action->getPlaceholder()),
            $grid->escape('<input type="text" class="live-date-input" />'),
        );
        $stop = '</span>';
        return [$start, $stop];
    }
}