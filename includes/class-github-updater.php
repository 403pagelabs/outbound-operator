<?php

class Outbound_Operator_GitHub_Updater {
    private $slug;
    private $pluginData;
    private $username;
    private $repo;
    private $pluginFile;
    private $githubAPIResult;
    private $accessToken;

    function __construct($pluginFile) {
        add_filter("pre_set_site_transient_update_plugins", array($this, "setTransient"));
        add_filter("plugins_api", array($this, "setPluginInfo"), 10, 3);
        add_filter("upgrader_post_install", array($this, "postInstall"), 10, 3);

        $this->pluginFile = $pluginFile;
        $this->username = "403pagelabs";
        $this->repo = "outbound-operator";
        $this->slug = plugin_basename($this->pluginFile);
    }

    private function getRepoReleaseInfo() {
        if (!empty($this->githubAPIResult)) {
            return $this->githubAPIResult;
        }

        $url = "https://api.github.com/repos/{$this->username}/{$this->repo}/releases/latest";
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json'
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return false;
        }

        $this->githubAPIResult = json_decode(wp_remote_retrieve_body($response));

        return $this->githubAPIResult;
    }

    public function setTransient($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $plugin_data = get_plugin_data($this->pluginFile);
        $current_version = $plugin_data['Version'];

        $release_info = $this->getRepoReleaseInfo();
        if ($release_info === false) {
            return $transient;
        }

        // Remove 'v' prefix if it exists
        $latest_version = ltrim($release_info->tag_name, 'v');

        if (version_compare($latest_version, $current_version, '>')) {
            $plugin = array(
                'url' => $plugin_data["PluginURI"],
                'slug' => $this->slug,
                'package' => $release_info->zipball_url,
                'new_version' => $latest_version
            );
            $transient->response[$this->slug] = (object)$plugin;
        }

        return $transient;
    }

    public function setPluginInfo($false, $action, $response) {
        if (empty($response->slug) || $response->slug !== $this->slug) {
            return $false;
        }

        $release_info = $this->getRepoReleaseInfo();
        if ($release_info === false) {
            return $false;
        }

        $plugin_data = get_plugin_data($this->pluginFile);

        $response->name = $plugin_data["Name"];
        $response->slug = $this->slug;
        $response->version = ltrim($release_info->tag_name, 'v');
        $response->author = $plugin_data["AuthorName"];
        $response->homepage = $plugin_data["PluginURI"];
        $response->requires = $plugin_data["RequiresWP"];
        $response->tested = $plugin_data["TestedUpTo"];
        $response->downloaded = 0;
        $response->last_updated = $release_info->published_at;
        $response->sections = array(
            'description' => $plugin_data["Description"],
            'changelog' => nl2br($release_info->body)
        );
        $response->download_link = $release_info->zipball_url;

        return $response;
    }

    public function postInstall($true, $hook_extra, $result) {
        global $wp_filesystem; 
        
        $pluginFolder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname($this->slug);
        
        $wp_filesystem->move($result['destination'], $pluginFolder);
        $result['destination'] = $pluginFolder;

        return $result;
    }
}