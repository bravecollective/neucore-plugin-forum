<?php

namespace Brave\Neucore\Plugin\Forum;

use Exception;
use phpbb\config\db;
use phpbb\db\driver\driver_interface;
use phpbb\profilefields\manager;
use phpbb\user;
use Symfony\Component\DependencyInjection\Container;

/**
 * phpBB integration.
 *
 * Copied and adjusted from https://github.com/bravecollective/forum-auth
 * which originated from https://github.com/bravecollective/oldcore-forum-auth
 *
 * This needs two custom profile fields (Single text fields):
 * core_corp_name
 * core_alli_name
 *
 * See config.php for groups.
 */
class PhpBB
{
    /**
     * @var array
     */
    private $configGroups;

    /**
     * @var array
     */
    private $configGroupDefaultByTag;

    /**
     * @var array
     */
    private $configGroupByTag;

    /**
     * @var Container
     */
    private $phpBBContainer;

    /**
     * @var db
     */
    private $config;

    /**
     * @var driver_interface
     */
    private $db;

    /**
     * @var user
     */
    private $user;

    public function __construct(
        array $cfg_bb_groups,
        array $cfg_bb_group_default_by_tag,
        array $cfg_bb_group_by_tag,
        Container $phpbb_container,
        db $config,
        driver_interface $db,
        user $user
    ) {
        $this->configGroups = $cfg_bb_groups;
        $this->configGroupDefaultByTag = $cfg_bb_group_default_by_tag;
        $this->configGroupByTag = $cfg_bb_group_by_tag;

        $this->phpBBContainer = $phpbb_container;
        $this->config = $config;
        $this->db = $db;
        $this->user = $user;

        // needed to prevent some undefined index errors in dev mode (the object is included via "global" in phpBB)
        // the user_id must *not* be the user ID that is used to do stuff
        $this->user->data = ['user_id' => 0, 'user_email' => null];
    }

    /**
     * @return false|numeric
     */
    public function brave_bb_user_name_to_id(string $user_name)
    {
        $user_names = array($user_name);
        $user_ids = array();
        $result = user_get_id_name($user_ids, $user_names);
        if ($result) {
            return false;
        }
        if (sizeof($user_ids) == 1) {
            return $user_ids[0];
        }
        return false;
    }

    /*function brave_bb_account_activate($user_name)
    {
        $user_id = $this->brave_bb_user_name_to_id($user_name);
        if (! $user_id) {
            return;
        }

        user_active_flip('activate', $user_id);

        $this->brave_bb_account_update($user_name);
    }*/

    /*function brave_bb_account_deactivate($user_name)
    {
        $user_id = $this->brave_bb_user_name_to_id($user_name);
        if (! $user_id) {
            return;
        }

        user_active_flip('deactivate', $user_id);

        $this->brave_bb_account_update($user_name);
    }*/

    public function brave_bb_account_create($character_id, $user_name, $ipAddress)
    {
        $user = array(
            'username' => $user_name,
            'user_email' => '',
            'group_id' => $this->configGroups['register'],
            'user_type' => USER_NORMAL,
            'user_ip' => $ipAddress,
            'user_new' => ($this->config['new_member_post_limit']) ? 1 : 0,
            'user_avatar' => 'https://image.eveonline.com/Character/' . $character_id . '_128.jpg',
            'user_avatar_type' => 2,
            'user_avatar_width' => 128,
            'user_avatar_height' => 128
        );

        user_add($user);

        $user_id = $this->brave_bb_user_name_to_id($user_name);

        add_log('user', $user_id, 'LOG_USER_GENERAL', 'Created user through CORE');

        return $user_id;
    }

    /**
     * @param int $user_id forum user ID
     * @param array $character
     * @return bool
     */
    public function brave_bb_account_update(int $user_id, array $character): bool
    {
        /* @var $cp manager */
        try {
            $cp = $this->phpBBContainer->get('profilefields.manager');
        } catch (Exception $e) {
            return false;
        }

        $cp_data = array();
        $cp_data['pf_core_corp_name'] = $character['corporation_name'];
        $cp_data['pf_core_alli_name'] = $character['alliance_name'];
        $cp->update_profile_field_data($user_id, $cp_data);

        // DO GROUP MAGIC

        $tags = explode(",", $character['core_tags']);
        $tags = array_unique($tags);
        asort($tags);

        $gid_default = $this->configGroups[$this->configGroupDefaultByTag['default'][1]];

        $i = 0;
        foreach ($tags as $tag) {
            $gs = $this->configGroupDefaultByTag[$tag] ?? null;
            if (! $gs) {
                continue;
            }
            $gid = $this->configGroups[$gs[1]] ?? null;
            if (! $gid || $gs[0] < $i) {
                continue;
            }
            $i = $gs[0];
            $gid_default = $gid;
        }

        $gIds_want = array();
        $gIds_want[] = $gid_default;
        $gIds_want[] = $this->configGroups['register'];
        foreach ($tags as $t) {
            $ids = $this->brave_tag_to_group_ids((string)$t);
            foreach ($ids as $id) {
                $gIds_want[] = $id;
            }
        }
        $gIds_want = array_unique($gIds_want);

        $gIds_has = array();
        foreach (group_memberships(false, [$user_id], false) as $g) {
            $gid = $g['group_id'];
            if (! in_array($gid, $gIds_want)) {
                group_user_del($gid, $user_id);
                continue;
            }
            $gIds_has[] = $gid;
        }

        foreach ($gIds_want as $gid) {
            if (in_array($gid, $gIds_has)) {
                continue;
            }
            group_user_add($gid, $user_id, false, false, false);
        }

        group_set_user_default($gid_default, [$user_id], false, true);

        return true;
    }

    public function brave_bb_account_password(int $user_id, string $password): bool
    {
        try {
            $passwords_manager = $this->phpBBContainer->get('passwords.manager');
        } catch (Exception $e) {
            return false;
        }

        $sql_ary = array(
            'user_password' => $passwords_manager->hash($password),
            'user_passchg' => time()
        );

        $sql = 'UPDATE ' . USERS_TABLE .
            ' SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) .
            ' WHERE user_id = ' . $user_id;
        $this->db->sql_query($sql);

        $this->user->reset_login_keys($user_id);

        add_log('user', $user_id, 'LOG_USER_NEW_PASSWORD', 'Reset password through CORE');

        return true;
    }

    private function brave_tag_to_group_ids(string $tag): array
    {
        $shorts = $this->configGroupByTag[$tag] ?? null;
        if (! $shorts) {
            return array();
        }
        if (! is_array($shorts)) {
            $shorts = array($shorts);
        }

        $ids = array();
        foreach ($shorts as $short) {
            $id = $this->configGroups[$short] ?? null;
            if (! $id) {
                continue;
            }
            $ids[] = $id;
        }

        return $ids;
    }
}
