<?php

namespace UWMadison\BetterSearch;

use ExternalModules\AbstractExternalModule;

class BetterSearch extends AbstractExternalModule
{
    public function redcap_every_page_top($project_id)
    {
?>
        <script>
            $(document).ready(() => $(`a[href='/redcap_v${redcap_version}/DataEntry/record_home.php?pid=${pid}']`).prop('href', '<?= $this->getUrl('index.php') ?>'))
        </script>
<?php
    }

    public function redcap_module_ajax($action, $payload)
    {
        if ($action == "search") {
            //$this->search($payload);
        }
    }
}
