<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

namespace Gibbon\Session;

use SessionHandlerInterface;
use Gibbon\Contracts\Database\Connection;

/**
 * DatabaseSessionHandler Class
 *
 * @version v23
 * @since   v23
 */
class DatabaseSessionHandler implements SessionHandlerInterface
{
    use SessionEncryption;

    /**
     * @var Gibbon\Contracts\Database\Connection
     */
    protected $db;

    /**
     * @var string
     */
    private $key;

    /**
     * @var bool
     */
    private $encrypted;

    /**
     * Handles session read/write methods using a database connection rather than
     * the built-in PHP session handler.
     *
     * @param Connection $connection
     * @param string|null $key
     */
    public function __construct(Connection $connection, string $key = null)
    {
        $this->db = $connection;
        $this->key = $key;
        $this->encrypted = !empty($key) && function_exists('openssl_encrypt');
    }

    /**
     * Implements the SessionHandlerInterface
     *
     * @param string $path
     * @param string $name
     * @return bool true for success or false for failure
     */
    public function open($path, $name)
    {
        return true;
    }

    /**
     * Implements the SessionHandlerInterface
     *
     * @return bool true for success or false for failure
     */
    public function close()
    {
        return true;
    }

    /**
     * Implements the SessionHandlerInterface
     *
     * @param string $id
     * @return bool true for success or false for failure
     */
    public function destroy($id)
    {
        $data = ['gibbonSessionID' => $id];
        $sql = "DELETE FROM gibbonSession WHERE gibbonSessionID=:gibbonSessionID";

        return $this->db->delete($sql, $data) ? true : false;
    }

    /**
     * Implements the SessionHandlerInterface
     *
     * @param string $id
     * @return string the session data or an empty string
     */
    public function read($id)
    {
        $data = ['gibbonSessionID' => $id];
        $sql = "SELECT sessionData FROM gibbonSession WHERE gibbonSessionID=:gibbonSessionID";

        $sessionData = (string)$this->db->selectOne($sql, $data);
        
        if ($this->encrypted) {
            $sessionData = $this->decrypt($sessionData, $this->key);
        }

        return $sessionData;
    }

    /**
     * Implements the SessionHandlerInterface
     *
     * @param string $id
     * @param string $data
     * @return bool true for success or false for failure
     */
    public function write($id, $sessionData)
    {
        if ($this->encrypted) {
            $sessionData = $this->encrypt($sessionData, $this->key);
        }

        $data = ['gibbonSessionID' => $id, 'sessionData' => $sessionData, 'timestampCreated' => date('Y-m-d H:i:s'), 'timestampModified' => date('Y-m-d H:i:s')];
        $sql = "INSERT INTO gibbonSession (gibbonSessionID, sessionData, timestampCreated, timestampModified) VALUES (:gibbonSessionID, :sessionData, :timestampCreated, :timestampModified) ON DUPLICATE KEY UPDATE sessionData=:sessionData, timestampModified=:timestampModified";

        $this->db->insert($sql, $data);

        return $this->db->getQuerySuccess();
    }

    /**
     * Implements the SessionHandlerInterface
     *
     * @param int $max_lifetime
     * @return bool true for success or false for failure
     */
    public function gc($max_lifetime)
    {
        $data = ['maxLifetime' => $max_lifetime];
        $sql = "DELETE FROM gibbonSession WHERE TIMESTAMPDIFF(SECOND, timestampModified, CURRENT_TIMESTAMP) > :maxLifetime";

        $this->db->delete($sql, $data);

        return $this->db->getQuerySuccess();
    }
}
