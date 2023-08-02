<?php

namespace Brave\Neucore\Plugin\Forum;

use Neucore\Plugin\Core\FactoryInterface;
use Neucore\Plugin\Data\CoreAccount;
use Neucore\Plugin\Data\CoreCharacter;
use Neucore\Plugin\Data\CoreGroup;
use Neucore\Plugin\Data\PluginConfiguration;
use Neucore\Plugin\Data\ServiceAccountData;
use Neucore\Plugin\Exception;
use Neucore\Plugin\ServiceInterface;
use PDO;
use PDOException;
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
    private const LOG_PREFIX = 'neucore-plugin-forum: ';

    private LoggerInterface $logger;

    private ?PDO $pdo = null;

    private string $console = 'php ' . __DIR__ . '/../phpbb/console.php';

    private string $configFile;

    public function __construct(
        LoggerInterface $logger,
        PluginConfiguration $pluginConfiguration,
        FactoryInterface $factory,
    ) {
        $this->logger = $logger;
        $this->configFile = $_ENV['NEUCORE_PLUGIN_FORUM_CONFIG_FILE'] ?? '';
    }

    public function onConfigurationChange(): void
    {
    }

    /**
     * @throws Exception
     */
    public function request(
        string $name,
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?CoreAccount $coreAccount,
    ): ResponseInterface {
        throw new Exception();
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
            $this->logger->error(self::LOG_PREFIX . $e->getMessage(), ['exception' => $e]);
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
        $this->setupPhpBbConfig();

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
            $this->logger->error(self::LOG_PREFIX . $e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        // save groups
        $this->addGroups($character->id, $groups);

        $password = $this->generatePassword();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';

        $args = $this->getArgsString([
            $this->configFile,
            'register',
            $username,
            $password,
            $character->id,
            $this->getGroupNames($groups),
            $character->corporationName,
            $character->allianceName,
            $ipAddress,
        ]);
        exec("$this->console $args", $output, $retVal);
        if ($retVal !== 0) {
            $this->logger->error(self::LOG_PREFIX . json_encode($output));
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
        $this->setupPhpBbConfig();

        // delete all groups
        $stmtDelete = $this->pdo->prepare("DELETE FROM character_groups WHERE character_id = :id");
        try {
            $stmtDelete->execute(['id' => $character->id]);
        } catch (PDOException $e) {
            $this->logger->error(self::LOG_PREFIX . $e->getMessage(), ['exception' => $e]);
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
            $this->logger->error(self::LOG_PREFIX . $e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        $args = $this->getArgsString([
            $this->configFile,
            'update-account',
            $this->getForumUsername($character->id),
            $character->corporationName,
            $character->allianceName,
            $this->getGroupNames($groups),
        ]);
        exec("$this->console $args", $output, $retVal);
        if ($retVal !== 0) {
            $this->logger->error(self::LOG_PREFIX . json_encode($output));
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
        $this->setupPhpBbConfig();

        $username = $this->getForumUsername($characterId);
        $password = $this->generatePassword();

        $args = $this->getArgsString([$this->configFile, 'reset-password', $username, $password]);
        exec("$this->console $args", $output, $retVal);
        if ($retVal !== 0) {
            $this->logger->error(self::LOG_PREFIX . json_encode($output));
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
            $this->logger->error(self::LOG_PREFIX . $e->getMessage(), ['exception' => $e]);
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

    public function search(string $query): array
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
            $this->logger->error(self::LOG_PREFIX . $e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $result[0]['username'];
    }

    /**
     * @throws Exception
     */
    private function addGroups(int $characterId, array $groups): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO character_groups (character_id, name) VALUES (:character_id, :name)");
        foreach ($groups as $group) {
            try {
                $stmt->execute([':character_id' => $characterId, ':name' => $group->name]);
            } catch (PDOException $e) {
                $this->logger->error(self::LOG_PREFIX . $e->getMessage(), ['exception' => $e]);
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
            } catch (\Exception) {
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
                $this->logger->error(self::LOG_PREFIX . $e->getMessage() . ' at ' . __FILE__ . ':' . __LINE__);
                throw new Exception();
            }
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
    }

    private function setupPhpBbConfig(): void
    {
        $phpbb_root_path = Shared::PHPBB_ROOT_PATH;

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
                $table_prefix = "'.Shared::PHPBB_TABLE_PREFIX.'";
                $phpbb_adm_relative_path = "adm/";
                $acm_type = "phpbb\\\\cache\\\\driver\\\\file";
                ',
            );
        }
    }

    private function getArgsString(array $args): string
    {
        $argsString = [];
        foreach ($args as $arg) {
            $argsString[] = escapeshellarg($arg);
        }
        return implode(' ', $argsString);
    }
}
