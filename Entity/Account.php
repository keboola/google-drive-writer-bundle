<?php
/**
 * Account.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 26.6.13
 */

namespace Keboola\Google\DriveWriterBundle\Entity;

use Keboola\Google\DriveWriterBundle\Exception\ConfigurationException;
use Keboola\Google\DriveWriterBundle\Writer\Configuration;
use Keboola\StorageApi\Table;

class Account extends Table
{
	protected $header = array('id', 'title', 'googleId', 'type', 'sheetId', 'tableId', 'operation', 'targetFolder');

	protected $accountId;

	/** @var Configuration */
	protected $configuration;

	protected $files = array();

	public function __construct(Configuration $configuration, $accountId)
	{
		$this->configuration = $configuration;
		$storageApi = $this->configuration->getStorageApi();
		$sysBucket = $this->configuration->getSysBucketId();
		$this->accountId = $accountId;

		parent::__construct($storageApi, $sysBucket . '.' . $accountId, "");
	}

	public function getAttribute($key)
	{
		if (isset($this->attributes[$key])) {
			return $this->attributes[$key];
		}
		return null;
	}

	/**
	 * alias to setAccountId
	 *
	 * @param $id
	 * @return $this
	 */
	public function setId($id)
	{
		$this->setAccountId($id);
		return $this;
	}

	public function setAccountId($id)
	{
		$this->setAttribute('id', $id);
		$this->accountId = $id;
		return $this;
	}

	public function getAccountId()
	{
		return $this->accountId;
	}

	public function setGoogleId($googleId)
	{
		$this->setAttribute('googleId', $googleId);
		return $this;
	}

	public function getGoogleId()
	{
		return $this->getAttribute('googleId');
	}

	public function setEmail($email)
	{
		$this->setAttribute('email', $email);
		return $this;
	}

	public function getEmail()
	{
		return $this->getAttribute('email');
	}

	public function setAccountName($name)
	{
		$this->setAttribute('name', $name);
		return $this;
	}

	public function getAccountName()
	{
		return $this->getAttribute('name');
	}

	public function setGoogleName($name)
	{
		$this->setAttribute('googleName', $name);
		return $this;
	}

	public function getGoogleName()
	{
		return $this->getAttribute('googleName');
	}

	public function setDescription($desc)
	{
		$this->setAttribute('description', $desc);
		return $this;
	}

	public function getDescription()
	{
		return $this->getAttribute('description');
	}

	public function setAccessToken($accessToken)
	{
		try {
			$this->setAttribute('accessToken', $this->configuration->getEncryptor()->encrypt($accessToken));
		} catch (\Exception $e) {
		}
		return $this;
	}

	public function getAccessToken()
	{
		try {
			return $this->configuration->getEncryptor()->decrypt($this->getAttribute('accessToken'));
		} catch (\Exception $e) {
			return null;
		}

	}

	public function setRefreshToken($refreshToken)
	{
		try {
			$this->setAttribute('refreshToken', $this->configuration->getEncryptor()->encrypt($refreshToken));
		} catch (\Exception $e) {
		}
		return $this;
	}

	public function getRefreshToken()
	{
		try {
			return $this->configuration->getEncryptor()->decrypt($this->getAttribute('refreshToken'));
		} catch (\Exception $e) {
			return null;
		}
	}

	public function getFiles()
	{
		return $this->files;
	}

	public function fromArray($array)
	{
		if (isset($array['items'])) {
			// set sheets as array
			$this->setFromArray($array['items']);

			foreach ($array['items'] as $file) {
				$this->files[] = new File($file);
			}
		}
		unset($array['items']);

		foreach($array as $k => $v) {
			$this->setAttribute($k, $v);
		}
	}

	public function getFile($fileId)
	{
		/**
		 * @var int $k
		 * @var File $v
		 */
		foreach ($this->files as $k => $v) {
			if ($v->getId() == $fileId) {
				return $v;
			}
		}

		return null;
	}

	public function updateFile($fileId, $params)
	{
		$file = null;
		$fileIndex = null;

		/**
		 * @var int $k
		 * @var File $v
		 */
		foreach ($this->files as $k => $v) {
			if ($v->getId() == $fileId) {
				$file = $v;
				$fileIndex = $k;
				break;
			}
		}

		foreach ($params as $k => $v) {
			$methodName = 'set' . ucfirst($k);

			if (method_exists($file, $methodName)) {
				$file->$methodName($v);
			}
		}

		$this->files[$fileIndex] = $file;
	}

	public function addFile($fileData)
	{
		$this->files[] = new File($fileData);

		return $this;
	}

	public function removeFile($fileId)
	{
		/**
		 * @var int $k
		 * @var File $v
		 */
		foreach ($this->files as $k => $v) {
			if ($v->getId() == $fileId) {
				unset($this->files[$k]);
				break;
			}
		}
	}

	public function toArray()
	{
		$attributes = $this->getAttributes();
		$attributes['accountId'] = $this->accountId;
		$array = array_merge(
			$attributes,
			array(
				'items' => $this->getData()
			)
		);
		return $array;
	}

	public function save($async = false)
	{
		$data = array();

		/** @var File $file */
		foreach ($this->files as $file) {
			$data[] = $file->toArray();
		}

		$this->setFromArray($data);

		parent::save($async);
	}
}
