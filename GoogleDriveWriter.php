<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 17/04/14
 * Time: 18:38
 */

namespace Keboola\Google\DriveWriterBundle;


use Keboola\Google\DriveWriterBundle\Entity\Account;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Google\DriveWriterBundle\Exception\ConfigurationException;
use Keboola\Google\DriveWriterBundle\Exception\ParameterMissingException;
use Keboola\Google\DriveWriterBundle\GoogleDrive\RestApi;
use Keboola\Google\DriveWriterBundle\Writer\Configuration;
use Syrup\ComponentBundle\Component\Component;
use Syrup\ComponentBundle\Exception\UserException;

class GoogleDriveWriter extends Component
{
	protected $name = 'google-drive';
	protected $prefix = 'wr';

	/** @var Configuration */
	protected $configuration;

	/**
	 * @return Configuration
	 */
	protected function getConfiguration()
	{
		if ($this->configuration == null) {
			$this->configuration = new Configuration($this->storageApi, $this->getFullName(), $this->container->get('syrup.encryptor'));
		}
		return $this->configuration;
	}

	protected function checkParams($required, $params)
	{
		foreach ($required as $r) {
			if (!isset($params[$r])) {
				throw new ParameterMissingException(sprintf("Parameter %s is missing.", $r));
			}
		}
	}

	/**
	 * @param Entity\Account $account
	 * @internal param $accessToken
	 * @internal param $refreshToken
	 * @return RestApi
	 */
	protected function getApi(Account $account)
	{
		/** @var RestApi $googleDriveApi */
		$googleDriveApi = $this->container->get('google_drive_writer_rest_api');
		$googleDriveApi->getApi()->setCredentials($account->getAccessToken(), $account->getRefreshToken());

		//@TODO
//		$googleDriveApi->getApi()->setRefreshTokenCallback(array($this->extractor, 'refreshTokenCallback'));

		return $googleDriveApi;
	}

	public function postRun($params)
	{
		$accounts = $this->getConfiguration()->getAccounts();
		if (isset($options['account'])) {
			if (!isset($accounts[$options['account']])) {
				throw new ConfigurationException("Account '" . $options['account'] . "' does not exist.");
			}
			$accounts = array(
				$options['account'] => $accounts[$options['account']]
			);
		}

		/** @var Account $account */
		foreach ($accounts as $accountId => $account) {

			$googleDriveApi = $this->getApi($account);

			$files = $account->getFiles();

			/** @var File $file */
			foreach ($files as $file) {

				$file->setPathname($this->getTemp()->createTmpFile()->getPathname());

				$this->storageApi->exportTable($file->getTableId(), $file->getPathname());

				$googleDriveApi->uploadFile($file);
			}
		}

		return array(
			'status'    => 'ok'
		);
	}

	public function getConfigs()
	{
		$accounts = $this->getConfiguration()->getAccounts(true);

		$res = array();
		foreach ($accounts as $account) {
			$res[] = array_intersect_key($account, array_fill_keys(array('id', 'name', 'description'), 0));
		}

		return $res;
	}

	public function postConfigs($params)
	{
		$this->checkParams(array('name'), $params);

		try {
			$this->getConfiguration()->exists();
		} catch (ConfigurationException $e) {
			$this->getConfiguration()->create();
		}

		if (null != $this->getConfiguration()->getAccountBy('accountId', $this->configuration->getIdFromName($params['name']))) {
			throw new ConfigurationException('Account already exists');
		}

		return $this->getConfiguration()->addAccount($params);
	}

	public function deleteConfig($id)
	{
		$this->getConfiguration()->removeAccount($id);
	}

	public function getAccount($id)
	{
		return $this->getConfiguration()->getAccountBy('accountId', $id, true);
	}

	public function getAccounts()
	{
		return $this->getConfiguration()->getAccounts(true);
	}

	public function postFiles($accountId, $params)
	{
		/** @var Account $account */
		$account = $this->getConfiguration()->getAccountBy('accountId', $accountId);

		if ($account == null) {
			throw new UserException("Account doesn't exist.");
		}

		foreach ($params as $fileData) {
			$this->checkParams(array(
				'tableId',
				'title'
			), $fileData);

			$account->addFile($fileData);
		}
		$account->save();

		return array('status'   => 'ok');
	}

	public function getToken()
	{
		return $this->getConfiguration()->createToken();
	}

	public function getFiles($accountId)
	{
		$accounts = $this->getConfiguration()->getAccounts();

		/** @var Account $account */
		$account = $accounts[$accountId];

		$res = array();

		/** @var File $file */
		foreach ($account->getFiles() as $file) {
			$res[] = $file->toArray();
		}

		return $res;
	}
}
