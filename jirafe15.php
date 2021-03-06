<?php

class Jirafe extends Jirafe_Base
{
    // The Prestashop Client communicates with the Prestashop ecommerce platform
    private $prestashopClient;

    private static $syncUpdatedObject;

    public function getPrestashopClient()
    {
        if (null === $this->prestashopClient) {
            // Prestashop Ecommerce Client
            $this->prestashopClient = new Jirafe_Platform_Prestashop15();

            $this->prestashopClient->trackerUrl = JIRAFE_TRACKER_URL;
        }

        return $this->prestashopClient;
    }

    public function install()
    {
        $ps = $this->getPrestashopClient();
        $jf = $this->getJirafeAdminClient();

        // Get the application information needed by Jirafe
        $app = $ps->getApplication();

        // Check if there is a token (probably not since we are installing) and if not, get one from Jirafe
        if (empty($app['token'])) {
            try {
                $app = $jf->applications()->create($app['name'], $app['url'], 'prestashop', _PS_VERSION_, JIRAFE_MODULE_VERSION);
            } catch (Exception $e) {
                $this->_errors[] = $this->l('The Jirafe Web Service is unreachable. Please try again when the connection is restored.');
                return false;
            }

            // Set the application information in Prestashop
            $ps->setApplication($app);
            // Set the token in the Jirafe client for later
            $jf->setToken($app['token']);
        }

        // Sync for the first time
        try {
            $results = $jf->applications($app['app_id'])->resources()->sync($ps->getSites(), $ps->getUsers(), array(
                'platform_type' => 'prestashop',
                'platform_version' => _PS_VERSION_,
                'plugin_version' => JIRAFE_MODULE_VERSION,
                'opt_in' => false // @TODO, enable onboarding when ready
            ));
        } catch (Exception $e) {
            $this->_errors[] = $this->l('The Jirafe Web Service is unreachable. Please try again when the connection is restored.');
            return false;
        }

        // Save information back in Prestashop
        $ps->setUsers($results['users']);
        $ps->setSites($results['sites']);

        // Add hooks for stats and tags
        return (
            parent::install()  // Get Jirafe ID, perform initial sync
            && $this->registerHook('backOfficeTop')  // Check to see if we should sync
            && $this->registerHook('actionObjectAddAfter')  // Check to see if we should sync
            && $this->registerHook('actionObjectUpdateBefore')  // Check to see if we should sync
            && $this->registerHook('actionObjectUpdateAfter')  // Check to see if we should sync
            && $this->registerHook('actionObjectDeleteAfter')  // Check to see if we should sync
            && $this->registerHook('backOfficeHeader')  // Add dashboard script
            && $this->registerHook('header')         // Install Jirafe tags
            && $this->registerHook('cart')           // When adding items to the cart
            && $this->registerHook('orderConfirmation')    // Goal tracking
            && $ps->set('logo', 'http://jirafe.com/bundles/jirafewebsite/images/logo.png')
            && self::installAdminDashboard()
        );
    }

    private function installAdminDashboard()
    {
        $tab = new Tab();
        $tab->name = 'Jirafe Analytics';
        $tab->class_name = 'AdminJirafeDashboard';
        $tab->module = 'jirafe';
        $tab->id_parent = Tab::getIdFromClassName('AdminParentStats');
        return $tab->add();
    }

    public function uninstall()
    {
        $ps = $this->getPrestashopClient();

        // Remove values in the DB
        return (
            parent::uninstall()
            && $ps->delete('app_id')
            && $ps->delete('sites')
            && $ps->delete('users')
            && $ps->delete('sync')
            && $ps->delete('token')
            && $ps->delete('logo')
            && $this->unregisterHook('backOfficeTop')  // Check to see if we should sync
            && $this->unregisterHook('actionObjectAddAfter')  // Check to see if we should sync
            && $this->unregisterHook('actionObjectUpdateBefore')  // Check to see if we should sync
            && $this->unregisterHook('actionObjectUpdateAfter')  // Check to see if we should sync
            && $this->unregisterHook('actionObjectDeleteAfter')  // Check to see if we should sync
            && $this->unregisterHook('backOfficeHeader')  // Add dashboard script
            && $this->unregisterHook('header')         // Install Jirafe tags
            && $this->unregisterHook('cart')           // When adding items to the cart
            && $this->unregisterHook('orderConfirmation')    // Goal tracking
            && $this->uninstallAdminDashboard()
        );
    }

    private function uninstallAdminDashboard()
    {
        $tab = new Tab(Tab::getIdFromClassName('AdminJirafeDashboard'));

        return (
            parent::uninstall()
            && $tab->delete()
        );
    }

    /**
    * Check to see if someone saved something we need to update Jirafe about
    *
    * @param array $params Information from the user, like cookie, etc
    */
    public function hookBackOfficeTop($params)
    {
        if ($this->getPrestashopClient()->isDataChanged($params)) {
            $this->_sync();
        }
    }

    /**
     * Check to see if someone created something we need to update Jirafe about
     */
    public function hookActionObjectAddAfter($params)
    {
        $object = $params['object'];

        if ($object instanceof Employee
            || $object instanceof Shop
            || $object instanceof ShopUrl) {
            $this->_sync();
        }
    }

    public function hookActionObjectUpdateBefore($params)
    {
        // active a flag if an object is changed before prestashop really saves
        // the object
        self::$syncUpdatedObject = $this->getPrestashopClient()->isDataChanged($params);
    }

    public function hookActionObjectUpdateAfter($params)
    {
        // sync if an object was detected as changed
        if (self::$syncUpdatedObject) {
            $this->_sync();
        }
    }

    /**
     * Check to see if someone deleted something we need to update Jirafe about
     */
    public function hookActionObjectDeleteAfter($params)
    {
        $object = $params['object'];

        if ($object instanceof Employee
            || $object instanceof Shop
            || $object instanceof ShopUrl) {
            $this->_sync();
        }
    }

    public function hookBackOfficeHeader($params)
    {
        $prefix = JIRAFE_ASSETS_URL_PREFIX;

        return <<<EOT
            <link type="text/css" rel="stylesheet" href="{$prefix}/css/prestashop_ui.css" media="all" />
            <script type="text/javascript" src="{$prefix}/js/prestashop_ui.js"></script>
EOT;
    }

    /**
     * Hook which allows us to insert our analytics tag into the Front end
     *
     * @param array $params variables from the front end
     * @return string the additional HTML that we are generating in the header
     */
    public function hookHeader($params)
    {
        $ps = $this->getPrestashopClient();
        return $ps->getTag();
    }

    private function _sync()
    {
        $ps = $this->getPrestashopClient();
        $jf = $this->getJirafeAdminClient();

        // Sync the changes
        $app = $ps->getApplication();
        try {
            $results = $jf->applications($ps->get('app_id'))->resources()->sync($ps->getSites(), $ps->getUsers(), array(
                'platform_type' => 'prestashop',
                'platform_version' => _PS_VERSION_,
                'plugin_version' => JIRAFE_MODULE_VERSION,
                'opt_in' => false // @TODO, enable onboarding when ready
            ));
        } catch (Exception $e) {
            $this->_errors[] = $this->displayError($this->l('The Jirafe Web Service is unreachable. Please try again when the connection is restored.'));
            return false;
        }

        // Save information back in Prestashop
        $ps->setUsers($results['users']);
        $ps->setSites($results['sites']);

        return true;
    }
}
