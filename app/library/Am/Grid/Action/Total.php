<?php

class Am_Grid_Action_Total extends Am_Grid_Action_Abstract
{
    protected $privilege = 'browse';
    protected $type = self::HIDDEN;
    protected $fields = [];
    protected $stms = [];
    protected $render = [];
    /** @var Am_Query */
    protected $ds;

    public function run()
    {
        $this->grid->getDi()->session->writeClose();
        $this->grid->getDi()->response->ajaxResponse([
            'total' => $this->renderTotals()
        ]);
    }

    /**
     * @param Am_Grid_Field $field
     * @return Am_Grid_Action_Total
     */
    public function addField(Am_Grid_Field $field, $stm = '%s', $renderCallback = null)
    {
        $this->fields[$field->getFieldName()] = $field;
        $this->stms[$field->getFieldName()] = $stm;
        $this->render[$field->getFieldName()] = $renderCallback ?? ['Am_Currency', 'render'];
        return $this;
    }

    public function setGrid(Am_Grid_Editable $grid)
    {
        $grid->addCallback(Am_Grid_ReadOnly::CB_BEFORE_RUN, function($grid) {
            if ($grid->actionGet($this->getId())) {
                $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_TABLE, [$this, 'renderOut']);
                $this->ds = clone $grid->getDataSource();
            }
        });
        parent::setGrid($grid);
    }

    public function renderOut(& $out)
    {
        $html = $this->grid->isAsyncTotals() ? $this->renderTotalsPlaceholder() : $this->renderTotals();
        $out = preg_replace('|(<div.*?class="am-grid-container)|', str_replace('$', '\$', $html) . '\1', $out);
    }

    function renderTotals()
    {
        $titles = [];
        $render = [];

        $this->ds->clearFields()
            ->clearOrder()
            ->toggleAutoGroupBy(false);

        foreach ($this->fields as $field) {
            /* @var $field Am_Grid_Field */
            $name = $field->getFieldName();
            $stm = $this->stms[$name];
            $this->ds
                ->addField(sprintf("SUM($stm)", $name), '_' . $name);
            $titles['_' . $name] = $field->getFieldTitle();
            $render['_' . $name] = $this->render[$name];
        }

        $totals = [];
        foreach ($this->grid->getDi()->db->selectRow($this->ds->getSql()) as $key => $val) {
            $totals[] = sprintf('%s %s: <strong>%s</strong>', ___('Total'), $titles[$key], call_user_func($render[$key], $val));
        }
        return sprintf('<div class="am-grid-total">%s</div>', implode(',', $totals));
    }

    protected function renderTotalsPlaceholder()
    {
        return sprintf(
            "<div class='need-reload' data-url='%s' data-key='total'>%s</div>",
            $this->grid->makeUrl([Am_Grid_Editable::ACTION_KEY => $this->getId()]),
            '<div class="am-grid-total">&nbsp;</div>'
        );
    }
}