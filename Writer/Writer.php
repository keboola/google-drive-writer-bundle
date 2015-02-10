<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 29/01/15
 * Time: 16:30
 */

namespace Keboola\Google\DriveWriterBundle\Writer;


use GuzzleHttp\Exception\BadResponseException;
use Keboola\Google\DriveWriterBundle\Entity\Account;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Google\DriveWriterBundle\GoogleDrive\RestApi;
use Keboola\StorageApi\Client;
use Monolog\Logger;
use Syrup\ComponentBundle\Exception\UserException;
use Syrup\ComponentBundle\Filesystem\Temp;

class Writer
{
	/** @var RestApi */
	protected $googleDriveApi;

	/** @var Temp */
	protected $temp;

	/** @var Logger */
	protected $logger;

	/** @var Configuration */
	protected $configuration;

	/** @var Client */
	protected $storageApi;

	/** @var Account */
	protected $currAccount;


	public function __construct(RestApi $googleDriveApi, Temp $temp, Logger $logger)
	{
		$this->googleDriveApi = $googleDriveApi;
		$this->temp = $temp;
	}

	public function setConfiguration($configuration)
	{
		$this->configuration = $configuration;
		$this->storageApi = $this->configuration->getStorageApi();
	}

	public function uploadFiles($options)
	{
		$accounts = $this->configuration->getAccounts();

		if (isset($options['account'])) {
			if (!isset($accounts[$options['account']])) {
				throw new UserException("Account '" . $options['account'] . "' does not exist.");
			}
			$accounts = array(
				$options['account'] => $accounts[$options['account']]
			);
		}

		$status = [];

		/** @var Account $account */
		foreach ($accounts as $accountId => $account) {

			$files = $account->getFiles();

			/** @var File $file */
			foreach ($files as $file) {

				$file->setPathname($this->temp->createTmpFile()->getPathname());
				$this->storageApi->exportTable($file->getTableId(), $file->getPathname());
				$this->initApi($account);

				try {

					if ($file->getType() == File::TYPE_FILE) {
						$this->processFile($file);
					} else {
						$this->processSheet($file);
					}

				} catch (BadResponseException $e) {

					$statusCode = $e->getResponse()->getStatusCode();
					if ($statusCode == 404) {

						// file not found - create new one and issue a warning
						$response = $this->googleDriveApi->insertFile($file);
						$file->setGoogleId($response['id']);
					}

				}

			}

			// updated changes to files
			$account->save();
		}
	}

	protected function processFile(File $file)
	{

		if (null == $file->getGoogleId() || $file->isIncremental()) {

			// create new file
			$response = $this->googleDriveApi->insertFile($file);

			$file->setGoogleId($response['id']);

		} else {

			// overwrite existing file
			$response = $this->googleDriveApi->updateFile($file);
		}
	}

	protected function processSheet(File $file)
	{
		if (null == $file->getGoogleId() || $file->isIncremental()) {

			// create new file
			$fileRes = $this->googleDriveApi->insertFile($file);

			// get list of worksheets in file, there shall be only one
			$sheets = $this->googleDriveApi->getWorksheets($fileRes['id']);
			$sheet = array_shift($sheets);

			// update file
			$file->setGoogleId($fileRes['id']);
			$file->setSheetId($sheet['id']);

		} else {

			// update content of existing file
			$response = $this->googleDriveApi->updateSheet($file);
		}

		return $file;
	}

	public function listFiles(Account $account, $params = [])
	{
		$this->initApi($account);

		return $this->googleDriveApi->listFiles($params);
	}

	protected function initApi(Account $account)
	{
		$this->currAccount = $account;
		$this->googleDriveApi->getApi()->setCredentials($account->getAccessToken(), $account->getRefreshToken());
		$this->googleDriveApi->getApi()->setRefreshTokenCallback(array($this, 'refreshTokenCallback'));
	}

	public function refreshTokenCallback($accessToken, $refreshToken)
	{
		$account = $this->currAccount;
		$account->setAccessToken($accessToken);
		$account->setRefreshToken($refreshToken);
		$account->save();
	}

}
