<?php
/**
 * @title MojoCloud Object Storage
 */

require_once __DIR__ . '/s3.php';

class Am_Storage_MojoCloud extends Am_Storage_S3
{
    protected $_endpoints = [
        'us-north-1' => 'us-north-1.mojocloud.com',
    ];
    protected $_regions = [
        'us-north-1' => 'US North',
    ];

    function getTitle()
    {
        return 'MojoCloud Object Storage';
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('access_key', ['class' => 'am-el-wide'])
            ->setLabel('Access Key')
            ->addRule('required')
            ->addRule('regex', 'must be alphanumeric', '/^[A-Z0-9]+$/');
        $form->addSecretText('secret_key', ['class' => 'am-el-wide'])
            ->setLabel('Secret Key')
            ->addRule('required');

        $form->addSelect('region')
            ->loadOptions($this->_regions)
            ->setLabel('Region');
        $form->addText('expire', ['size' => 5])
            ->setLabel('Video link lifetime, min');
        $form->setDefault('expire', 15);
        $form->addAdvCheckbox('use_ssl')
            ->setLabel(___("Use SSL for Authenticated URLs\n" .
                "enable this option if you use https for your site"));

        $msg = ___('Your content  should not be public.
            Please restrict public access to your files
            and ensure you can not access it directly from MojoCloud Object Storage.
            aMember use Access Key and Secret Key to generate links with
            authentication token for users to provide access them to your
            content on Spaces.');

        $form->addProlog(<<<CUT
<div class="info"><strong>$msg</strong></div>
CUT
        );
    }

    public function getDescription()
    {
        return $this->isConfigured() ?
            ___("Files located on MojoCloud Object Storage storage. (Warning: Your buckets should not contain letters in uppercase in its name)") :
            ___("MojoCloud Object Storage storage is not configured");
    }

    protected function getConnector()
    {
        if (!$this->_connector)
        {
            $this->_connector = new S3($this->getConfig('access_key'), $this->getConfig('secret_key'), true, $this->getEndpoint(), $this->getConfig('region'));
            $this->_connector->setRequestClass('S3Request_HttpRequest4');
        }

        return $this->_connector;
    }
}