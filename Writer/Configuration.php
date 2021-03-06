<?php
/**
 * Configuration.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 26.6.13
 */

namespace Keboola\Google\DriveWriterBundle\Writer;

use Keboola\Google\DriveWriterBundle\Entity\AccountFactory;
use Keboola\Google\DriveWriterBundle\Entity\Account;
use Keboola\Google\DriveWriterBundle\Exception\ConfigurationException;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\StorageApi\Config\Reader;
use Keboola\StorageApi\Table;
use Keboola\Syrup\Service\ObjectEncryptor;

class Configuration
{
	/** @var StorageApi */
	protected $storageApi;

	protected $componentName;

	protected $sys_prefix = 'sys.c-';

	protected $accounts;

	/** @var AccountFactory */
	protected $accountFactory;

	/** @var ObjectEncryptor */
	protected $encryptor;

	protected $tokenExpiration = 172800;

	public function __construct($componentName, ObjectEncryptor $encryptor)
	{
		$this->componentName = $componentName;

		$this->encryptor = $encryptor;

		$this->accountFactory = new AccountFactory($this);
	}

	public function getEncryptor()
	{
		return $this->encryptor;
	}

	public function setEncryptor($encryptor)
	{
		$this->encryptor = $encryptor;
	}

	public function setStorageApi($storageApi)
	{
		$this->storageApi = $storageApi;
	}

	public function getStorageApi()
	{
		return $this->storageApi;
	}

	public function create()
	{
		$this->storageApi->createBucket($this->componentName, 'sys', 'GoogleDrive Writer');
	}

	public function exists()
	{
		return $this->storageApi->bucketExists($this->getSysBucketId());
	}

	/**
	 * Add new account
	 * @param $data
	 * @return \Keboola\Google\DriveWriterBundle\Entity\Account
	 */
	public function addAccount($data)
	{
		$data['id'] = $this->getIdFromName($data['name']);
		$account = $this->accountFactory->get($data['id']);
		$account->fromArray($data);
		$account->save();

		return $account;
	}

	/**
	 * Remove account
	 *
	 * @param $accountId
	 */
	public function removeAccount($accountId)
	{
		$tableId = $this->getSysBucketId() . '.' . $accountId;
		if ($this->storageApi->tableExists($tableId)) {
			$this->storageApi->dropTable($tableId);
		}

		unset($this->accounts[$accountId]);
	}

	public function deleteFile($accountId, $fileId)
	{
		/** @var Account $account */
		$account = $this->getAccount($accountId);
		$account->removeFile($fileId);
		$account->save();
	}

	public function getConfig()
	{
		Reader::$client = $this->storageApi;
		try {
			$config = Reader::read($this->getSysBucketId());

			if (isset($config['items'])) {
				return $config['items'];
			}
		} catch (\Exception $e) {

		}

		return array();
	}

	public function getSysBucketId()
	{
		if ($this->storageApi->bucketExists('sys.c-' . $this->componentName)) {
			return 'sys.c-' . $this->componentName;
		} else if ($this->storageApi->bucketExists('sys.' . $this->componentName)) {
			return 'sys.' . $this->componentName;
		}
		throw new ConfigurationException("SYS bucket don't exists");
	}

	/**
	 * @param bool $asArray - convert Account objects to array
	 * @return array - array of Account objects or 2D array
	 */
	public function getAccounts($asArray = false)
	{
		$accounts = array();
		foreach ($this->getConfig() as $accountId => $v) {
			$account = $this->accountFactory->get($accountId);
			$account->fromArray($v);
			if ($asArray) {
				$account = $account->toArray();
			}
			$accounts[$accountId] = $account;
		}

		return $accounts;
	}

	/**
	 * @param $id
	 * @return Account|array
	 */
	public function getAccount($id)
	{
		$accounts = $this->getAccounts();

		if (!isset($accounts[$id])) {
			throw new ConfigurationException(sprintf("Account %s not found", $id));
		}

		return $accounts[$id];
	}

	public function getAccountBy($key, $value, $asArray = false)
	{
		$accounts = $this->getAccounts();

		$method = 'get' . ucfirst($key);
		/** @var Account $account */
		foreach ($accounts as $account) {
			if ($account->$method() == $value) {
				if ($asArray) {
					return $account->toArray();
				}
				return $account;
			}
		}

		return null;
	}

	public function updateFile($accountId, $fileId, $params)
	{
		$account = $this->getAccount($accountId);
		$account->updateFile($fileId, $params);
		$account->save();
	}

	private function getAccountId()
	{
		$accountId = 0;
		/** @var Account $v */
		foreach($this->getAccounts() as $k => $v) {
			if ($k >= $accountId) {
				$accountId = $k+1;
			}
		}

		return $accountId;
	}

	public function getIdFromName($name)
	{
		return strtolower(Table::removeSpecialChars($name));
	}

	public function createToken()
	{
		$permissions = array(
			$this->getSysBucketId() => 'write'
		);
		$tokenId = $this->storageApi->createToken($permissions, 'External Authorization', $this->tokenExpiration);
		$token = $this->storageApi->getToken($tokenId);

		return $token;
	}

	public function getFiles($accountId)
	{
		return $this->getAccount($accountId)->getFiles();
	}

	public function addFile($accountId, $fileData)
	{
		$account = $this->getAccount($accountId);

        $fileData['id'] = $this->storageApi->generateId();
        $account->addFile($fileData);

		$account->save();

        return $fileData['id'];
	}
}
