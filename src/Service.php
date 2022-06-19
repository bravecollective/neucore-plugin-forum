<?php

namespace Brave\Neucore\Plugin\Forum;

use Neucore\Plugin\CoreCharacter;
use Neucore\Plugin\CoreGroup;
use Neucore\Plugin\Exception;
use Neucore\Plugin\ServiceAccountData;
use Neucore\Plugin\ServiceConfiguration;
use Neucore\Plugin\ServiceInterface;
use PDO;
use PDOException;
use phpbb\request\request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * TODO the table character_groups is not used anymore
 *
 * @noinspection PhpUnused
 */
class Service implements ServiceInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PDO|null
     */
    private $pdo;

    /**
     * @var array
     */
    private $config;

    /**
     * @var PhpBB
     */
    private $phpBB;

    /** @noinspection PhpUnusedParameterInspection */
    public function __construct(LoggerInterface $logger, ServiceConfiguration $serviceConfiguration)
    {
        $this->logger = $logger;
    }

    /**
     * @param CoreCharacter[] $characters
     * @return ServiceAccountData[]
     * @throws Exception
     */
    public function getAccounts(array $characters): array
    {
        $this->dbConnect();

        $characterIds = array_map(function (CoreCharacter $character) {
            return $character->id;
        }, $characters);
        $placeholders = implode(',', array_fill(0, count($characterIds), '?'));
        $stmt = $this->pdo->prepare("SELECT id, username FROM characters WHERE id IN ($placeholders)");
        try {
            $stmt->execute($characterIds);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        return array_map(function (array $row) {
            return new ServiceAccountData((int)$row['id'], $row['username']);
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param CoreGroup[] $groups
     * @param int[] $allCharacterIds
     * @throws Exception
     */
    public function register(
        CoreCharacter $character,
        array $groups,
        string $emailAddress,
        array $allCharacterIds
    ): ServiceAccountData {
        if (empty($character->name)) {
            throw new Exception();
        }
        $this->dbConnect();

        $username = $character->name;

        // create character
        $stmt = $this->pdo->prepare("
            INSERT INTO characters (id, name, username, corporation_name, alliance_name, last_update) 
            VALUES (:id, :name, :username, :corporation_name, :alliance_name, :last_update)"
        );
        try {
            $stmt->execute([
                ':id' => $character->id,
                ':name' => $character->name,
                ':username' => $username,
                ':corporation_name' => (string)$character->corporationName,
                ':alliance_name' => (string)$character->allianceName,
                ':last_update' => gmdate('Y-m-d h:i:s'),
            ]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        // save groups
        $this->addGroups($character->id, $groups);

        $phpBB = $this->getPhpBB();

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userId = $phpBB->brave_bb_account_create($character->id, $username, $ipAddress);
        if ($userId === false) {
            throw new Exception();
        }

        $success = $phpBB->brave_bb_account_update((int)$userId, [
            'corporation_name' => $character->corporationName,
            'alliance_name' => $character->allianceName,
            'core_tags' => $this->getGroupNames($groups)
        ]);
        if (!$success) {
            throw new Exception();
        }

        $password = $this->generatePassword();
        if (!$phpBB->brave_bb_account_password((int)$userId, $password)) {
            throw new Exception();
        }

        return new ServiceAccountData($character->id, $username, $password);
    }

    /**
     * @throws Exception
     */
    public function updateAccount(CoreCharacter $character, array $groups, ?CoreCharacter $mainCharacter): void
    {
        $this->dbConnect();

        // delete all groups
        $stmtDelete = $this->pdo->prepare("DELETE FROM character_groups WHERE character_id = :id");
        try {
            $stmtDelete->execute(['id' => $character->id]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        // add groups
        $this->addGroups($character->id, $groups);

        // update character - do not change name
        $stmtUpdate = $this->pdo->prepare(
            "UPDATE characters 
            SET last_update = :last_update, corporation_name = :corporation_name, alliance_name = :alliance_name
            WHERE id = :id"
        );
        try {
            $stmtUpdate->execute([
                'id' => $character->id,
                'corporation_name' => (string)$character->corporationName,
                'alliance_name' => (string)$character->allianceName,
                ':last_update' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        $phpBB = $this->getPhpBB();

        // get forum user
        $username = $this->getForumUsername($character->id);
        $userId = $phpBB->brave_bb_user_name_to_id($username);
        if ($userId === false) {
            throw new Exception();
        }

        // update forum groups
        $success = $phpBB->brave_bb_account_update((int)$userId, [
            'corporation_name' => $character->corporationName,
            'alliance_name' => $character->allianceName,
            'core_tags' => $this->getGroupNames($groups)
        ]);
        if (!$success) {
            throw new Exception();
        }
    }

    public function updatePlayerAccount(CoreCharacter $mainCharacter, array $groups): void
    {
        throw new Exception();
    }

    public function moveServiceAccount(int $toPlayerId, int $fromPlayerId): bool
    {
        return true;
    }

    /**
     * @throws Exception
     */
    public function resetPassword(int $characterId): string
    {
        $this->dbConnect();

        $username = $this->getForumUsername($characterId);

        $phpBB = $this->getPhpBB();

        // get forum user
        $userId = $phpBB->brave_bb_user_name_to_id($username);
        if ($userId === false) {
            throw new Exception();
        }

        $password = $this->generatePassword();
        if (!$phpBB->brave_bb_account_password((int)$userId, $password)) {
            throw new Exception();
        }

        return $password;
    }

    /**
     * @throws Exception
     */
    public function getAllAccounts(): array
    {
        $this->dbConnect();

        $stmt = $this->pdo->prepare("SELECT id, username FROM characters ORDER BY last_update");
        try {
            $stmt->execute();
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        return array_map(function (array $row) {
            return (int)$row['id'];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getAllPlayerAccounts(): array
    {
        return [];
    }

    /**
     * @param CoreGroup[] $groups
     * @return string
     */
    private function getGroupNames(array $groups): string
    {
        $groupNames = [];
        foreach ($groups as $group) {
            $groupNames[] = $group->name;
        }
        return implode(',', $groupNames);
    }

    /**
     * @throws Exception
     */
    private function getForumUsername(int $characterId): string
    {
        $stmt = $this->pdo->prepare("SELECT username FROM characters WHERE id = :id");
        try {
            $stmt->execute([':id' => $characterId]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $result[0]['username'];
    }

    /**
     * @throws Exception
     */
    private function addGroups(int $characterId, array $groups)
    {
        $stmt = $this->pdo->prepare("INSERT INTO character_groups (character_id, name) VALUES (:character_id, :name)");
        foreach ($groups as $group) {
            try {
                $stmt->execute([':character_id' => $characterId, ':name' => $group->name]);
            } catch (PDOException $e) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
                throw new Exception();
            }
        }
    }

    private function generatePassword(): string
    {
        $characters = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789";
        $max = mb_strlen($characters) - 1;
        $pass = '';
        for ($i = 0; $i < 10; $i++) {
            try {
                $pass .= $characters[random_int(0, $max)];
            } catch (\Exception $e) {
                $pass .= $characters[rand(0, $max)];
            }
        }
        return $pass;
    }

    /**
     * @throws Exception
     */
    private function dbConnect(): void
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = new PDO(
                    "mysql:dbname={$_ENV['NEUCORE_PLUGIN_FORUM_DB_NAME']};host={$_ENV['NEUCORE_PLUGIN_FORUM_DB_HOST']}",
                    $_ENV['NEUCORE_PLUGIN_FORUM_DB_USERNAME'],
                    $_ENV['NEUCORE_PLUGIN_FORUM_DB_PASSWORD']
                );
            } catch (PDOException $e) {
                $this->logger->error($e->getMessage() . ' at ' . __FILE__ . ':' . __LINE__);
                throw new Exception();
            }
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
    }

    private function readConfig(): void
    {
        if ($this->config !== null) {
            return;
        }

        $this->config = include $_ENV['NEUCORE_PLUGIN_FORUM_CONFIG_FILE'];
    }

    private function getPhpBB(): PhpBB
    {
        if ($this->phpBB !== null) {
            return $this->phpBB;
        }

        $this->readConfig();

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
        $phpbb_root_path = __DIR__.'/../phpBB3/';
        $phpEx = "php";
        $table_prefix = "phpbb_";

        // write config file for phpBB
        $phpBbConfig = file_get_contents($phpbb_root_path.'/config.php');
        if (empty($phpBbConfig)) {
            file_put_contents(
                $phpbb_root_path.'/config.php',
                '<?php
                $dbms = "phpbb\\db\\driver\\mysqli";
                $dbhost = "'.$_ENV['NEUCORE_PLUGIN_FORUM_DB_HOST'].'";
                $dbport = "3306";
                $dbname = "'.$_ENV['NEUCORE_PLUGIN_FORUM_DB_NAME'].'";
                $dbuser = "'.$_ENV['NEUCORE_PLUGIN_FORUM_DB_USERNAME'].'";
                $dbpasswd = "'.$_ENV['NEUCORE_PLUGIN_FORUM_DB_PASSWORD'].'";
                $table_prefix = "'.$table_prefix.'";
                $phpbb_adm_relative_path = "adm/";
                $acm_type = "phpbb\\\\cache\\\\driver\\\\file";
                ',
            );
        }

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
        $this->phpBB = new PhpBB(
            $this->config['cfg_bb_groups'],
            $this->config['cfg_bb_group_default_by_tag'],
            $this->config['cfg_bb_group_by_tag'],
            $phpbb_container,
            $config,
            $db,
            $user
        );

        return $this->phpBB;
    }

    /**
     * @throws Exception
     */
    public function request(
        CoreCharacter $coreCharacter,
        string $name,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $groups
    ): ResponseInterface {
        throw new Exception();
    }
}
