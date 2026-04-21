<?php

/**
 * Export interface
 * @package Am_Grid
 */
interface Am_Grid_Export_Processor
{
    public function buildForm($form);
    public function run(Am_Grid_Editable $grid, Am_Grid_DataSource_Interface_ReadOnly $dataSource, $fields, $config);
    public function setFilenameTemplate(string $filename);
}

/**
 * Factory for export processors
 * @package Am_Grid
 */
class Am_Grid_Export_Processor_Factory
{
    protected static $elements = [];

    static public function register($id, $class, $title)
    {
        self::$elements[$id] = [
            'class' => $class,
            'title' => $title
        ];
    }

    static public function create($id)
    {
        if (isset(self::$elements[$id]))
            return new self::$elements[$id]['class'];
        throw new Am_Exception_InternalError(sprintf('Can not create object for id [%s]'), $id);
    }

    static public function createAll()
    {
        $res = [];
        foreach (self::$elements as $id => $desc) {
            $res[$id] = new $desc['class'];
        }
        return $res;
    }

    static public function getOptions()
    {
        $options = [];
        foreach (self::$elements as $id => $desc) {
            $options[$id] = $desc['title'];
        }
        return $options;
    }
}

/**
 * Export as CSV file
 * @package Am_Grid
 */
class Am_Grid_Export_CSV implements Am_Grid_Export_Processor
{
    const EXPORT_REC_LIMIT = 1024;

    protected $filenameTemplate = "amember%grid_id%-%dat%";

    public function buildForm($form)
    {
        $form->addText('delim', ['size' => 3, 'value' => ','])
                ->setLabel(___('Fields delimited by'));
    }

    public function run(Am_Grid_Editable $grid, Am_Grid_DataSource_Interface_ReadOnly $dataSource, $fields, $config)
    {
        set_time_limit(0);
        ini_set('memory_limit', AM_HEAVY_MEMORY_LIMIT);

        header('Cache-Control: maxage=3600');
        header('Pragma: public');
        header("Content-type: text/csv");
        header("Content-Disposition: attachment; filename={$this->getFileName($grid)}.csv");

        $total = $dataSource->getFoundRows();
        $numOfPages = ceil($total / self::EXPORT_REC_LIMIT);
        $delim = $config['delim'];

        //render headers
        foreach ($fields as $field) {
            echo amEscapeCsv(
                    $field->getFieldTitle(), $delim
            ) . $delim;
        }
        echo "\r\n";

        //render content
        for ($i = 0; $i < $numOfPages; $i++) {
            $ret = $dataSource->selectPageRecords($i, self::EXPORT_REC_LIMIT);
            foreach ($ret as $r) {
                foreach ($fields as $field) {
                    echo amEscapeCsv(
                            $field->get($r, $grid), $delim
                    ) . $delim;
                }
                echo "\r\n";
            }
        }
        return;
    }

    public function setFilenameTemplate(string $filename)
    {
        $this->filenameTemplate = $filename;
    }

    protected function getFileName($grid)
    {
        $tmp = new Am_SimpleTemplate();
        $tmp->assign('grid_id', $grid->getId());
        $tmp->assign('time', Am_Di::getInstance()->time);
        $tmp->assign('dat', date('YmdHis'));
        $tmp->assignStdVars();

        return str_replace('/', '_', $tmp->render($this->filenameTemplate));
    }
}

/**
 * Export as XML
 * @package Am_Grid
 */
class Am_Grid_Export_XML implements Am_Grid_Export_Processor
{
    const EXPORT_REC_LIMIT = 1024;

    protected $filenameTemplate = "amember%grid_id%-%dat%";

    public function buildForm($form)
    {
        //nop
    }

    public function run(Am_Grid_Editable $grid, Am_Grid_DataSource_Interface_ReadOnly $dataSource, $fields, $config)
    {
        header('Cache-Control: maxage=3600');
        header('Pragma: public');
        header("Content-type: application/xml");
        $dat = date('YmdHis');
        header("Content-Disposition: attachment; filename={$this->getFileName($grid)}.xml");

        $total = $dataSource->getFoundRows();
        $numOfPages = ceil($total / self::EXPORT_REC_LIMIT);

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument();

        $xml->startElement('export');
        for ($i = 0; $i < $numOfPages; $i++) {
            $ret = $dataSource->selectPageRecords($i, self::EXPORT_REC_LIMIT);
            foreach ($ret as $r) {
                $xml->startElement('row');
                foreach ($fields as $field) {
                    $xml->startElement('field');
                    $xml->writeAttribute('name', $field->getFieldTitle());
                    $xml->text($field->get($r, $grid));
                    $xml->endElement(); // field
                }
                $xml->endElement();
            }
        }
        $xml->endElement();
        echo $xml->flush();
        return;
    }

    public function setFilenameTemplate(string $filename)
    {
        $this->filenameTemplate = $filename;
    }

    protected function getFileName($grid)
    {
        $tmp = new Am_SimpleTemplate();
        $tmp->assign('grid_id', $grid->getId());
        $tmp->assign('time', Am_Di::getInstance()->time);
        $tmp->assign('dat', date('YmdHis'));
        $tmp->assignStdVars();

        return str_replace('/', '_', $tmp->render($this->filenameTemplate));
    }
}

/**
 * Export as PDF file
 * @package Am_Grid
 */
class Am_Grid_Export_PDF implements Am_Grid_Export_Processor
{
    const EXPORT_REC_LIMIT = 1024;

    protected $filenameTemplate = "amember%grid_id%-%dat%";

    public function buildForm($form)
    {
        $form->addAdvRadio('pdf_format', ['value' => Zend_Pdf_Page::SIZE_A4])
            ->setLabel(___('Pdf Format'))
            ->loadOptions([
                Zend_Pdf_Page::SIZE_A4 => ___('A4 Portrait'),
                Zend_Pdf_Page::SIZE_A4_LANDSCAPE => ___('A4 Landscape'),
                Zend_Pdf_Page::SIZE_LETTER => ___('US Letter Portrait'),
                Zend_Pdf_Page::SIZE_LETTER_LANDSCAPE => ___('US Letter Landscape'),
                '612:1008:' => ___('US Legal Portrait'),
                '1008:612:' => ___('US Legal Landscape'),
            ]);
    }

    public function run(Am_Grid_Editable $grid, Am_Grid_DataSource_Interface_ReadOnly $dataSource, $fields, $config)
    {
        set_time_limit(0);
        ini_set('memory_limit', AM_HEAVY_MEMORY_LIMIT);

        header('Cache-Control: maxage=3600');
        header('Pragma: public');

        $total = $dataSource->getFoundRows();
        $numOfPages = ceil($total / self::EXPORT_REC_LIMIT);
        $format = $config['pdf_format'];

        [$width, $height] = explode(':', $format);
        $padd = 20;
        $left = $padd;
        $right = $width - $padd;

        $pdf = new Zend_Pdf();

        $pdf->pages[0] = $pdf->newPage($format);
        $page = new Am_Pdf_Page_Decorator($pdf->pages[0]);
        $page->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 8);

        $pointer = $height;

        $this->renderPdfHeader($page, $padd, $pointer);

        $table = new Am_Pdf_Table(function ($page, & $y) use ($pdf, $padd, $format, $height) {
            $pdf->pages[] = $_ = $pdf->newPage($format);
            $p = new Am_Pdf_Page_Decorator($_);
            $p->setFont($page->getFont(), $page->getFontSize());
            $pointer = $height;
            $this->renderPdfHeader($p, $padd, $pointer);
            $y = $pointer;
            return $p;
        }, 15);
        $table->setMargin(0, $padd, $padd, $padd);
        $table->setStyleForRow(
            1, [
            'shape' => [
                'type' => Zend_Pdf_Page::SHAPE_DRAW_STROKE,
                'color' => new Zend_Pdf_Color_Html("#cccccc")
            ],
            'font' => [
                'face' => Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA),
                'size' => 8
            ]]);

        $table->addRow(array_map(function($field) {return $field->getFieldTitle();}, $fields));

        for ($i = 0; $i < $numOfPages; $i++) {
            $ret = $dataSource->selectPageRecords($i, self::EXPORT_REC_LIMIT);
            foreach ($ret as $r) {
                $table->addRow(array_map(function($field) use ($r, $grid) {return $field->get($r, $grid);}, $fields));
            }
        }

        $page->drawTable($table, 0, $pointer);

        $pCount = count($pdf->pages);
        $pIndex = 1;
        foreach ($pdf->pages as $p) {
            $pointer = $height;
            $pd = new Am_Pdf_Page_Decorator($p);
            $pd->nl($pointer);
            $pd->nl($pointer);
            $pd->drawText(sprintf('Page %d/%d', $pIndex++, $pCount), $width - $padd, $pointer, 'UTF-8', Am_Pdf_Page_Decorator::ALIGN_RIGHT);
        }

        $helper = new Am_Mvc_Controller_Action_Helper_SendFile();
        $helper->sendData($pdf->render(), 'application/pdf', "{$this->getFileName($grid)}.pdf");
        throw new Am_Exception_Redirect;

    }

    public function setFilenameTemplate(string $filename)
    {
        $this->filenameTemplate = $filename;
    }

    function renderPdfHeader($page, $padd, & $pointer)
    {
        $page->nl($pointer);
        $page->nl($pointer);
        $page->nl($pointer);
    }

    protected function getFileName($grid)
    {
        $tmp = new Am_SimpleTemplate();
        $tmp->assign('grid_id', $grid->getId());
        $tmp->assign('time', Am_Di::getInstance()->time);
        $tmp->assign('dat', date('YmdHis'));
        $tmp->assignStdVars();

        return str_replace('/', '_', $tmp->render($this->filenameTemplate));
    }
}

Am_Grid_Export_Processor_Factory::register('csv', 'Am_Grid_Export_CSV', 'CSV');
Am_Grid_Export_Processor_Factory::register('xml', 'Am_Grid_Export_XML', 'XML');
Am_Grid_Export_Processor_Factory::register('pdf', 'Am_Grid_Export_PDF', 'PDF');

/**
 * Grid action to display "export" option
 * @package Am_Grid
 */
class Am_Grid_Action_Export extends Am_Grid_Action_Abstract
{
    protected $privilege = 'export';
    protected $type = self::HIDDEN;
    protected $fields = [];
    protected $usePreset = true;
    protected $getDataSourceFunc = null;
    protected $filenameTemplate = null;

    public function run()
    {
        if ($this->usePreset) {
            //delete preset
            if ($_ = $this->grid->getRequest()->getParam('preset_delete')) {
                $this->deletePreset($_);
                return $this->grid->redirectBack();
            }

            //use preset
            if ($_ = $this->grid->getRequest()->getParam('preset')) {
                $values = $this->getPreset($_);

                $this->_do($values);
            }
        }

        //normal flow
        $form = new Am_Form_Admin();
        $form->setAction($this->getUrl());
        $form->setAttribute('name', 'export');
        $form->setAttribute('target', '_blank');

        $form->addSortableMagicSelect('fields_to_export', ['class'=>'am-combobox-fixed'])
            ->loadOptions($this->getExportOptions())
            ->setLabel(___('Fields To Export'))
            ->setJsOptions(<<<CUT
{
    allowSelectAll:true,
    sortable: true,
}
CUT
            )
            ->addRule('required');

        $form->addSelect('export_type')
            ->loadOptions(Am_Grid_Export_Processor_Factory::getOptions())
            ->setLabel(___('Export Format'))
            ->setId('form-export-type');

        foreach (Am_Grid_Export_Processor_Factory::createAll() as $id => $obj) {
            $obj->buildForm($form->addFieldset($id)->setId('form-export-options-' . $id));
        }

        if ($this->usePreset) {
            $g = $form->addGroup();
            $g->setSeparator(' ');
            $g->setLabel(___("Save As Preset\n" .
                "for future quick access"));
            $g->addAdvCheckbox('preset');
            $g->addText('preset_name', ['placeholder' => ___('Preset Name')]);
        }

        $form->addSubmit('export', ['value' => ___('Export')]);

        $script = <<<CUT
    jQuery(function(){
        jQuery('[name=preset]').change(function(){
            jQuery(this).next('input').toggle(this.checked);
        }).change();

        function update_options(\$sel) {
            jQuery('[id^=form-export-options-]').hide();
            jQuery('#form-export-options-' + \$sel.val()).show();
        }

        update_options(jQuery('#form-export-type'));
        jQuery('#form-export-type').bind('change', function() {
            update_options(jQuery(this));
        })
    });
CUT;
        $form->addScript('script')->setScript($script);

        $this->initForm($form);

        if ($form->isSubmitted() && $form->validate()) {
            $values = $form->getValue();

            if ($this->usePreset) {
                //save preset
                if ($values['preset']) {
                    $this->savePreset($values['preset_name'] ?: 'Export Preset', $values);
                }
            }

            $this->_do($values);
        } else {
            echo $this->renderTitle();
            echo $form;
        }
    }

    function _do($values)
    {
        $fields = [];
        foreach ($values['fields_to_export'] as $fieldName) {
            $fields[$fieldName] = $this->getField($fieldName);
        }

        $this->log();
        $export = Am_Grid_Export_Processor_Factory::create($values['export_type']);
        if ($this->filenameTemplate) {
            $export->setFilenameTemplate($this->filenameTemplate);
        }
        $export->run($this->grid, $this->getDataSource($fields), $fields, $values);
        exit;
    }

    public function setFilenameTemplate(string $filename)
    {
        $this->filenameTemplate = $filename;
    }

    /**
     * can be used to customize datasource to add some UNION for example
     *
     * @param type $callback
     */
    public function setGetDataSourceFunc($callback)
    {
        if (!is_callable($callback))
            throw new Am_Exception_InternalError("Invalid callback in " . __METHOD__);

        $this->getDataSourceFunc = $callback;
    }

    public function addField(Am_Grid_Field $field)
    {
        $this->fields[] = $field;
        return $this;
    }

    /**
     * @param string $fieldName
     * @return Am_Grid_Field
     */
    public function getField($fieldName)
    {
        foreach ($this->getFields() as $field)
            if ($field->getFieldName() == $fieldName)
                return $field;
        throw new Am_Exception_InternalError("Field [$fieldName] not found in " . __METHOD__);
    }

    protected function getFields()
    {
        return count($this->fields) ? $this->fields : $this->grid->getFields();
    }

    protected function initForm($form)
    {
        $form->setDataSources([$this->grid->getCompleteRequest()]);

        $vars = [];
        foreach ($this->grid->getVariablesList() as $k) {
            $vars[$this->grid->getId() . '_' . $k] = $this->grid->getRequest()->get($k, "");
        }
        foreach (Am_Html::getArrayOfInputHiddens($vars) as $name => $value) {
            $form->addHidden($name)->setValue($value);
        }
    }

    /**
     * @return Am_Grid_DataSource_Interface_ReadOnly
     */
    protected function getDataSource($fields)
    {
        return $this->getDataSourceFunc ?
                call_user_func($this->getDataSourceFunc, $this->grid->getDataSource(), $fields) :
                $this->grid->getDataSource();
    }

    protected function getExportOptions()
    {
        $res = [];

        foreach ($this->getFields() as $field) {
            if (in_array($field->getFieldName(), ['_checkboxes', '_actions'])) continue;
            /* @var $field Am_Grid_Field */
            $res[$field->getFieldName()] = $field->getFieldTitle();
        }

        return $res;
    }

    public function setGrid(Am_Grid_Editable $grid)
    {
        parent::setGrid($grid);
        if ($this->hasPermissions()) {
            $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_TABLE, [$this, 'renderLink']);
        }
    }

    public function getPreset($name)
    {
        if (($_ = $this->grid->getDi()->store->getBlob($this->storeKey())) &&
            ($_ = json_decode($_, true)) &&
            isset($_[$name])) {

            return $_[$name];
        }
    }

    public function getAllPresets()
    {
        $preset = null;
        if ($_ = $this->grid->getDi()->store->getBlob($this->storeKey())) {
            $preset = json_decode($_, true);
        }
        return $preset ?: [];
    }

    public function savePreset($name, $values)
    {
        $preset = $this->getAllPresets();
        $preset[$name] = $values;
        $this->grid->getDi()->store->setBlob($this->storeKey(), json_encode($preset));
    }

    public function deletePreset($name)
    {
        $preset = $this->getAllPresets();
        unset($preset[$name]);
        $this->grid->getDi()->store->setBlob($this->storeKey(), $preset ? json_encode($preset) : null);
    }

    public function renderLink(& $out)
    {
        if ($this->usePreset && $preset = $this->getAllPresets()) {
            $id = $this->grid->getId();
            $a_id = $this->getId();
            $links = [];
            foreach (array_keys($preset) as $op) {
                $links[] = sprintf('<li class="grid-action-export-preset-list-item"><a href="%s" class="link" target="_top">%s</a><span class="grid-action-export-preset-list-action"> &ndash; <a href="%s" target="_top" class="link" onclick="return confirm(\'Are you sure?\')">delete</a></span></li>',
                    $this->getUrl() . "&{$id}_preset=" . urlencode($op), Am_Html::escape($op),
                    $this->getUrl() . "&{$id}_preset_delete="  . urlencode($op));
            }
            $p_id = sprintf("%s-preset", $a_id);
            $p_id_j = json_encode($p_id);
            $out .= sprintf(<<<CUT
<div style="float:right">&nbsp;(<a class="local" href="javascript:;" onclick='openPresetPopup($p_id_j);'>%s</a>)
    <div id="$p_id" style="display:none;">
        <ul class="grid-action-export-preset-list">%s</ul>
    </div>
</div>
<script>
if (openPresetPopup === undefined) {
    function openPresetPopup(id)
    {
        jQuery(function(){
            jQuery(`#\${id}`).dialog({
                buttons: {
                    'Close' : function() { jQuery(this).dialog("close"); }
                },
                close: function() { jQuery(this).dialog("destroy"); },
                modal: true,
                title: %s,
                autoOpen: true,
            })
        })
    }
}
</script>
CUT
                ,___('Presets'), implode("\n", $links), json_encode(___('Export Presets')));
        }

        $out .= sprintf('<div style="float:right">&nbsp;&nbsp;&nbsp;<a class="link" href="%s">'. $this->getTitle() .'</a></div>',
            $this->getUrl());
    }

    protected function storeKey()
    {
        $id = $this->grid->getId();
        return sprintf('%s-preset-%s', $this->getId(), $id);
    }
}