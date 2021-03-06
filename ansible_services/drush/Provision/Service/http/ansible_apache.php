<?php

require_once(__DIR__ . '/../../../../../vendor/autoload.php');

use Asm\Ansible\Ansible;


/**
 * @file
 * Provides the Ansible Apache service driver.
 */

class Provision_Service_http_ansible_apache extends Provision_Service_http_apache_ssl {

    /**
     * @var Ansible;
     */
    private $ansible;
    private $ansible_user = '';
    private $inventory;
    private $playbook;
    private $ansible_config_file;
    private $ansible_config;

    /**
     * This is kicked off by a drush hook, since there is no good method to override in Provision_Service_http or Provision_Service.
     *
     * @TODO: Make this a server service, separate.
     *
     * @return mixed
     */
    public function runPlaybook() {

        drush_log('Provision_Service_http_ansible_apache::init_server', 'status');

        // If "inventory" exists in ansible configuration, use that instead of the default '/etc/ansible/hosts'
        if ($this->getAnsibleInventory()) {
            drush_log('Ansible Config Loaded from ' . $this->ansible_config_file, 'status');
            $this->inventory = $this->getAnsibleInventory();
        }

        // Last check: does the inventory file exist?
        if (!file_exists($this->inventory)) {
            return drush_set_error('DRUSH_ERROR', 'No file was found at the path specified by the "inventory-file" option: ' . $this->inventory);
        }
        drush_log('Ansible Inventory Loaded from ' . $this->inventory, 'status');


        // Look for playbook
        $this->playbook = drush_get_option('playbook', realpath(__DIR__ . '/../../../../../playbook.yml'));

        // Last check: does the playbook file exist?
        if (!file_exists($this->playbook)) {
            return drush_set_error('DRUSH_ERROR', 'No file was found at the path specified by the "playbook" option: ' . $this->playbook);
        }
        drush_log('Ansible Playbook Loaded from ' . $this->playbook, 'status');

        // Prepare the Ansible object.
        $this->ansible = new Ansible(
            getcwd(),
            '/usr/bin/ansible-playbook',
            '/usr/bin/ansible-galaxy'
        );

        $ansible = $this->ansible->playbook();

        $ansible->play($this->playbook);
        $command = "ansible-playbook {$this->playbook} ";

        if ($this->ansible_user = drush_get_option('ansible_user', 'root')) {
            drush_log('Connecting as user ' . $this->ansible_user, 'status');
            $ansible->user($this->ansible_user);
            $command .= "-u {$this->ansible_user} ";
        }

        if ($this->getHostname()) {
            drush_log('Limiting playbook run to ' . $this->getHostname(), 'status');
            $ansible->limit($this->getHostname());
            $command .= "-l {$this->getHostname()} ";
        }

        if ($this->inventory) {
            $ansible->inventoryFile($this->inventory);
            $command .= "-i {$this->inventory} ";
        }

        $is_devshop = drush_get_option('is-devshop', FALSE);

        drush_log("Running '$command'", $is_devshop? 'devshop_command': 'status');

        $exit = $ansible->execute(function ($type, $buffer) {
            if (drush_get_option('is-devshop', FALSE)) {
                drush_log($buffer, 'devshop_info');
            }
            else {
                print $buffer;
            }
        });

        if ($exit != 0) {
            drush_log(dt('Ansible playbook failed to complete.'), 'devshop_error');
            drush_set_error('DRUSH_ERROR', 'Ansible command exited with non-zero code.');
        }
        else {
            drush_log(dt('Ansible playbook complete!'), 'devshop_ok');
        }

    }

    function init_platform() {
        drush_log('Provision_Service_http_ansible_apache::init_platform', 'status');

    }

    function init_site() {
        drush_log('Provision_Service_http_ansible_apache::init_site', 'status');

    }

    function verify_server_cmd() {


    }

    function verify_platform_cmd() {
        parent::verify_platform_cmd();
        drush_log('Platform Verified', 'devshop_log');
    }

    function verify_site_cmd() {
        parent::verify_site_cmd();
        drush_log('Site Verified', 'devshop_log');
    }


    /**
     * Return the inventory file from ansible configuration.
     *
     * @return string
     */
    protected function getAnsibleInventory() {
        if (!$this->inventory) {
            $this->ansible_config = $this->getAnsibleConfig();
            if (isset($this->ansible_config['inventory'])) {
                $this->inventory = $this->ansible_config['inventory'];
            }
            else {
                $this->inventory = '/etc/ansible/hosts';
            }
        }
        return $this->inventory;
    }

    /**
     * Loads ansible configuration from the default ansible.cfg files.
     *
     * @see http://docs.ansible.com/ansible/intro_configuration.html
     *
     * @return array
     */
    protected function getAnsibleConfig() {
        $ansible_cfg[] = getenv('ANSIBLE_CONFIG');
        $ansible_cfg[] = getcwd() . '/ansible.cfg';
        $ansible_cfg[] = getenv('HOME') . '/.ansible.cfg';
        $ansible_cfg[] = '/etc/ansible/ansible.cfg';

        foreach ($ansible_cfg as $path) {
            if (file_exists($path)) {
                $this->ansible_config_file = $path;
                $config = @parse_ini_file($this->ansible_config_file);
                if (is_array($config)) {
                    return $config;
                }
                else {
                    return array();
                }
            }
        }
        return array();
    }

    function getHostname() {
        return $this->server->remote_host;
    }
}
