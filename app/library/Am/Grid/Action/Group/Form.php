<?php

abstract class Am_Grid_Action_Group_Form extends Am_Grid_Action_Group_Abstract
{
    protected Am_Form $form;
    protected $_vars = [];

    abstract public function createForm(): Am_Form;

    public function getForm()
    {
        if (empty($this->form)) {
            $this->form = $this->createForm();
        }

        return $this->form;
    }

    public function renderConfirmationForm($btn = null, $addHtml = null)
    {
        $form = $this->getForm();
        $vars = $this->grid->getCompleteRequest()->toArray();
        $vars[$this->grid->getId() . '_confirm'] = 'yes';
        foreach ($vars as $k => $v) {
            if ($form->getElementsByName($k)) {
                unset($vars[$k]);
            }
        }
        foreach (Am_Html::getArrayOfInputHiddens($vars) as $k => $v) {
            $form->addHidden($k)->setValue($v);
        }

        $url_yes = $this->grid->makeUrl(null);
        $form->setAction($url_yes);
        echo $this->renderTitle();
        echo (string)$form;
    }

    public function run()
    {
        if (!$this->getForm()->validate()) {
            echo $this->renderConfirmationForm();
        } else {
            $prefix = $this->grid->getId().'_';
            foreach ($this->getForm()->getValue() as $k => $v) {
                if (strpos($k, $prefix) === 0) {
                    $this->_vars[substr($k, strlen($prefix))] = $v;
                }
            }
            return parent::run();
        }
    }
}