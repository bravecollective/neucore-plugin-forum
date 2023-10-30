<?php

namespace plugin\phpbb;

use Brave\Neucore\Plugin\Forum\Shared;
use Exception;
use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\log\log_interface;
use phpbb\profilefields\manager;
use phpbb\request\request;
use phpbb\user;
use Symfony\Component\DependencyInjection\Container;

require_once __DIR__ . '/../src/Shared.php';

/**
 * phpBB integration.
 *
 * This is executed via console, it does not run in a Neucore context.
 *
 * Copied and adjusted from https://github.com/bravecollective/forum-auth
 * which originated from https://github.com/bravecollective/oldcore-forum-auth
 *
 * This needs two custom profile fields (Single text fields):
 * core_corp_name
 * core_alli_name
 *
 * See config.php.dist for groups.
 */
final class PhpBB
{
    private static ?array $config = null;

    private static ?PhpBB $phpBB = null;

    private array $configGroups;

    private array $configGroupDefaultByTag;

    private array $configGroupByTag;

    private Container $phpBBContainer;

    private config $phpBBConfig;

    private driver_interface $db;

    private user $user;

    private log_interface $phpBBLog;

    /**
     * @throws Exception
     */
    public static function getInstance(string $configFile): PhpBB
    {
        if (self::$phpBB !== null) {
            return self::$phpBB;
        }

        if (self::$config === null) {
            self::$config = include $configFile;
        }

        // Note: This was initially written for phpBB version 3.2.2,
        //       worked unmodified with phpBB version 3.3.4.

        // define required constants
        if (!defined('Patchwork\Utf8\MB_OVERLOAD_STRING')) {
            // There's a fatal error with PHP 8 without this in
            // phpBB3/vendor/patchwork/utf8/src/Patchwork/Utf8/Bootup.php
            // This will probably not work if the mbstring extension is missing.
            // See also phpBB3/vendor/patchwork/utf8/src/Patchwork/Utf8/Bootup/mbstring.php
            define('Patchwork\Utf8\MB_OVERLOAD_STRING', 2);
        }
        define('IN_PHPBB', true);
        define('PHPBB_INSTALLED', true); // will send a header("location") otherwise

        // development = necessary to have errors in the Neucore log, but notices will cause errors!
        // but DEV does not work with PHP 8: Declaration of phpbb\debug\error_handler::handleError ...
        #define('PHPBB_ENVIRONMENT', 'development');
        define('PHPBB_ENVIRONMENT', 'production');

        // Variables that needs to be global.
        global $phpbb_root_path, $phpEx, $table_prefix;
        $phpbb_root_path = Shared::PHPBB_ROOT_PATH;
        $phpEx = "php";
        $table_prefix = Shared::PHPBB_TABLE_PREFIX;

        // write development config if running in dev mode
        if (PHPBB_ENVIRONMENT === 'development' && !is_file($phpbb_root_path.'/config/development/config.yml')) {
            mkdir($phpbb_root_path.'/config/development');
            mkdir($phpbb_root_path.'/config/development/container');
            mkdir($phpbb_root_path.'/config/development/routing');
            copy($phpbb_root_path.'/config/production/config.yml', $phpbb_root_path.'/config/development/config.yml');
            copy(
                $phpbb_root_path.'/config/production/container/environment.yml',
                $phpbb_root_path.'/config/development/container/environment.yml'
            );
            copy(
                $phpbb_root_path.'/config/production/container/parameters.yml',
                $phpbb_root_path.'/config/development/container/parameters.yml'
            );
            copy(
                $phpbb_root_path.'/config/production/container/services.yml',
                $phpbb_root_path.'/config/development/container/services.yml'
            );
            copy(
                $phpbb_root_path.'/config/production/routing/environment.yml',
                $phpbb_root_path.'/config/development/routing/environment.yml'
            );
        }

        // a few variables from common.php need to be global
        /** @noinspection PhpUnusedLocalVariableInspection */
        global $phpbb_container, $phpbb_dispatcher, $request;

        // include necessary phpBB functions
        require_once $phpbb_root_path . 'common.'.$phpEx;
        require_once $phpbb_root_path . 'includes/functions_user.'.$phpEx;

        // phpBB overwrites super globals, but we need them.
        /* @var request $request */
        $request->enable_super_globals();

        global $config, $db, $user; // These global variables were created with the common.php include
        /** @noinspection PhpParamsInspection */
        self::$phpBB = new PhpBB(
            self::$config['cfg_bb_groups'],
            self::$config['cfg_bb_group_default_by_tag'],
            self::$config['cfg_bb_group_by_tag'],
            $phpbb_container,
            $config,
            $db,
            $user,
            $phpbb_container->get('log')
        );

        return self::$phpBB;
    }

    public function register(array $args): ?string
    {
        if (count($args) < 7) {
            return 'register: Missing arguments (7).';
        }

        $username = $args[0];
        $password = $args[1];
        $characterId = $args[2];
        $corporationName = $args[3];
        $allianceName = $args[4];
        $groups = $args[5] ?? '';

        $userId = $this->brave_bb_account_create($characterId, $username);
        if (!$userId) {
            return 'Failed to add user.';
        }

        $success = $this->brave_bb_account_update($userId, [
            'corporation_name' => $corporationName,
            'alliance_name' => $allianceName === Shared::PLACEHOLDER_NO_ALLIANCE ? '' : $allianceName,
            'core_tags' => $groups
        ]);
        if (!$success) {
            return 'Failed to update user.';
        }

        if (!$this->brave_bb_account_password($userId, $password)) {
            return 'Failed to set password.';
        }

        return null;
    }

    public function updateAccount(array $args): ?string
    {
        if (count($args) < 2) {
            return 'updateAccount: Missing arguments (2).';
        }

        $username = $args[0];
        $corporationName = $args[1];
        $allianceName = $args[2];
        $groups = $args[3] ?? '';

        // get forum user
        $userId = $this->brave_bb_user_name_to_id($username);
        if (!$userId) {
            return 'User not found.';
        }

        // update forum groups
        $success = $this->brave_bb_account_update($userId, [
            'corporation_name' => $corporationName,
            'alliance_name' => $allianceName === Shared::PLACEHOLDER_NO_ALLIANCE ? '' : $allianceName,
            'core_tags' => $groups
        ]);
        if (!$success) {
            return 'Failed to update account.';
        }

        return null;
    }

    public function resetPassword(array $args): ?string
    {
        if (count($args) < 2) {
            return 'resetPassword: Missing arguments (2).';
        }

        $username = $args[0];
        $password = $args[1];

        // get forum user
        $userId = $this->brave_bb_user_name_to_id($username);
        if (!$userId) {
            return 'User not found.';
        }

        if (!$this->brave_bb_account_password($userId, $password)) {
            return 'Failed to change password.';
        }

        return null;
    }

    private function __construct(
        array $cfg_bb_groups,
        array $cfg_bb_group_default_by_tag,
        array $cfg_bb_group_by_tag,
        Container $phpbb_container,
        config $config,
        driver_interface $db,
        user $user,
        log_interface $phpBBLog,
    ) {
        $this->configGroups = $cfg_bb_groups;
        $this->configGroupDefaultByTag = $cfg_bb_group_default_by_tag;
        $this->configGroupByTag = $cfg_bb_group_by_tag;

        $this->phpBBContainer = $phpbb_container;
        $this->phpBBConfig = $config;
        $this->db = $db;
        $this->user = $user;
        $this->phpBBLog = $phpBBLog;

        // needed to prevent some undefined index errors in dev mode (the object is included via "global" in phpBB)
        // the user_id must *not* be the user ID that is used to do stuff
        $this->user->data = ['user_id' => 0, 'user_email' => null];
    }

    private function brave_bb_user_name_to_id(string $user_name): ?int
    {
        $user_names = array($user_name);
        $user_ids = array();
        $result = user_get_id_name($user_ids, $user_names);
        if ($result) {
            return null;
        }
        if (sizeof($user_ids) == 1) {
            return (int)$user_ids[0];
        }
        return null;
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

    private function brave_bb_account_create($character_id, $user_name): ?int
    {
        $user = array(
            'username' => $user_name,
            'user_email' => '',
            'group_id' => $this->configGroups['register'],
            'user_type' => USER_NORMAL,
            'user_ip' => '',
            'user_new' => ($this->phpBBConfig['new_member_post_limit']) ? 1 : 0,
            'user_avatar' => 'https://image.eveonline.com/Character/' . $character_id . '_128.jpg',
            'user_avatar_type' => 2,
            'user_avatar_width' => 128,
            'user_avatar_height' => 128
        );

        user_add($user);

        $user_id = $this->brave_bb_user_name_to_id($user_name);

        $this->phpBBLog->add('user', 0, '', 'LOG_USER_GENERAL', time(), [
            'reportee_id' => (string)$user_id,
            0 => 'Created user through CORE',
        ]);

        return $user_id;
    }

    /**
     * @param int $user_id forum user ID
     * @param array $character
     * @return bool
     */
    private function brave_bb_account_update(int $user_id, array $character): bool
    {
        /* @var $cp manager */
        try {
            $cp = $this->phpBBContainer->get('profilefields.manager');
        } catch (Exception) {
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
        foreach (group_memberships(false, [$user_id]) as $g) {
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
            group_user_add($gid, $user_id);
        }

        group_set_user_default($gid_default, [$user_id], false, true);

        return true;
    }

    private function brave_bb_account_password(int $user_id, string $password): bool
    {
        try {
            $passwords_manager = $this->phpBBContainer->get('passwords.manager');
        } catch (Exception) {
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

        $this->phpBBLog->add('user', 0, '', 'LOG_USER_NEW_PASSWORD', time(), [
            'reportee_id' => $user_id,
            0 => 'Reset password through CORE',
        ]);

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
