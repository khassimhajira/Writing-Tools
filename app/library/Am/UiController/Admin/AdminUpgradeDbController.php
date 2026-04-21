<?php
/*
*
*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.cgi-central.net
*    Details: upgrade DB from ../amember.sql
*    FileName $RCSfile$
*    Release: 6.3.39 ($Revision$)
*
* Please direct bug reports,suggestions or feedback to the cgi-central forums.
* http://www.cgi-central.net/forum/
*
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*
*/

class AdminUpgradeDbController extends Am_Mvc_Controller
{
    protected $db_version;

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->isSuper();
    }

    function printHeader()
    {
        if (defined('HP_ROOT_DIR') || php_sapi_name() == 'cli') return;
        echo <<<CUT
        <!doctype html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
            <title>aMember Database Upgrade</title>
            <style>
            body {
                font-family: Arial;
                background:#EDEDED;
                text-align: center;
            }
            h1 {font-size:1.5rem; font-weight: normal; margin:0.2em 0}
            a, a:visited {
                color: #34536e;
            }
            .am-upgrade-body {
                display:inline-block;
                text-align: left;
                max-width:800px;
            }
            </style>
        </head>
        <body>
        <div class="am-upgrade-body">
        <h1>aMember Database Upgrade</h1>
        <hr />
CUT;
    }

    function printFooter()
    {
        if (defined('HP_ROOT_DIR') || php_sapi_name() == 'cli') return;
        echo '</body></html>';
    }

    function renderAutoRedirect()
    {
        $timeout = defined('HR_ROOT_DIR') ? 3 : 5;
        return <<<CUT
        <br /><br />
        <div id='redirect-div' style='display:none'>Your browser will be automatically redirected in <span id='time-left'>$timeout</span> seconds...
        <a href='javascript:cancelUpgradeRedirect()'>Cancel</a>
        </div>
        <script>
            let el = document.getElementById('time-left');
            let redirectDiv = document.getElementById('redirect-div')
            redirectDiv.style.display = 'block';
            let count = $timeout;
            let cancelUpgradeRed = false;
            if (!cancelUpgradeRed)
            {
                setInterval(function(){
                    if (cancelUpgradeRed) {
                        return;
                    }
                    count--;
                    if (count <= 0)
                        document.getElementById('upgrade-redirect-link').click();
                    else
                        el.innerText = count;
                }, 1*1000);
            }
            function cancelUpgradeRedirect() {
                cancelUpgradeRed = true;
                if (redirectDiv) {
                    redirectDiv.parentElement.removeChild(redirectDiv);
                }
                redirectDiv = null;
            }
        </script>
CUT;
    }

    function indexAction()
    {
        $this->getDi()->db->setLogger(false);

        $t = new Am_View;
        set_time_limit(0);
        ini_set('memory_limit', AM_HEAVY_MEMORY_LIMIT);

        $this->db_version = $this->getDi()->store->get('db_version');

        if (defined('AM_DEBUG')) ob_start();
        $this->printHeader();

        $this->doUpgradeDb();

        if (php_sapi_name() == 'cli') {
            echo "Db upgrade finished\n";
        } else {

            $version = AM_VERSION;
            $year = date('Y');

            $return_url = $this->getDi()->url('admin');
            $auto_redirect = $this->renderAutoRedirect();

            echo <<<CUT
        <br/>
            <strong>Upgrade finished successfully.</strong>
            <strong>Go to </strong><a id="upgrade-redirect-link" href="$return_url">aMember Admin CP</a>.
            $auto_redirect
            <hr />
            <div style="text-align:center; font-size:70%">
                aMember Pro&trade; $version by <a href="http://www.amember.com">aMember.com</a>  &copy; 2002&ndash;$year CGI-Central.Net
            </div>
        </div>
CUT;
        }
        $this->printFooter();
    }

    function convertStandardNewsletters()
    {
        $this->getDi()->db->query("UPDATE ?_newsletter_list SET plugin_id='standard' WHERE plugin_id IS NULL OR plugin_id=''");
    }

    function fixNotUniqueRecordInRebillLog()
    {
        //to set unique index (invoice_id,rebill_date)
        if (version_compare($this->db_version, '4.2.15') < 0)
        {
            $db = $this->getDi()->db;
            try { //to handle situation when ?_cc_rebill table does not exists
                $db->query('CREATE TEMPORARY TABLE ?_cc_rebill_temp (
                    cc_rebill_id int not null,
                    tm_added datetime not null,
                    paysys_id varchar(64),
                    invoice_id int,
                    rebill_date date,
                    status smallint,
                    status_tm datetime,
                    status_msg varchar(255),
                    UNIQUE INDEX(invoice_id, rebill_date))');

                $db->query('
                    INSERT IGNORE INTO ?_cc_rebill_temp
                    SELECT * FROM ?_cc_rebill
                ');

                $db->query("TRUNCATE ?_cc_rebill");

                $db->query('
                    INSERT INTO ?_cc_rebill
                    SELECT * FROM ?_cc_rebill_temp
                ');

                $db->query("DROP TABLE ?_cc_rebill_temp");
            } catch (Exception $e) {

            }
        }
    }

    function fillResourceAccessSort()
    {
        $this->getDi()->resourceAccessTable->syncSortOrder();
    }

    function manuallyApproveInvoices()
    {
        if (
            version_compare($this->db_version, '4.2.4') < 0
            || (version_compare(AM_VERSION, '4.2.7') <= 0 && !$this->getDi()->config->get('manually_approve_invoice'))
        ) {
            echo "Manually approve old invoices...";
            @ob_end_flush();

            $this->getDi()->db->query("update ?_invoice set is_confirmed=1");
            echo "Done<br/>\n";
        }
    }

    function checkInvoiceItemTotals()
    {
        if (version_compare($this->db_version, '4.1.8') < 0)
        {
            echo "Update invoice_item.total columns...";
            @ob_end_flush();
            $this->getDi()->db->query("
                UPDATE ?_invoice_item
                SET
                    first_total = first_price*qty - first_discount + first_shipping + first_tax,
                    second_total = second_price*qty - second_discount + second_shipping + second_tax
                WHERE
                    ((first_total IS NULL OR first_total = 0) AND first_price > 0)
                OR
                    ((second_total IS NULL OR second_total = 0) AND second_price > 0)
                ");
            echo "Done<br>\n";
        }
    }

    function convertTax()
    {
        if (version_compare($this->db_version, '4.2.0') < 0)
        {
            echo "Move product.no_tax -> product.tax columns...";
            @ob_end_flush();
            try {
                $this->getDi()->db->query("
                UPDATE ?_product
                SET tax_group = IF(IFNULL(no_tax, 0) = 0, 0, 1)
                ");
//                $this->getDi()->db->query("ALTER TABLE ?_product DROP no_tax");
            } catch (Am_Exception_Db $e) { }

            echo "Move invoice_item.no_tax -> invoice_item.tax_group columns...";
            @ob_end_flush();
            try {
               $this->getDi()->db->query("
                UPDATE ?_invoice_item
                SET tax_group = IF(IFNULL(no_tax, 0) = 0, 0, 1)
                ");
//                $this->getDi()->db->query("ALTER TABLE ?_invoice_item DROP no_tax");
            } catch (Am_Exception_Db $e) { }
            echo "Done<br>\n";

            echo "Migrate tax settings...";
            if ($this->getDi()->config->get('use_tax'))
            {
                $config = $this->getDi()->config;
                $config->read();
                switch ($this->getDi()->config->get('tax_type'))
                {
                    case 1:
                        $config->set('plugins.tax', ['global-tax']);
                        $config->set('tax.global-tax.rate', $config->get('tax_value'));
                        break;
                    case 2:
                        $config->set('plugins.tax', ['regional']);
                        $config->set('tax.regional.taxes', $config->get('regional_taxes'));
                        break;
                }
                $arr = $config->getArray();
                unset($arr['tax_type']);
                unset($arr['regional_taxes']);
                unset($arr['tax_value']);
                unset($arr['use_tax']);
                $config->setArray($arr);
                $config->save();
            }
            echo "Done<br>\n";
        }
    }

    function convertAutoresponderPrefix()
    {
        if (version_compare($this->db_version, '4.2.0') < 0)
        {
            echo "Convert Autoresponder Prefix From [emailtemplate] to [email-messages]";
            @ob_end_flush();
            try {
                $rows = $this->getDi()->db->query("
                SELECT * FROM ?_email_template
                WHERE name IN ('autoresponder', 'expire') AND attachments IS NOT NULL
                ");

                $upload_ids = [];
                foreach ($rows as $row) {
                    $upload_ids = array_merge($upload_ids, explode(',', $row['attachments']));
                }

                if (count($upload_ids)) {
                    $templates = [];
                    foreach ($upload_ids as $id) {
                        $rows = $this->getDi()->db->query("
                            SELECT * FROM ?_email_template
                            WHERE name NOT IN ('autoresponder', 'expire')
                            AND (attachments=? OR attachments LIKE ?
                            OR attachments LIKE ? OR attachments LIKE ?)",
                            $id,
                            '%,'.$id,
                            $id.',%',
                            '%,'.$id.',%'
                            );
                        $templates = array_merge($templates, $rows);
                    }

                    if (count($templates)) {
                        $names = [];
                        foreach ($templates as $tpl) {
                            $names[] = sprintf('%s [%s]', $tpl['name'], $tpl['lang']);
                        }

                        echo sprintf(' <span style="color:#F44336;">Please reupload attachments for the following templates: %s</span><br />',
                            implode(', ', $names));
                    }

                    $this->getDi()->db->query("UPDATE ?_upload SET prefix=? WHERE upload_id IN (?a)",
                        'email-messages', $upload_ids);
                }
            } catch (Am_Exception_Db $e) { }
            echo "Done<br>\n";
        }
    }

    function checkResourceAccessEmailTemplates()
    {
        if (version_compare($this->db_version, '4.1.14') < 0)
        {
            echo "Update resource access table ...";
            @ob_end_flush();
            $this->getDi()->db->query("
                    UPDATE ?_resource_access
                    SET
                    start_days = (SELECT day FROM ?_email_template WHERE email_template_id=resource_id),
                    stop_days = (SELECT day FROM ?_email_template WHERE email_template_id=resource_id)
                    WHERE resource_type = 'emailtemplate' AND fn='free' AND start_days IS NULL
                    ");
            echo "Done<br>\n";

        }
    }

    function enableSkipIndexPage()
    {
        if (version_compare($this->db_version, '4.1.16') < 0)
        {
            echo "Enable skip_index_page option...";
            if (ob_get_level()) ob_end_flush();
            $_ = $this->getDi()->db->selectCell("SELECT config FROM ?_config WHERE name = ?", 'default');
            $config = substr($_, 0, 2) == 'a:' ? unserialize($_) : json_decode($_, true);
            if (!isset($config['skip_index_page'])) {
                $config['skip_index_page'] = 1;
                $this->getDi()->db->selectCol("UPDATE ?_config SET config=? WHERE name = ?", serialize($config), 'default');
            }

            echo "Done<br>\n";

        }
    }

    function addCountryCodes()
    {
        if (version_compare($this->db_version, '4.2.10') < 0)
        {
            echo "Add country codes...";
            if (ob_get_level()) ob_end_flush();
            $query = file_get_contents(dirname(AM_APPLICATION_PATH) . '/library/sql-country.sql');
            $query = str_replace('@DB_MYSQL_PREFIX@', '?_', $query);
            $this->getDi()->db->query($query);
            echo "Done<br>\n";
        }
    }

    function fixCryptSavedPass()
    {
        if (version_compare($this->db_version, '4.2.16') < 0)
        {
            echo "Fix crypt saved pass...";
            if (ob_get_level()) ob_end_flush();
            $this->getDi()->db->query("UPDATE ?_saved_pass SET salt=pass WHERE format=?", 'crypt');
            echo "Done<br>\n";
        }
    }

    function updateStateInfo()
    {
        if (version_compare($this->db_version, '4.2.16') < 0)
        {
            echo "Update State Info...";
            if (ob_get_level()) ob_end_flush();
            $query =  file_get_contents(dirname(AM_APPLICATION_PATH) . '/library/sql-state.sql');
            $query = str_replace('@DB_MYSQL_PREFIX@', '?_', $query);
            $this->getDi()->db->query($query);
            echo "Done<br>\n";
        }
    }

    function fixCustomFieldSortTableName()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Rename custom_fields_sort to custom_field_sort...";
            if (ob_get_level()) ob_end_flush();
            try {
                //actually we move data from old table to new one here to leave user preference
                if (!$this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_custom_field_sort")) {
                    $this->getDi()->db->query("SET @i = 0");
                    $this->getDi()->db->query("INSERT INTO ?_custom_field_sort (custom_field_table, custom_field_name, sort_order)
                        SELECT custom_field_table, custom_field_name, (@i:=@i+1) FROM ?_custom_fields_sort ORDER BY sort_order");
                }
            } catch (Exception $e) {
                //nop, handle situation for upgrade from version where ?_custom_fields_sort is not exists yet
            }
            echo "Done<br>\n";
        }
    }

    function normalizeProductSortOrder()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Normalize sort order for products...";
            if (ob_get_level()) ob_end_flush();
            $this->getDi()->db->query("SET @i = 0");
            $this->getDi()->db->query("UPDATE ?_product SET sort_order=(@i:=@i+1) ORDER BY sort_order");
            echo "Done<br>\n";
        }
    }

    function normalizeSavedSearchSortOrder()
    {
        if (version_compare($this->db_version, '6.1.4') <= 0)
        {
            echo "Normalize sort order for saved search...";
            if (ob_get_level()) ob_end_flush();
            $this->getDi()->db->query("SET @i = 0");
            $this->getDi()->db->query("UPDATE ?_saved_search SET sort_order=(@i:=@i+1) ORDER BY sort_order");
            echo "Done<br>\n";
        }
    }

    function fixNotUniquePathInPages()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Fix Not Unique Path in Pages...";
            if (ob_get_level()) ob_end_flush();
            $this->getDi()->db->query("UPDATE ?_page SET path = page_id WHERE path=''");
            echo "Done<br>\n";
        }
    }

    function fixPagePath()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Fix Page Path...";
            if (ob_get_level()) ob_end_flush();
            $this->getDi()->db->query("UPDATE ?_page SET path = NULL WHERE path=page_id");
            echo "Done<br>\n";
        }
    }

    function setupDefaultProtectionForCustomFields()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Setup default protection for custom fields...";
            foreach($this->getDi()->userTable->customFields()->getAll() as $field) {
                if (isset($field->from_config) && $field->from_config)
                    $this->getDi()->resourceAccessTable->setAccess(amstrtoint($field->name), Am_CustomField::ACCESS_TYPE, [
                        ResourceAccess::FN_FREE_WITHOUT_LOGIN => [
                            json_encode([
                                'start' => null,
                                'stop' => null,
                                'text' => ___('Free Access without log-in')
                            ])
                        ]
                    ]);
            }
            echo "Done<br>\n";
        }
    }

    function populateInvoicePublicId()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Populate Invoice Public Id (Denormalization)...";
            foreach ([
                '?_access',
                '?_invoice_item',
                '?_invoice_payment',
                '?_invoice_refund'
                     ] as $table) {

                $this->getDi()->db->query("UPDATE $table t SET invoice_public_id =
                    (SELECT public_id FROM ?_invoice i WHERE i.invoice_id=t.invoice_id)
                    WHERE t.invoice_id IS NOT NULL");
            }
            echo "Done<br>\n";
        }
    }

    function populateCouponCode()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Populate Coupon Code (Denormalization)...";
            $this->getDi()->db->query("UPDATE ?_invoice t SET coupon_code =
                    (SELECT code FROM ?_coupon c WHERE c.coupon_id=t.coupon_id)
                    WHERE t.coupon_id IS NOT NULL");
            echo "Done<br>\n";
        }
    }

    function convert0toNull()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            echo "Convert 0 to NULL...";
            $this->getDi()->db->query("UPDATE ?_access SET invoice_id=NULL
                    WHERE invoice_id=0");
            $this->getDi()->db->query("UPDATE ?_access SET invoice_payment_id=NULL
                    WHERE invoice_payment_id=0");
            $this->getDi()->db->query("UPDATE ?_access SET invoice_item_id=NULL
                    WHERE invoice_item_id=0");
            $this->getDi()->db->query("UPDATE ?_access SET transaction_id=NULL
                    WHERE transaction_id=''");
            echo "Done<br>\n";
        }
    }

    function fixLogoutRedirectSettings()
    {
        if (version_compare($this->db_version, '4.2.20') < 0)
        {
            if (!$this->getDi()->config->get('protect.php_include.redirect_logout') &&
                $this->getDi()->config->get('protect.php_include.redirect')) {
                Am_Config::saveValue('protect.php_include.redirect_logout', 'url');

                $this->getDi()->config->read();
            }
        }
    }

    function fixUserStatusTable()
    {
        if (version_compare($this->db_version, '4.3.3') < 0)
        {
            echo "Fix ?_user_status table...";
            $this->getDi()->db->query("DELETE FROM ?_user_status WHERE user_id NOT IN (SELECT user_id FROM ?_user)");
            echo "Done<br>\n";
        }
    }

    function populateAffAddedField()
    {
        if (version_compare($this->db_version, '4.3.3') < 0)
        {
            echo "Populate aff_added field...";
            $this->getDi()->db->query("UPDATE ?_user SET aff_added=added WHERE aff_id>0 AND aff_added IS NULL");
            echo "Done<br>\n";
        }
    }

    function convertFreeWithoutAccessFoldersToLinks()
    {
        if (version_compare($this->db_version, '4.3.4') < 0)
        {
            echo "Converst Free Without Access Folders to Links...";
            foreach ($this->getDi()->resourceAccessTable->findBy([
                'fn' => ResourceAccess::FN_FREE_WITHOUT_LOGIN,
                'resource_type' => ResourceAccess::FOLDER
            ]) as $rec) {

                try {
                    $folder = $this->getDi()->folderTable->load($rec->resource_id);
                    $link = $this->getDi()->linkRecord;
                    foreach (['title', 'desc', 'url', 'hide'] as $prop) {
                        $link->{$prop} = $folder->{$prop};
                    }
                    $link->save();
                    $link->setAccess([
                        ResourceAccess::FN_FREE => [
                            0 => [
                                'start' => null,
                                'stop' => null
                            ]
                        ]
                    ]);

                    $sort = $folder->getSortOrder();
                    $folder->delete();
                    $this->unprotectFolder($folder);
                    $link->setSortOrder($sort);
                } catch (Exception $e) {}
            }
            echo "Done<br>\n";
        }
    }

    function setDefaultProfileForm()
    {
        if (version_compare($this->db_version, '4.6.4') < 0)
        {
            echo "Set Default Profile Form...";
            if (!$this->getDi()->savedFormTable->getDefault(SavedForm::D_PROFILE)) {
                $id = $this->getDi()->db->selectCell('SELECT saved_form_id FROM ?_saved_form WHERE type=? LIMIT 1', 'profile');
                $this->getDi()->savedFormTable->setDefault(SavedForm::D_PROFILE, $id);
            }
            echo "Done<br>\n";
        }
    }

    public function hideSignupForms()
    {
        if (version_compare($this->db_version, '4.7.0') < 0)
        {
            echo "Hide Signup Forms from Menu...";
            $form = $this->getDi()->savedFormTable->getDefault(SavedForm::D_MEMBER);
            $this->getDi()->db->query('UPDATE ?_saved_form SET hide=1
                WHERE type=? AND saved_form_id<>?',
                    SavedForm::T_SIGNUP, $form->pk());
            echo "Done<br>\n";
        }
    }

    public function disableUserNotesPlugin()
    {
        if (version_compare($this->db_version, '4.7.3') < 0)
        {
            //echo "Disable User Notes plugin (This feature is incorporated to core)...";
            $plugins = $this->getDi()->config->get('plugins.misc');
            if ($plugins)
                foreach ($plugins as $k => $pl) {
                    if ($pl == 'user-note') {
                        unset($plugins[$k]);
                        Am_Config::saveValue('plugins.misc', $plugins);
                        break;
                    }
                }
            //echo "Done<br>\n";
        }
    }

    public function disableForcePasswordCachangePlugin()
    {
        if (version_compare($this->db_version, '4.7.3') < 0)
        {
            //echo "Disable Force Password Change plugin (This feature is incorporated to core)...";
            $plugins = $this->getDi()->config->get('plugins.misc');
            if ($plugins)
                foreach ($plugins as $k => $pl) {
                    if ($pl == 'force-password-change') {
                        Am_Config::saveValue('force_change_password', 1);
                        Am_Config::saveValue('force_change_password_period', $this->getDi()->config->get('misc.force-password-change.period'));
                        unset($plugins[$k]);
                        Am_Config::saveValue('plugins.misc', $plugins);
                        break;
                    }
            }
            //echo "Done<br>\n";
        }
    }

    public function enableUseCoupons()
    {
        if (version_compare($this->db_version, '4.7.3') < 0)
        {
            echo "Enable use of coupons in Shopping Cart...";
            Am_Config::saveValue('cart.use_coupons', 1);
            echo "Done<br>\n";
        }
    }

    public function pupulateRefundAmount()
    {
        if (version_compare($this->db_version, '4.7.3') < 0)
        {
            echo "Populate Refund Amount...";
            $this->getDi()->db->query("INSERT INTO ?_invoice_payment
                        (invoice_payment_id, refund_dattm, refund_amount)
                        (SELECT invoice_payment_id, MIN(dattm), SUM(amount)
                            FROM ?_invoice_refund
                            WHERE invoice_payment_id > 0
                            GROUP BY invoice_payment_id)
                        ON DUPLICATE KEY UPDATE
                                refund_dattm = VALUES(refund_dattm),
                                refund_amount = VALUES(refund_amount);");
            echo "Done<br>\n";
        }
    }

    public function enableAffKeywords()
    {
        if (version_compare($this->db_version, '4.7.3') < 0)
        {
            echo "Enable Keywords support in Affiliate Module...";
            Am_Config::saveValue('aff.keywords', 1);
            echo "Done<br>\n";
        }
    }

    public function enableRecurringForExpireEmailTemplates()
    {
        if (version_compare($this->db_version, '4.7.3') < 0)
        {
            echo "Enable Recurring Option for Expire Email Templates...";
            $this->getDi()->db->query("UPDATE ?_email_template SET recurring=1 WHERE name=?",
                EmailTemplate::EXPIRE);
            echo "Done<br>\n";
        }
    }

    function initFormSortOrder()
    {
        if (version_compare($this->db_version, '5.0.1') < 0)
        {
            echo "Init sort order for saved forms...";
            if (ob_get_level()) ob_end_flush();
            $this->getDi()->db->query("SET @i = 0");
            $this->getDi()->db->query("UPDATE ?_saved_form SET sort_order=(@i:=@i+1) ORDER BY `type`='signup' DESC");
            echo "Done<br>\n";
        }
    }

    function initProductUpgradeSortOrder()
    {
        if (version_compare($this->db_version, '6.3.22') < 0)
        {
            echo "Init sort order for product upgrades...";
            if (ob_get_level()) ob_end_flush();
            $this->getDi()->db->query("SET @i = 0");
            $this->getDi()->db->query("UPDATE ?_product_upgrade SET sort_order=(@i:=@i+1)");
            echo "Done<br>\n";
        }
    }

    function enableAllowCancel()
    {
        if (version_compare($this->db_version, '5.0.5') <= 0)
        {
            echo "Enable allow_cancel option...";
            if (ob_get_level()) ob_end_flush();
            Am_Config::saveValue('allow_cancel', 1);
            echo "Done<br>\n";
        }
    }

    function fixDateFormat()
    {
        if (version_compare($this->db_version, '5.0.7') <= 0)
        {
            echo "Fix date_format and time_format...";
            if (ob_get_level()) ob_end_flush();
            Am_Config::saveValue('date_format', null);
            Am_Config::saveValue('time_format', null);
            echo "Done<br>\n";
        }
    }

    function updateBrowseUsersField()
    {
        if (version_compare($this->db_version, '5.1.7') < 0)
        {
            echo "Update Browse Users fields...";
            $update = ['name', 'payments_count', 'payments_sum', 'products', 'ugroup', 'expire'];
            if (ob_get_level()) ob_end_flush();
            foreach($this->getDi()->adminTable->findBy() as $admin)
            {
                $pref = $admin->getPref('grid_setup_u');
                if (!empty($pref) && is_array($pref) && count($pref))
                {
                    foreach($pref as $k => $v)
                    {
                        if(in_array($v, $update))
                            $pref[$k] = '_' . $v;
                    }
                    $admin->setPref('grid_setup_u', $pref);
                }
            }
            echo "Done<br>\n";
        }
    }

    public function handlePdfConfig()
    {
        if (version_compare($this->db_version, '5.1.8') <= 0)
        {
            echo "Normalize PDF Invoice options...";
            if ($this->getDi()->config->get('send_pdf_invoice')) {
                Am_Config::saveValue('pdf_invoice_sent_user', 1);
                Am_Config::saveValue('pdf_invoice_sent_admin', 1);
                Am_Config::saveValue('pdf_invoice_link', 1);
            }
            echo "Done<br>\n";
        }
    }

    public function convertCCRecords()
    {
        if ((version_compare($this->db_version, '5.1.8') <= 0) && $this->getDi()->modules->isEnabled('cc'))
        {
            $this->error(sprintf("Default encryption method was changed. You must re-encrypt database. Please run <a href='%s'>this tool</a>\n<br/>",
                $this->getUrl('admin-convert', 'index', 'cc')));
        }
    }

    function moveCurrencyToBp()
    {
        echo "Move Currency to Billing Plans...";
        //idempotent operation
        $this->getDi()->db->query(<<<CUT
            UPDATE ?_billing_plan bp
                SET currency = (SELECT currency FROM ?_product p WHERE p.product_id = bp.product_id)
                WHERE currency IS NULL;
CUT
        );
        echo "Done<br>\n";
    }

    public function unprotectFolder(Folder $folder)
    {
        $htaccess_path = $folder->path . '/.htaccess';
        if (!is_dir($folder->path)) {
            $this->error(sprintf('Could not open folder [%s] to remove .htaccess from it. Do it manually', $folder->path));
            return;
        }
        $content = file_get_contents($htaccess_path);
        if (strlen($content) && !preg_match('/^\s*\#+\sAMEMBER START.+AMEMBER FINISH\s#+\s*/s', $content)) {
            $this->error(sprintf('File [%s] contains not only aMember code - remove it manually to unprotect folder', $htaccess_path));
            return;
        }
        if (!unlink($folder->path . '/.htaccess'))
            $this->error(sprintf('File [%s] cannot be deleted - remove it manually to unprotect folder', $htaccess_path));
    }

    public function convertCustomVats()
    {
        $nodata = 'a:29:{s:2:"AT";s:0:"";s:2:"BE";s:0:"";s:2:"BG";s:0:"";s:2:"CY";s:0:"";s:2:"HR";s:0:"";s:2:"CZ";s:0:"";s:2:"DE";s:0:"";s:2:"DK";s:0:"";s:2:"EE";s:0:"";s:2:"GR";s:0:"";s:2:"ES";s:0:"";s:2:"FI";s:0:"";s:2:"FR";s:0:"";s:2:"GB";s:0:"";s:2:"HU";s:0:"";s:2:"IE";s:0:"";s:2:"IM";s:0:"";s:2:"IT";s:0:"";s:2:"LT";s:0:"";s:2:"LU";s:0:"";s:2:"LV";s:0:"";s:2:"MT";s:0:"";s:2:"NL";s:0:"";s:2:"PL";s:0:"";s:2:"PT";s:0:"";s:2:"RO";s:0:"";s:2:"SE";s:0:"";s:2:"SK";s:0:"";s:2:"SI";s:0:"";}';
        $i = 1;

        if ($records = $this->getDi()->db->select("SELECT * FROM ?_data WHERE `key` = 'vat_eu_rate'")) {

            $groups = $this->getConfig('tax.vat2015.tax_groups', []);
            $index = [];
            foreach ($groups as $id => $title) {
               $index[md5(serialize($this->getConfig("tax.vat2015.$id")))] = $id;
            }

            foreach ($records as $r) {
                if ($r['blob'] == $nodata) continue;

                $k = md5($r['blob']);
                if (isset($index[$k])) {
                    $tax_group = $index[$k];
                } else {
                    $d = unserialize($r['blob']);
                    $groups[$_ = uniqid()] = 'Reduced Tax Group '. $i++;
                    $index[md5($r['blob'])] = $_;
                    Am_Config::saveValue("tax.vat2015.$_", $d);
                    $tax_group = $_;
                }
                if ($p = $this->getDi()->productTable->load($r['id'], false)) {
                    $p->tax_rate_group = $tax_group;
                    $p->save();
                }
            }
            foreach ($groups as $id => $title) {
                Am_Config::saveValue('tax.vat2015.tax_groups.' . $id, $title);
            }
            $this->getDi()->db->query("DELETE FROM ?_data WHERE `key` = 'vat_eu_rate'");
        }
    }

    function setupUserMenu()
    {
        if ($this->getDi()->config->get('user_menu')) return;

        echo "Setup User Menu...";
        $items = [];
        $items[] = ['id' => 'dashboard', 'name' => 'Dashboard'];

        //Signup Forms
        $forms = $this->getDi()->savedFormTable->findBy([
            'type' => SavedForm::T_SIGNUP,
            'hide' => 0
        ], null, null, 'sort_order');

        if (count($forms) == 1) {
           [$f] = $forms;
           $items[] = ['id' => 'signup-form', 'name' => $f->title, 'config' => ['id' => $f->pk()]];
        } elseif (count($forms) > 1) {
            $subitems = [];
            foreach ($forms as $f) {
                $subitems[] = ['id' => 'signup-form', 'name' => $f->title, 'config' => ['id' => $f->pk()]];
            }
            $items[] = [
                'id' => 'container',
                'name' => 'Add/Renew Subscription',
                'config' => ['label' => 'Add/Renew Subscription'],
                'items' => $subitems
            ];
        }

        //Profile Forms
        $forms = $this->getDi()->savedFormTable->findBy([
            'type' => SavedForm::T_PROFILE,
            'hide' => 0
        ], null, null, 'sort_order');

        if (count($forms) == 1) {
           [$f] = $forms;
           $items[] = ['id' => 'profile-form', 'name' => $f->title, 'config' => ['id' => $f->pk()]];
        } elseif (count($forms) > 1) {
            $subitems = [];
            foreach ($forms as $f) {
                $subitems[] = ['id' => 'profile-form', 'name' => $f->title, 'config' => ['id' => $f->pk()]];
            }
            $items[] = [
                'id' => 'container',
                'name' => 'Profile',
                'config' => ['label' => 'Profile'],
                'items' => $subitems
            ];
        }

        $items[] = ['id' => 'resource-categories', 'name' => 'Resource Categories Menu'];

        //Pages
        foreach ($this->getDi()->pageTable->findByOnmenu(1) as $page) {
            $items[] = ['id' => 'page', 'name' => $page->title, 'config' => ['id' => $page->pk()]];
        }
        //Links
        foreach ($this->getDi()->linkTable->findByOnmenu(1) as $link) {
            $items[] = ['id' => 'link', 'name' => $link->title, 'config' => ['id' => $link->pk()]];
        }
        Am_Config::saveValue('user_menu', json_encode($items));
        echo "Done<br>\n";
    }

    function convertAgreements()
    {
        if ($this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_agreement")) {
            return;
        }

        $agreements = [];
        foreach ($this->getDi()->savedFormTable->selectObjects("SELECT * FROM ?_saved_form") as $form)
        {
            $update = false;
            $fields = $form->getFields();
            foreach ($fields as $k => $row)
            {
                if ($row['id'] == 'agreement')
                {
                    $text = @$row['config']['text'];

                    $hash = $this->createAgreementHash($text);

                    if (empty($agreements[$hash])) {

                        $agreements[$hash] = $type = sprintf('agreement-%s-%s', $form->type, $form->code?:'default');

                        $text = !empty($row['config']['isHtml'])&& $row['config']['isHtml']? $text : sprintf("<pre>%s</pre>", $text);

                        $this->getDi()->db->query(
                            "INSERT INTO ?_agreement "
                            . "(type, title, body, is_current) "
                            . "VALUES"
                            . "(?,?,?,?)",
                            $type, ___('Terms & Conditions'), $text, 1);

                    } else {
                        $type = $agreements[$hash];
                    }
                    $fields[$k]['config']['agreement_type']  = $type;
                    $update = true;

                }
            }
            if ($update)
            {
                $form->setFields($fields);
                $form->update();
            }

        }

        if (empty($agreements))
        {
            $this->getDi()->db->query(
                "INSERT INTO ?_agreement "
                . "(type, title, body, is_current) "
                . "VALUES"
                . "(?,?,?,?)",
                'agreement-default', ___('Terms & Conditions'), "", 1);
        }
    }

    function recordUserConsent()
    {
        if (version_compare($this->db_version, '5.4.3') <= 0) {
            $this->getDi()->db->query("INSERT INTO ?_user_consent "
                . "(user_id, `type`, dattm, remote_addr, source) "
                . "SELECT user_id, 'imported', added, remote_addr, 'Account Registration' FROM ?_user WHERE i_agree>0");

            if($this->getDi()->db->selectCell("SELECT id FROM ?_data WHERE `table`='invoice' AND `key`='agreement_accepted' LIMIT 1")) {
                $this->getDi()->db->query("INSERT INTO ?_user_consent "
                    . "(user_id, `type`, dattm, remote_addr, source) "
                        . "SELECT i.user_id, 'imported', i.tm_added, dip.`value` AS agreement_accepted_ip, "
                        . "CONCAT('Invoice #', i.invoice_id, '/', i.public_id) "
                        . "FROM ?_invoice i "
                        . "LEFT JOIN ?_data da "
                        . "ON da.`table`='invoice' AND da.`key`='agreement_accepted' AND da.`id` = i.invoice_id "
                        . "LEFT JOIN ?_data dip "
                        . "ON dip.`table`='invoice' AND dip.`key`='agreement_accepted_ip' AND dip.`id` = i.invoice_id "
                        . "LEFT JOIN ?_user u "
                        . "ON u.user_id = i.invoice_id "
                        . "HAVING agreement_accepted_ip IS NOT NULL");
            }
        }
    }

    function fixStandardNewsletters()
    {
        if (
            $this->getDi()->modules->isEnabled('newsletter')
            && !$this->getDi()->plugins_newsletter->isEnabled('standard')
        ) {
            $plugins = $this->getDi()->config->get('plugins.newsletter');
            $plugins[] = 'standard';
            Am_Config::saveValue('plugins.newsletter', $plugins);
        }
    }

    function populateSourceCurrency()
    {
        $this->getDi()->db->query("UPDATE ?_currency_exchange SET base=? WHERE base IS NULL;", Am_Currency::getDefault());
    }

    function populateInvoicePaymentIsFirst()
    {
        if (version_compare($this->db_version, '6.3.34') < 0) {
            $this->getDi()->db->query(<<<CUT
                UPDATE ?_invoice_payment p
                    LEFT JOIN ?_invoice i USING (invoice_id)
                SET p.is_first = IF(DATE(p.dattm) = DATE(i.tm_started), 1, 0)
CUT
            );
            $this->getDi()->db->query(<<<CUT
                UPDATE ?_invoice_payment p
                    LEFT JOIN ?_invoice i USING (invoice_id)
                SET p.is_first = 1 WHERE i.rebill_times = 0;
CUT
            );
            echo "Done<br>\n";
        }
    }

    function storeBcryptPasswordInSavedPassTable()
    {
        print "Create placeholder for wordpress bcrypt passwords in SavedPassTable...";
        if (version_compare($this->db_version, '6.3.35') < 0) {
            $time = $this->getDi()->time;
            $this->getDi()->db->query(<<<CUT
                INSERT INTO  ?_saved_pass  (user_id, format, pass, salt, `time`)
                SELECT user_id, 'password_wordpress_bcrypt', pass, null, ? FROM ?_user
                ON DUPLICATE KEY UPDATE ?_saved_pass.pass=?_user.pass
CUT
            , $time);
            echo "Done<br>\n";
        }
    }

    function createAgreementHash($text)
    {
        $text = preg_replace('/[^\da-z]/i', '', $text);
        return md5(strtoupper($text));
    }

    function error($msg)
    {
        echo sprintf('<span style="color:#F44336;">%s</span><br />', $msg);
    }

    protected function setLevelOnErrorLogTable()
    {
        $this->getDi()->db->query("UPDATE ?_error_log SET level=400 WHERE level IS NULL");
        $this->getDi()->db->query("UPDATE ?_error_log SET channel='amember-core' WHERE channel IS NULL");
    }

    function fillAddressInfoTable()
    {
        if (version_compare($this->db_version, '6.3.5', '<')) {
            print "Fill address info table...";
            $this->getDi()->db->query("
                    INSERT IGNORE INTO ?_address (user_id, type, name_f, name_l, street, street2, city, state, zip, country, added, updated, hash)
                                SELECT user_id, 'tax', name_f, name_l, street, street2, city, state, zip, country, now(), now(),
                                       MD5(
                                           UPPER(
                                               CONCAT(
                                                   IFNULL(name_f,''),
                                                   IFNULL(name_l,''),
                                                   IFNULL(street,''),
                                                   IFNULL(street2,''),
                                                   IFNULL(city,''),
                                                   IFNULL(state,''),
                                                   IFNULL(zip,''),
                                                   IFNULL(country,'')
                                               )
                                           )
                                       ) FROM ?_user");
            print "Done<br/>\n";
        }
    }

    function setTaxPaymentRefundTaxRate()
    {
        if (version_compare($this->db_version, '6.3.5', '<')) {
            print "Update tax rate and address info in invoice payment and refund tables...";
            // Set tax rate and address in payment record;
            $q = $this->getDi()->db->query(""
                        . "UPDATE ?_invoice_payment ip "
                    . "LEFT  JOIN ?_invoice AS i ON ip.invoice_id = i.invoice_id "
                    . "LEFT  JOIN  ?_address AS a ON a.user_id = ip.user_id "
                    . "SET ip.tax_rate = IFNULL(i.tax_rate, 0), ip.tax_address_id = a.address_id "
                    . "WHERE ip.tax_rate IS NULL"
                    . "");
            // Update Refund records;
            $q = $this->getDi()->db->query(""
                        . "UPDATE ?_invoice_refund ir "
                    . "LEFT JOIN ?_invoice i ON ir.invoice_id = i.invoice_id "
                    . "LEFT JOIN ?_address a ON ir.user_id = a.user_id "
                    . "SET ir.tax_rate = IFNULL(i.tax_rate, 0), ir.tax_address_id = a.address_id,  "
                    . "ir.tax = ir.amount - (ir.amount / (1 + (IFNULL(i.tax_rate, 0)/100))) "
                    . "WHERE ir.tax_rate IS NULL");
            echo "Done<br/>\n";
        }
    }
    function setCountryPhoneCodes()
    {
        if (version_compare($this->db_version, '6.3.15', '<')) {
            print "Set mobile phone area codes...";
            Am_Di::getInstance()->db->query("INSERT INTO ?_country (country_id, phone_code) VALUES
                ('1', '1'),('2', '1'),('3', '93'),('4', '355'),('5', '213'),('6', '1-684'),('7', '376'),('8', '244'),('9', '1-264'),
                ('10', '672'),('11', '1-268'),('12', '54'),('13', '374'),('14', '297'),('15', '61'),('16', '43'),('17', '994'),
                ('18', '1-242'),('19', '973'),('20', '880'),('21', '1-246'),('22', '375'),('23', '32'),('24', '501'),('25', '229'),
                ('26', '1-441'),('27', '975'),('28', '591'),('29', '387'),('30', '267'),('32', '55'),('33', '246'),('34', '673'),
                ('35', '359'),('36', '226'),('37', '257'),('38', '855'),('39', '237'),('40', '238'),('41', '1-345'),('42', '236'),('43', '235'),
                ('44', '56'),('45', '86'),('46', '61'),('47', '61'),('48', '57'),('49', '269'),('50', '242'),('51', '682'),('52', '506'),('53', '225'),
                ('54', '385'),('55', '53'),('56', '357'),('57', '420'),('58', '243'),('59', '45'),('60', '253'),('61', '1-767'),
                ('62', '1-809,1-829,1-849'),('63', '670'),('64', '593'),('65', '20'),('66', '503'),('67', '240'),('68', '291'),('69', '372'),
                ('70', '251'),('71', '500'),('72', '298'),('73', '679'),('74', '358'),('75', '33'),('76', '594'),('77', '689'),
                ('78', '262,508,590,594,596'),('79', '241'),('80', '220'),('81', '995'),('82', '49'),('83', '233'),('84', '350'),('85', '30'),
                ('86', '299'),('87', '1-473'),('88', '590'),('89', '1-671'),('90', '502'),('91', '224'),('92', '245'),('93', '592'),('94', '509'),
                ('96', '504'),('97', '852'),('98', '36'),('99', '354'),('100', '91'),('101', '62'),('102', '98'),('103', '964'),
                ('104', '353'),('105', '972'),('106', '39'),('107', '1-876'),('108', '81'),('109', '962'),('110', '7'),('111', '254'),('112', '686'),
                ('113', '82'),('114', '965'),('115', '996'),('116', '856'),('117', '371'),('118', '961'),('119', '266'),('120', '231'),('121', '218'),
                ('122', '423'),('123', '370'),('124', '352'),('125', '853'),('126', '389'),('127', '261'),('128', '265'),('129', '60'),('130', '960'),
                ('131', '223'),('132', '356'),('133', '692'),('134', '596'),('135', '222'),('136', '230'),('137', '262'),('138', '52'),('139', '691'),
                ('140', '373'),('141', '377'),('142', '976'),('143', '1-664'),('144', '212'),('145', '258'),('146', '95'),('147', '264'),('148', '674'),
                ('149', '977'),('150', '31'),('151', '599'),('152', '687'),('153', '64'),('154', '505'),('155', '227'),('156', '234'),('157', '683'),
                ('158', '672'),('159', '850'),('160', '1-670'),('161', '47'),('162', '968'),('163', '92'),('164', '680'),('165', '507'),('166', '675'),
                ('167', '595'),('168', '51'),('169', '63'),('170', '64'),('171', '48'),('172', '351'),('173', '1-787,1-939'),('174', '974'),('175', '262'),
                ('176', '40'),('177', '7'),('178', '250'),('179', '685'),('180', '378'),('181', '239'),('182', '966'),('183', '221'),('184', '382'),
                ('185', '248'),('186', '232'),('187', '65'),('188', '421'),('189', '386'),('190', '677'),('191', '252'),('192', '27'),('193', '500'),
                ('194', '34'),('195', '94'),('196', '290'),('197', '1-869'),('198', '1-758'),('199', '508'),('200', '1-784'),('201', '249'),('202', '597'),
                ('203', '47'),('204', '268'),('205', '46'),('206', '41'),('207', '963'),('208', '886'),('209', '992'),('210', '255'),('211', '66'),
                ('212', '228'),('213', '690'),('214', '676'),('215', '1-868'),('216', '216'),('217', '90'),('218', '993'),('219', '1-649'),('220', '688'),
                ('221', '256'),('222', '380'),('223', '971'),('224', '44'),('226', '598'),('227', '998'),('228', '678'),('229', '379'),
                ('230', '58'),('231', '84'),('232', '1-284'),('233', '1-340'),('234', '681'),('235', '967'),('236', '260'),('237', '263'),('238', '358'),
                ('239', '599'),('240', '599'),('241', '44-1481'),('242', '44-1624'),('243', '44-1534'),('245', '970'),('246', '590'),('247', '590'),
                ('248', '381'),('249', '1-721'),('250', '211'),('251', '212')
                                               ON DUPLICATE KEY UPDATE phone_code=VALUES(phone_code)
            ");
            echo "Done<br/>\n";
        }
    }

    public function accessCacheDaysToDate()
    {
            $this->getDi()->db->query(<<<CUT
UPDATE ?_access_cache
SET days_active=DATE_SUB(CURDATE(), INTERVAL days DAY )
WHERE days_active IS NULL or days_active = '0000-00-00' AND days IS NOT NULL AND days>0 AND status='active'
CUT
            );
    }

    public function doUpgradeDb()
    {
        $db = Am_Di::getInstance()->getParameter('db');
        $lock_id = sha1("amember-upgrade-db-{$db['mysql']['db']}");

        if (!Am_Di::getInstance()->db->selectCell("SELECT GET_LOCK(?, 0)", $lock_id)) {
            throw new Am_Exception_InternalError("Upgrade DB Process is already running");
        }

        /* ******************************************************************************* *
         *                  M A I N
         */
        if (!defined('HP_ROOT_DIR')) {
            $this->fixNotUniqueRecordInRebillLog();
            $this->fixNotUniquePathInPages();
        }

        $this->getDi()->app->dbSync(true);

        if (!defined('HP_ROOT_DIR')) {
            $this->accessCacheDaysToDate();
            $this->checkInvoiceItemTotals();
            $this->convertTax();
            $this->convertAutoresponderPrefix();
            $this->enableSkipIndexPage();
            $this->manuallyApproveInvoices();
            $this->addCountryCodes();
            $this->fillResourceAccessSort();
            $this->fixCryptSavedPass();
            $this->updateStateInfo();
            $this->fixCustomFieldSortTableName();
            $this->normalizeProductSortOrder();
            $this->fixPagePath();
            $this->setupDefaultProtectionForCustomFields();
            $this->populateInvoicePublicId();
            $this->populateCouponCode();
            $this->convert0toNull();
            $this->fixLogoutRedirectSettings();
            $this->fixUserStatusTable();
            $this->populateAffAddedField();
            $this->convertFreeWithoutAccessFoldersToLinks();
            $this->setDefaultProfileForm();
            $this->hideSignupForms();
            $this->disableUserNotesPlugin();
            $this->disableForcePasswordCachangePlugin();
            $this->enableUseCoupons();
            $this->pupulateRefundAmount();
            $this->enableAffKeywords();
            $this->enableRecurringForExpireEmailTemplates();
            $this->initFormSortOrder();
            $this->enableAllowCancel();
            $this->fixDateFormat();
            $this->updateBrowseUsersField();
            $this->convertCustomVats();
            $this->handlePdfConfig();
            $this->moveCurrencyToBp();
            $this->convertCCRecords();
            $this->setupUserMenu();
            $this->convertAgreements();
            $this->recordUserConsent();
            $this->convertStandardNewsletters();
            $this->fixStandardNewsletters();
            $this->normalizeSavedSearchSortOrder();
            $this->populateSourceCurrency();
            $this->fillAddressInfoTable();
            $this->setTaxPaymentRefundTaxRate();
            $this->setCountryPhoneCodes();
            $this->initProductUpgradeSortOrder();
            $this->populateInvoicePaymentIsFirst();
            $this->storeBcryptPasswordInSavedPassTable();
        }

        if (version_compare($this->db_version, '6.0.0'))
        {
            $this->setLevelOnErrorLogTable();
        }

        $this->getDi()->hook->call(new Am_Event(Am_Event::DB_UPGRADE, ['version' => $this->db_version]));
        $this->getDi()->store->set('db_version', AM_VERSION);

        Am_Di::getInstance()->db->query("SELECT RELEASE_LOCK(?)", $lock_id);
    }
}