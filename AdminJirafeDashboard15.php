<?php

include_once('jirafe.php');

class AdminJirafeDashboard extends AdminTab
{
    public function __construct()
    {
        parent::__construct();
    }

    public function display()
    {
        global $cookie;

        $jirafe = new Jirafe();
        $ps = $jirafe->getPrestashopClient();
        $jirafeUser = $ps->getUser($cookie->email);

        $apiUrl = JIRAFE_API_URL;
        $token = $jirafeUser['token'];
        $appId = $ps->get('app_id');
        $locale = $ps->getLanguage();
        $title = $this->l('Dashboard');
        $errMsg = $this->l("We're unable to connect with the Jirafe service for the moment. Please wait a few minutes and refresh this page later.");
        echo <<<EOF
<div>
    <h1>{$title}</h1>
    <hr style="background-color: #812143;color: #812143;" />
    <br />
</div>

<!-- Jirafe Dashboard Begin -->
<div id="jirafe"></div>
<script type="text/javascript">
  jirafe.jQuery('#jirafe').jirafe({
     api_url:    '{$apiUrl}',
     api_token:  '{$token}',
     app_id:     '{$appId}',
     locale:     '{$locale}',
     version:    'presta-v0.1.0'
  });
setTimeout(function() {
    if ($('mod-jirafe') == undefined){
        $('messages').insert ("<ul class=\"messages\"><li class=\"error-msg\">{$errMsg}</li></ul>");
    }
}, 2000);
</script>
<!-- Jirafe Dashboard End -->
EOF;

    }
}
