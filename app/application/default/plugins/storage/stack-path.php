<?php
/**
 * @title StackPath
 * @logo_url stack-path.png
 */

require_once __DIR__ . '/s3.php';

class Am_Storage_StackPath extends Am_Storage_S3
{
    protected $_endpoints = [
        'us-east-2' => 's3.us-east-2.stackpathstorage.com',
        'us-west-1' => 's3.us-west-1.stackpathstorage.com',
        'eu-central-1' => 's3.eu-central.stackpathstorage.com',
    ];
    protected $_regions = [
        'us-east-2' => 'us-east-2',
        'us-west-1' => 'us-west-1',
        'eu-central-1' => 'eu-central-1',
    ];

    function getTitle()
    {
        return 'StackPath';
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('access_key', ['class' => 'am-el-wide'])
            ->setLabel('StackPath Access Key')
            ->addRule('required')
            ->addRule('regex', 'must be alphanumeric', '/^[A-Z0-9]+$/');
        $form->addSecretText('secret_key', ['class' => 'am-el-wide'])
            ->setLabel('StackPath Secret Key')
            ->addRule('required');

        $form->addSelect('region')
            ->loadOptions($this->_regions)
            ->setLabel('StackPath Region');
        $form->addText('expire', ['size' => 5])
            ->setLabel('Video link lifetime, min');
        $form->setDefault('expire', 15);
        $form->addAdvCheckbox('use_ssl')
            ->setLabel(___("Use SSL for Authenticated URLs\n" .
                "enable this option if you use https for your site"));

        $msg = ___('Your content  should not be public.
            Please restrict public access to your files
            and ensure you can not access it directly from StackPath.
            aMember use Access Key and Secret Key to generate links with
            authentication token for users to provide access them to your
            content on StackPath.');

        $form->addProlog(<<<CUT
<div class="info"><strong>$msg</strong></div>
CUT
        );
    }

    public function getDescription()
    {
        return $this->isConfigured() ?
            ___("Files located on StackPath storage. (Warning: Your buckets should not contain letters in uppercase in its name)") :
            ___("StackPath storage is not configured");
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