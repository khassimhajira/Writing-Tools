<?php

class Am_Grid_Action_Group_ProductPicture extends Am_Grid_Action_Group_Form
{
    public function __construct()
    {
        parent::__construct('product_picture', ___('Set Product Picture'));
        $this->setTarget('_top');
    }

    public function handleRecord($id, $product)
    {
        $data = $this->_vars;

        if (empty($data['img']))
        {
            $product->img = null;
            $product->img_path = null;
            $product->img_cart_path = null;
            $product->img_detail_path = null;
            $product->img_orig_path = null;
            $product->update();
            return;
        }

        $product->img = $data['img'];
        $this->grid->getDi()->modules->loadGet('cart')->resize($product);
    }

    public function createForm(): Am_Form
    {
        $prefix = $this->grid->getId() . '_';
        $form = new Am_Form_Admin;

        $form->addUpload("{$prefix}img", null, ['prefix' => Bootstrap_Cart::UPLOAD_PREFIX])
            ->setLabel(___("Product Picture\n" .
                'for shopping cart pages. Only jpg, png and gif formats allowed'))
            ->setAllowedMimeTypes([
                'image/png', 'image/jpeg', 'image/gif', 'image/webp'
            ]);
        $form->addSaveButton();

        return $form;
    }
}