<?php

namespace UWMadison\BetterSearch;

use ExternalModules\AbstractExternalModule;
use Records;

class BetterSearch extends AbstractExternalModule
{
    public function redcap_every_page_top($project_id)
    {
        // Bail if its not the Add/Edit records page
        if (!$this->isPage('DataEntry/record_home.php') || !empty($_GET["id"]))
            return;

        // Load settings and inject the JS
        $settings = $this->getProjectSettings();
        $settings = [
            "hide_list" => $settings['hide_list'] || $settings['hide_count'] >= Records::getRecordCount($project_id),
            "hide_search" => $settings['hide_search']

        ];
        if ($settings["hide_list"]) {
            // TODO stop the page from wasting load time
        }
        $this->initializeJavascriptModuleObject();
        echo "<script>{$this->getJavascriptModuleObjectName()}.settings = " . json_encode($settings) . ";</script>";
        echo "<script src={$this->getUrl('bettersearch.js')}></script>";
    }

    public function redcap_module_ajax($action, $payload)
    {
        if ($action == "search") {
            //$this->search($payload);
        }
    }
}
