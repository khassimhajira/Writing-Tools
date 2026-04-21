<?php
/**
 * @title Contabo
 */

require_once __DIR__ . '/s3.php';

class Am_Storage_Contabo extends Am_Storage_S3
{
    protected $_endpoints = [
        'eu2' => 'eu2.contabostorage.com',
        'sin1' => 'sin1.contabostorage.com',
        'usc1' => 'usc1.contabostorage.com',
    ];
    protected $_regions = [
        'eu2' => 'European Union',
        'sin1' => 'Singapore',
        'usc1' => 'United States',
    ];

    function getTitle()
    {
        return 'Contabo';
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('access_key', ['class' => 'am-el-wide'])
            ->setLabel('S3 Object Storage Access Key')
            ->addRule('required')
            ->addRule('regex', 'must be alphanumeric', '/^[a-zA-Z0-9]+$/');
        $form->addSecretText('secret_key', ['class' => 'am-el-wide'])
            ->setLabel('S3 Object Storage Secret Key')
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
            and ensure you can not access it directly from Contabo.
            aMember use Access Key and Secret Key to generate links with
            authentication token for users to provide access them to your
            content on Contabo.');

        $form->addProlog(<<<CUT
<div class="info"><strong>$msg</strong></div>
CUT
        );
    }

    public function getDescription()
    {
        return $this->isConfigured() ?
            ___("Files located on Contabo storage. (Warning: Your buckets should not contain letters in uppercase in its name)") :
            ___("Contabo  storage is not configured");
    }

    protected function getConnector()
    {
        if (!$this->_connector)
        {
            $this->_connector = new S3($this->getConfig('access_key'), $this->getConfig('secret_key'), true, $this->getEndpoint(), $this->getConfig('region'));
            $this->_connector->setRequestClass('S3Request_Contabo');
        }

        return $this->_connector;
    }
}

class S3Request_Contabo extends S3Request_HttpRequest4
{
    function __construct($verb, $bucket = '', $uri = '', $endpoint = 's3.amazonaws.com')
    {
        parent::__construct($verb, $bucket, $uri, $endpoint);
        $this->headers['Host'] = $this->endpoint;
        if ($this->bucket !== '')
        {
            $this->uri = '/'.$this->bucket.$this->uri;
        }
    }
    function getAuthenticatedURL(S3 $s3, $bucket, $uri, $lifetime, $hostBucket = false, $https = false, $force_download = true)
    {
        unset($this->headers['Date']);
        unset($this->headers['Content-MD5']);
        $secretKey = $s3->getSecretKey();
        $timestamp = time();
        $longDate = gmdate('Ymd\THis\Z', $timestamp);
        $shortDate = substr($longDate, 0, 8);

        $region = $s3->getServiceRegion();
        $service = 's3';

        $credentialScope = $this->createScope($shortDate, $region, $service);

        $query = parse_url($uri, PHP_URL_QUERY);
        $queryParams = [];
        $uri = parse_url($uri, PHP_URL_PATH);
        $queryParams['X-Amz-Algorithm'] = 'AWS4-HMAC-SHA256';
        $queryParams['X-Amz-Credential'] = "{$s3->getAccessKey()}/{$credentialScope}";
        $queryParams['X-Amz-Date'] = $longDate;
        $queryParams['X-Amz-Expires'] = $lifetime;
        $queryParams['X-Amz-SignedHeaders'] = 'host';
        if ($force_download) {
            $_ = explode('/', $uri);
            $filename = array_pop($_);
            $queryParams['response-content-disposition'] = "attachment;filename=$filename";
        }
        $payload = 'UNSIGNED-PAYLOAD';
        $this->uri = $this->bucket."/".$uri."?".http_build_query($queryParams);

        $signingContext = $this->createSigningContext($s3, $this->headers, $payload);
        $signingContext['string_to_sign'] = $this->createStringToSign(
            $longDate,
            $credentialScope,
            $signingContext['canonical_request']
        );

        $signingKey = $this->getSigningKey($shortDate, $region, $service, $secretKey);
        $signature = hash_hmac('sha256', $signingContext['string_to_sign'], $signingKey);
        $this->uri .= "&X-Amz-Signature={$signature}";

        return sprintf(($https ? 'https' : 'http').'://%s/%s',
            $hostBucket ? $bucket : $s3->endpoint, $this->uri);
    }

}